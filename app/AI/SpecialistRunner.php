<?php

namespace App\AI;

use App\Agents\AgentRunService;
use App\Agents\TokenBudgetService;
use App\AI\Contracts\AgentRunnerInterface;
use App\AI\Data\ProviderRequest;
use App\AI\Exceptions\InvalidStructuredOutputException;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\RunInterruptedException;
use App\AI\Exceptions\SpecialistRunFailedException;
use App\Costs\CostCalculator;
use App\Enums\AgentRunStatus;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\PromptVersion;
use App\Models\Task;
use Closure;
use Illuminate\Support\Str;

/**
 * Runs one specialist through the AI layer (§42 context → §81 prompt version →
 * §82 provider → §48.7 validation) inside a durable AgentRun. Re-entry with the
 * same key resumes the run; completed provider calls are replayed, not re-billed.
 */
class SpecialistRunner implements AgentRunnerInterface
{
    public function __construct(
        private readonly AgentRunService $runs,
        private readonly PromptCatalog $prompts,
        private readonly PromptBuilder $builder,
        private readonly ContextBuilder $context,
        private readonly StructuredOutputService $structured,
        private readonly TokenBudgetService $budgets,
        private readonly CostCalculator $costs,
    ) {}

    public function run(Task $task, Agent $agent, string $purpose, string $idempotencyKey, ?int $workflowStepId = null, ?Closure $heartbeat = null): array
    {
        $task->loadMissing('project.organization');
        $agent->loadMissing('role');

        return $this->runBrief($task, $agent, new AgentBrief(
            purpose: $purpose,
            promptKey: PromptCatalog::key($agent->role->key),
            // Empty: the planning context is built per attempt, with the skill versions the run recorded.
            context: [],
            activity: AgentStatus::Planning,
            message: "Planning {$task->key()} from the task, impact analysis and ".count($task->impact['expected_files'] ?? []).' indexed files',
            guard: fn (array $output) => $this->guard($output, $task, $agent),
        ), $idempotencyKey, $workflowStepId, $heartbeat);
    }

