<?php

namespace App\AI;

use App\Agents\AgentRunService;
use App\Agents\TokenBudgetService;
use App\AI\Data\ProviderRequest;
use App\AI\Data\ProviderResponse;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\RunInterruptedException;
use App\Costs\CostCalculator;
use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AiModel;
use App\Models\AiProviderCall;
use App\Reliability\AuditLogger;
use App\Reliability\SystemHalt;
use App\Security\SecretRedactor;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * One provider call with the §48.7 resilience rules: circuit breaker per provider,
 * one retry for timeouts/429/5xx (honouring retry-after), then the route's fallback
 * model. Every attempt is persisted in ai_provider_calls and is idempotent per key.
 */
class ProviderExecutor
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ProviderCircuitBreaker $circuits,
        private readonly AgentRunService $runs,
        private readonly AuditLogger $audit,
        private readonly SecretRedactor $redactor,
        private readonly SystemHalt $halt,
        private readonly TokenBudgetService $budgets,
        private readonly CostCalculator $costs,
    ) {}

    public function execute(AgentRun $run, ProviderRequest $request, bool $repair = false): ProviderResponse
    {
        $run->loadMissing(['model', 'modelRoute.fallbackModel', 'organization', 'project']);
        $models = collect([$run->model, $run->modelRoute?->fallbackModel])->filter()->unique('id')->values();
        $heartbeat = $this->heartbeat($run, $request);
        $last = null;

        foreach ($models as $modelIndex => $model) {
            if ($modelIndex > 0) {
                $this->assertMayCall($run);
                $this->runs->useFallback($run, $model->id, $last?->getMessage() ?? 'Primary provider unavailable.');
                $run->setRelation('model', $model);
                $this->audit->log('ai.provider.fallback', organization: $run->organization, project: $run->project, subject: $run, metadata: ['model_id' => $model->id, 'reason' => $last?->getMessage()]);
            }

            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $key = Str::limit("{$request->idempotencyKey}:{$model->id}:".($repair ? 'repair' : 'main').":{$attempt}", 191, '');
                $existing = AiProviderCall::where('idempotency_key', $key)->first();
                if ($existing?->status === 'completed') {
                    $response = $this->hydrate($existing);
                    $this->recordUsage($run, $existing, $response);

                    return $response;
                }

                $call = null;
                try {
                    // Re-checked before every call: Stop AI or a pause must never be followed by another billed request.
                    $this->assertMayCall($run);
                    $this->assertWithinBudget($run, $model, $request);
                    $heartbeat();
                    $this->circuits->guard($model->provider);
                    $provider = $this->providers->get($model->provider);
                    $call = $existing ?: AiProviderCall::forceCreate([
                        'organization_id' => $run->organization_id, 'project_id' => $run->project_id, 'task_id' => $run->task_id,
                        'agent_run_id' => $run->id, 'ai_model_id' => $model->id, 'prompt_version_id' => $run->prompt_version_id,
                        'provider' => $model->provider, 'status' => 'running', 'attempt' => $attempt,
                        'is_fallback' => $modelIndex > 0, 'is_repair' => $repair, 'idempotency_key' => $key,
                    ]);
                    $started = hrtime(true);
                    $response = $provider->generate($request->forModel($model->model_key, $key, $heartbeat));
                    $call->forceFill([
                        'status' => 'completed', 'response' => $this->redactor->redact($response->output), 'request_id' => $response->requestId,
                        'input_tokens' => $response->inputTokens, 'cached_input_tokens' => $response->cachedInputTokens,
                        'output_tokens' => $response->outputTokens, 'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                        'error_code' => null, 'error_message' => null, 'http_status' => null,
                    ])->save();
                    $this->circuits->success($model->provider);
                    $this->recordUsage($run, $call, $response);

                    return $response;
                } catch (RunInterruptedException $e) {
                    throw $e;
                } catch (ProviderException $e) {
                    $last = $e;
                    $call?->forceFill(['status' => 'failed', 'error_code' => $e->errorCode, 'error_message' => Str::limit($this->redactor->redactText($e->getMessage()), 4000, ''), 'http_status' => $e->httpStatus])->save();
                    if ($e->retryable && $e->errorCode !== 'circuit_open') {
                        $this->circuits->failure($model->provider);
                    }
                    // An open circuit or a non-retryable error goes straight to the fallback.
                    if (! $e->retryable || $e->errorCode === 'circuit_open' || $attempt === 2) {
                        break;
                    }
                    $this->wait(min($e->retryAfter, (int) config('ai.max_retry_after_seconds')), $heartbeat);
                } catch (Throwable $e) {
                    $last = new ProviderException($e->getMessage(), $model->provider, 'unexpected_error', true, previous: $e);
                    $this->circuits->failure($model->provider);
                    $call?->forceFill(['status' => 'failed', 'error_code' => 'unexpected_error', 'error_message' => Str::limit($this->redactor->redactText($e->getMessage()), 4000, '')])->save();
                    if ($attempt === 2) {
                        break;
                    }
                }
            }
        }

        throw $last ?? new ProviderException('No AI model is available for this route.', 'none', 'no_model');
    }

    private function assertMayCall(AgentRun $run): void
    {
        if ($this->halt->isHalted($run->organization_id)) {
            throw new RunInterruptedException('AI operations are stopped.');
        }

        $status = AgentRun::whereKey($run->id)->first(['id', 'status'])?->status;
        if ($status !== AgentRunStatus::Running) {
            throw new RunInterruptedException('The agent run is '.($status?->value ?? 'missing').', not running.');
        }
    }

    private function assertWithinBudget(AgentRun $run, AiModel $model, ProviderRequest $request): void
    {
        $input = (int) ceil((strlen($request->system) + strlen($request->input)) / 4);
        $cost = $this->costs->calculate($model, $input, 0, $request->maxOutputTokens)['total'] ?? null;
        $constraint = $this->budgets->blockingConstraint($run->refresh(), $input, $cost);
        if ($constraint === null) {
            return;
        }

        $message = $constraint['metric'] === 'cost'
            ? 'The next provider call may exceed the '.$constraint['scope'].' cost budget.'
            : 'The next provider call may exceed the '.$constraint['scope'].' token budget.';
        $this->runs->fail($run, $message, retryable: false);

        throw new RunInterruptedException($message);
    }

    /** Throttled liveness for the run (§48.5) plus whatever the caller needs, e.g. the workflow step. */
    private function heartbeat(AgentRun $run, ProviderRequest $request): Closure
    {
        $last = 0.0;
        $every = (int) config('eaj.heartbeat.interval_seconds', 15);

        return function () use ($run, $request, &$last, $every) {
            if (microtime(true) - $last < $every) {
                return;
            }
            $last = microtime(true);
            $this->runs->heartbeat($run);
            if ($request->heartbeat) {
                ($request->heartbeat)();
            }
        };
    }

    private function wait(int $seconds, Closure $heartbeat): void
    {
        for ($i = 0; $i < $seconds; $i++) {
            sleep(1);
            $heartbeat();
        }
    }

    private function recordUsage(AgentRun $run, AiProviderCall $call, ProviderResponse $response): void
    {
        $this->runs->recordTokens($run, $response->inputTokens, $response->cachedInputTokens, $response->outputTokens, 'provider-call:'.$call->id);
    }

    private function hydrate(AiProviderCall $call): ProviderResponse
    {
        return new ProviderResponse($call->response ?? [], $call->input_tokens, $call->cached_input_tokens, $call->output_tokens, $call->request_id);
    }
}