    /**
     * Run any brief (planning, implementation, review) as one durable AgentRun.
     * Same key = resume: a completed run returns its stored result.
     */
    public function runBrief(Task $task, Agent $agent, AgentBrief $brief, string $idempotencyKey, ?int $workflowStepId = null, ?Closure $heartbeat = null): array
    {
        $task->loadMissing('project.organization');
        $agent->loadMissing('role');
        $active = $this->prompts->active($task->project->organization, $brief->promptKey);

        $run = $this->runs->start($task, $agent, $brief->purpose, $idempotencyKey, ['task' => $task->key(), 'purpose' => $brief->purpose], $workflowStepId, $active->id);
        $run = $this->runs->beginAttempt($run);

        if ($run->status === AgentRunStatus::Completed) {
            return $this->result($run);
        }
        $this->assertRunning($run);

        // Re-entry keeps the prompt version the run started with, so the result is attributable (§81).
        $prompt = $run->prompt_version_id ? PromptVersion::find($run->prompt_version_id) ?? $active : $active;
        $skills = $run->skillVersions()->get();
        $context = $brief->context !== []
            ? [...$brief->context, 'skills' => $this->context->skills($skills)]
            : $this->context->build($task, $skills);
        $this->runs->report($run, $brief->activity, $brief->message);

        $messages = $this->builder->build($prompt, $agent, $context);
        $run->load('model');

        // §47: do not pay for a call whose input alone cannot fit the remaining budget (~4 characters per token).
        $estimate = (int) ceil((strlen($messages['system']) + strlen($messages['input'])) / 4);
        $maxOutput = $brief->maxOutputTokens ?? (int) config('ai.max_output_tokens');
        $estimatedCost = $this->costs->calculate($run->model, $estimate, 0, $maxOutput)['total'] ?? null;
        $constraint = $this->budgets->blockingConstraint($run, $estimate, $estimatedCost);
        if ($constraint !== null) {
            $message = $constraint['metric'] === 'cost'
                ? "This {$brief->purpose} may cost about $".number_format((float) $estimatedCost, 4).', but only $'.number_format((float) $constraint['remaining'], 4)." remains in the {$constraint['scope']} cost budget ($".number_format((float) $constraint['limit'], 2).'). Raise the budget under Costs, then resume.'
                : "This {$brief->purpose} needs about ".number_format($estimate)." input tokens, but only {$constraint['remaining']} remain in the {$constraint['scope']} budget ({$constraint['limit']}). Raise {$agent->name}'s budget under AI Team, or the task's budget, then resume.";
            $this->runs->fail($run, $message, retryable: false);

            throw new SpecialistRunFailedException($message, retryable: false);
        }

        $request = new ProviderRequest(
            $run->model->model_key,
            $messages['system'],
            $messages['input'],
            $prompt->output_schema,
            'agent_result',
            $maxOutput,
            "run:{$run->public_id}:attempt:{$run->attempt}",
            $heartbeat,
        );

        try {
            $output = $this->structured->generate($run, $request);

            // A schema-valid answer can still be unusable (an edit that does not match the file): one correction round.
            if ($brief->check && ($problems = ($brief->check)($output)) !== []) {
                $this->runs->report($run, AgentStatus::Fixing, 'Correcting its answer: '.Str::limit(implode(' ', $problems), 300));
                $output = $this->structured->generate($run, new ProviderRequest(
                    $request->model,
                    $request->system,
                    $request->input."\n\nYour previous answer could not be used. Fix exactly these problems and return the full corrected object:\n- ".implode("\n- ", $problems)."\nPrevious answer:\n".json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $request->schema,
                    $request->schemaName,
                    $request->maxOutputTokens,
                    "{$request->idempotencyKey}:correction",
                    $heartbeat,
                ));

                if (($problems = ($brief->check)($output)) !== []) {
                    throw new InvalidStructuredOutputException($problems);
                }
            }
        } catch (RunInterruptedException $e) {
            throw new SpecialistRunFailedException($e->getMessage(), retryable: ! $run->refresh()->status->terminal(), previous: $e);
        } catch (InvalidStructuredOutputException $e) {
            // §48.7: a second invalid output fails the step — invalid output is never applied.
            $this->runs->fail($run, $e->getMessage(), retryable: false);
            throw new SpecialistRunFailedException($e->getMessage(), retryable: false, previous: $e);
        } catch (ProviderException $e) {
            $run = $this->runs->fail($run, $e->getMessage(), $e->retryable);
            throw new SpecialistRunFailedException($e->getMessage(), $run->status === AgentRunStatus::Retrying, $e);
        }

        // The budget check on recorded usage may have blocked the run, or Stop AI paused it, while we waited.
        $this->assertRunning($run->refresh());
        $this->runs->complete($run, ['result' => $brief->guard ? ($brief->guard)($output) : $output, 'prompt_version' => $prompt->version]);

        return $this->result($run->refresh());
    }

    /**
     * §48.8 deterministic guards: Phase 6 gives agents no repository, write or test tools,
     * so any claim to have used them is removed rather than shown as fact (G6).
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function guard(array $output, Task $task, Agent $agent): array
    {
        $warnings = array_values(array_filter((array) ($output['warnings'] ?? []), 'is_string'));
        $claims = array_filter([
            'files read' => count((array) ($output['files_read'] ?? [])),
            'files modified' => count((array) ($output['files_modified'] ?? [])),
            'tests added' => count((array) ($output['tests_added'] ?? [])),
            'tests passed' => (int) ($output['tests_passed'] ?? 0),
            'tests failed' => (int) ($output['tests_failed'] ?? 0),
        ]);

        if ($claims !== []) {
            $warnings[] = 'Removed unverified claims ('.implode(', ', array_keys($claims)).'): no repository or test tools ran in this step.';
        }

        return [
            ...$output,
            'task_id' => $task->key(),
            'agent' => $agent->role->key,
            'files_read' => [],
            'files_modified' => [],
            'tests_added' => [],
            'tests_passed' => 0,
            'tests_failed' => 0,
            'ready_for_review' => false,
            'warnings' => $warnings,
        ];
    }

    private function assertRunning(AgentRun $run): void
    {
        if ($run->status !== AgentRunStatus::Running) {
            $retryable = $run->status === AgentRunStatus::Paused || $run->status === AgentRunStatus::Retrying;

            $why = $run->last_error ? ": {$run->last_error}" : '.';

            throw new SpecialistRunFailedException("The {$run->agent->name} run is ".strtolower($run->status->value).$why, $retryable);
        }
    }

    /** @return array<string, mixed> */
    private function result(AgentRun $run): array
    {
        return ['agent_run_id' => $run->public_id, 'role' => $run->role->key, ...(array) $run->output];
    }
}
