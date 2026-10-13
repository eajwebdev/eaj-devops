<?php

namespace App\Agents;

use App\AI\SkillLoader;
use App\Enums\AgentRunStatus;
use App\Enums\AgentStatus;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentEvent;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\TokenUsage;
use App\Reliability\EventRecorder;
use App\Reliability\SystemHalt;
use App\Security\SecretRedactor;
use App\Tasks\TaskService;
use App\Tasks\TaskStateMachine;
use App\Workflow\Exceptions\OperationsHaltedException;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Durable lifecycle for one specialist invocation. Provider adapters added in
 * Phase 6 must use this service rather than writing run tables directly.
 */
class AgentRunService
{
    public function __construct(
        private readonly ModelRouter $router,
        private readonly TokenBudgetService $budgets,
        private readonly EventRecorder $events,
        private readonly SecretRedactor $redactor,
        private readonly TaskService $tasks,
        private readonly SystemHalt $halt,
        private readonly SkillLoader $skills,
    ) {}

    /** Runs that hold their agent (one active run per agent). */
    public const ACTIVE = [AgentRunStatus::Running, AgentRunStatus::Retrying, AgentRunStatus::Paused];

    /** @param array<string, mixed> $input */
    public function start(Task $task, Agent $agent, string $purpose, string $idempotencyKey, array $input = [], ?int $workflowStepId = null, ?int $promptVersionId = null): AgentRun
    {
        if ($task->organization_id !== $agent->organization_id || $task->project->organization_id !== $agent->organization_id) {
            throw new DomainException('The agent and task must belong to the same organization.');
        }

        if (! $agent->enabled) {
            throw new DomainException('This agent is disabled.');
        }

        // §57: no new AI work while operations are stopped.
        if ($this->halt->isHalted($agent->organization_id)) {
            throw new OperationsHaltedException;
        }

        $idempotencyKey = Str::limit($idempotencyKey, 191, '');

        return DB::transaction(function () use ($task, $agent, $purpose, $idempotencyKey, $input, $workflowStepId, $promptVersionId) {
            $existing = AgentRun::where('organization_id', $agent->organization_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }

            $lockedAgent = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            if ($lockedAgent->runs()->whereIn('status', self::ACTIVE)->exists()) {
                throw new DomainException('This agent already has an active run.');
            }

            $decision = $this->router->resolve($agent, $purpose);

            // A run with no model to execute it would show work that cannot happen (G6).
            if (! $decision['primary']) {
                throw new DomainException("No AI model is configured for {$agent->name} ({$purpose}). Add one under AI Team → Models and routes.");
            }
            $budget = min(array_filter([
                (int) $agent->token_budget,
                $task->token_budget ? (int) $task->token_budget : null,
            ], fn ($value) => $value !== null && $value > 0)) ?: (int) $agent->token_budget;

            $run = AgentRun::forceCreate([
                'public_id' => (string) Str::uuid(),
                'organization_id' => $agent->organization_id,
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'agent_id' => $agent->id,
                'agent_role_id' => $agent->agent_role_id,
                'workflow_step_id' => $workflowStepId,
                'model_route_id' => $decision['route']?->id,
                'ai_model_id' => $decision['primary']?->id,
                'prompt_version_id' => $promptVersionId,
                'purpose' => $purpose,
                'status' => AgentRunStatus::Running,
                'attempt' => 1,
                'max_attempts' => $agent->retry_limit,
                'token_budget' => $budget,
                'idempotency_key' => $idempotencyKey,
                'input' => $this->redactor->redact($input),
                'started_at' => now(),
                'heartbeat_at' => now(),
            ]);

            $versionIds = $this->skills->for($agent, $task->project)->pluck('id');

            foreach ($versionIds as $versionId) {
                DB::table('agent_run_skills')->insert([
                    'agent_run_id' => $run->id,
                    'skill_version_id' => $versionId,
                    'created_at' => now(),
                ]);
            }

            $lockedAgent->forceFill(['status' => AgentStatus::Initializing, 'version' => $lockedAgent->version + 1])->save();
            $agent->setRawAttributes($lockedAgent->getAttributes(), true);
            $this->append($run, 'agent.run.started', "{$agent->name} started {$task->key()}.", [
                'purpose' => $purpose,
                'attempt' => 1,
                'max_attempts' => $run->max_attempts,
                'token_budget' => $run->token_budget,
                'model_tier' => $decision['route']?->tier->value,
                'model' => $decision['primary']?->display_name,
            ]);

            return $run->refresh();
        });
    }

    /**
     * Record provider-reported usage exactly once. Usage is retained even when
     * it crosses the cap, then the run and task are visibly blocked.
     */
    public function recordTokens(AgentRun $run, int $input, int $cachedInput, int $output, string $idempotencyKey): TokenUsage
    {
        foreach ([$input, $cachedInput, $output] as $value) {
            if ($value < 0) {
                throw new DomainException('Token counts cannot be negative.');
            }
        }

        $total = $input + $output;
        $blocked = false;
        $constraint = null;

        $usage = DB::transaction(function () use ($run, $input, $cachedInput, $output, $total, $idempotencyKey, &$blocked, &$constraint) {
            $locked = AgentRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $existing = TokenUsage::where('agent_run_id', $locked->id)->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                return $existing;
            }

            if ($locked->status->terminal()) {
                throw new DomainException('Token usage cannot be added to a finished run.');
            }

            $constraint = $this->budgets->headroom($locked, $total);
            $usage = TokenUsage::forceCreate([
                'organization_id' => $locked->organization_id,
                'project_id' => $locked->project_id,
                'task_id' => $locked->task_id,
                'agent_id' => $locked->agent_id,
                'agent_run_id' => $locked->id,
                'ai_model_id' => $locked->ai_model_id,
                'input_tokens' => $input,
                'cached_input_tokens' => min($cachedInput, $input),
                'output_tokens' => $output,
                'total_tokens' => $total,
                'idempotency_key' => Str::limit($idempotencyKey, 191, ''),
                'recorded_at' => now(),
            ]);

            $locked->forceFill([
                'input_tokens' => $locked->input_tokens + $input,
                'cached_input_tokens' => $locked->cached_input_tokens + min($cachedInput, $input),
                'output_tokens' => $locked->output_tokens + $output,
                'total_tokens' => $locked->total_tokens + $total,
                'version' => $locked->version + 1,
            ])->save();

            if (! $constraint['allowed']) {
                $blocked = true;
                $message = "Token budget reached ({$constraint['scope']}: {$constraint['limit']} tokens).";
                $locked->forceFill(['status' => AgentRunStatus::Blocked, 'last_error' => $message, 'finished_at' => now()])->save();
                $locked->agent()->update(['status' => AgentStatus::Blocked]);
                $this->append($locked, 'agent.run.budget_exhausted', $message, $constraint);
            } else {
                $this->append($locked, 'agent.run.tokens_recorded', "Recorded {$total} tokens.", [
                    'input_tokens' => $input,
                    'cached_input_tokens' => min($cachedInput, $input),
                    'output_tokens' => $output,
                    'remaining' => $constraint['remaining'] - $total,
                ]);
            }

            $run->setRawAttributes($locked->getAttributes(), true);

            return $usage;
        });

        if ($blocked) {
            $task = $run->task()->firstOrFail();
            if (TaskStateMachine::exists($task->status, TaskStatus::Blocked)) {
                $this->tasks->transition($task, TaskStatus::Blocked, 'system', null, "Token budget reached for {$run->agent->name}.");
            }
        }

        return $usage;
    }

    public function heartbeat(AgentRun $run): void
    {
        AgentRun::whereKey($run->id)
            ->whereIn('status', [AgentRunStatus::Running, AgentRunStatus::Retrying])
            ->update(['heartbeat_at' => now()]);
    }

    /** Move a queued retry back into execution without creating another run. */
    public function beginAttempt(AgentRun $run): AgentRun
    {
        return DB::transaction(function () use ($run) {
            $locked = AgentRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === AgentRunStatus::Retrying) {
                $locked->forceFill(['status' => AgentRunStatus::Running, 'heartbeat_at' => now(), 'version' => $locked->version + 1])->save();
                $locked->agent()->update(['status' => AgentStatus::Initializing]);
                $this->append($locked, 'agent.run.attempt_started', "Attempt {$locked->attempt} started.", ['attempt' => $locked->attempt]);
            }
            $run->setRawAttributes($locked->getAttributes(), true);

            return $run;
        });
    }

    public function useFallback(AgentRun $run, int $modelId, string $reason): AgentRun
    {
        return DB::transaction(function () use ($run, $modelId, $reason) {
            $locked = AgentRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->terminal() && $locked->ai_model_id !== $modelId) {
                $from = $locked->ai_model_id;
                $locked->forceFill(['ai_model_id' => $modelId, 'version' => $locked->version + 1])->save();
                $this->append($locked, 'ai.provider.fallback', 'Switched to the configured fallback model.', ['from_model_id' => $from, 'to_model_id' => $modelId, 'reason' => $reason]);
            }
            $run->setRawAttributes($locked->getAttributes(), true);

            return $run;
        });
    }

    /** Retry in-place so a single run preserves the full attempt and fallback history. */
    public function fail(AgentRun $run, string $error, bool $retryable = true): AgentRun
    {
        $blocked = false;
        $result = DB::transaction(function () use ($run, $error, $retryable, &$blocked) {
            $locked = AgentRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->terminal()) {
                return $locked;
            }

            $safeError = Str::limit($this->redactor->redactText($error), 4000, '');
            if ($retryable && $locked->attempt < $locked->max_attempts) {
                $fallbackId = $locked->modelRoute?->fallback_model_id;
                $locked->forceFill([
                    'status' => AgentRunStatus::Retrying,
                    'attempt' => $locked->attempt + 1,
                    'ai_model_id' => $fallbackId ?: $locked->ai_model_id,
                    'last_error' => $safeError,
                    'heartbeat_at' => now(),
                    'version' => $locked->version + 1,
                ])->save();
                // Waiting for its next attempt (§32 QUEUED), possibly on the fallback model.
                $locked->agent()->update(['status' => AgentStatus::Queued]);
                $this->append($locked, 'agent.run.retrying', "Retrying after failure: {$safeError}", [
                    'attempt' => $locked->attempt,
                    'max_attempts' => $locked->max_attempts,
                    'fallback_selected' => $fallbackId !== null,
                ]);
            } else {
                $status = $retryable ? AgentRunStatus::Blocked : AgentRunStatus::Failed;
                $blocked = $retryable;
                $locked->forceFill([
                    'status' => $status,
                    'last_error' => $safeError,
                    'finished_at' => now(),
                    'version' => $locked->version + 1,
                ])->save();
                $locked->agent()->update(['status' => $status === AgentRunStatus::Blocked ? AgentStatus::Blocked : AgentStatus::Failed]);
                $this->append($locked, $retryable ? 'agent.run.retry_limit_reached' : 'agent.run.failed', $safeError, [
                    'attempt' => $locked->attempt,
                    'max_attempts' => $locked->max_attempts,
                ]);
            }

            $run->setRawAttributes($locked->getAttributes(), true);

            return $run;
        });

        if ($blocked) {
            $task = $run->task()->firstOrFail();
            if (TaskStateMachine::exists($task->status, TaskStatus::Blocked)) {
                $this->tasks->transition($task, TaskStatus::Blocked, 'system', null, "Retry limit reached for {$run->agent->name}.");
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $output */
    public function complete(AgentRun $run, array $output = []): AgentRun
    {
        return DB::transaction(function () use ($run, $output) {
            $locked = AgentRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->terminal()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => AgentRunStatus::Completed,
                'output' => $this->redactor->redact($output),
                'last_error' => null,
                'finished_at' => now(),
                'version' => $locked->version + 1,
            ])->save();
            $locked->agent()->update(['status' => AgentStatus::Completed]);
            $this->append($locked, 'agent.run.completed', "{$locked->agent->name} completed the run.", [
                'tokens' => $locked->total_tokens,
                'attempts' => $locked->attempt,
            ]);
            $run->setRawAttributes($locked->getAttributes(), true);

            return $run;
        });
    }

    /**
     * What the agent is doing right now (§26 live activity, §32 states):
     * "Reading BookingService.php", "Running Pest", "Fixing 2 failed tests".
     * Executors call this; each call is a persisted event that drives the
     * War Room animation and doubles as a heartbeat.
     *
     * @param  array<string, mixed>  $metadata  e.g. ['file' => 'app/Services/BookingService.php'] or ['passed' => 118, 'total' => 120]
     */
    public function report(AgentRun $run, AgentStatus $activity, string $message, array $metadata = []): void
    {
        if (! in_array($activity, AgentStatus::activities(), true)) {
            throw new DomainException("{$activity->value} is a lifecycle state, not an activity.");
        }

        DB::transaction(function () use ($run, $activity, $message, $metadata) {
            $locked = AgentRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AgentRunStatus::Running) {
                throw new DomainException("Run {$locked->public_id} is {$locked->status->value}; it cannot report activity.");
            }

            $safe = Str::limit($this->redactor->redactText($message), 500, '');
            $locked->forceFill(['activity' => $safe, 'heartbeat_at' => now()])->save();
            $locked->agent()->update(['status' => $activity]);
            $this->append($locked, 'agent.status.changed', $safe, ['status' => $activity->value, ...$metadata]);

            $run->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  'owner'|'emergency_stop'  $reason
     */
    public function pause(AgentRun $run, string $reason = 'owner'): void
    {
        DB::transaction(function () use ($run, $reason) {
            $locked = AgentRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [AgentRunStatus::Running, AgentRunStatus::Retrying], true)) {
                return;
            }

            $locked->forceFill(['status' => AgentRunStatus::Paused, 'pause_reason' => $reason, 'version' => $locked->version + 1])->save();
            $locked->agent()->update(['status' => AgentStatus::Paused]);
            $this->append($locked, 'agent.run.paused', $reason === 'emergency_stop' ? 'Paused by the emergency stop.' : 'Paused by the owner.', ['reason' => $reason]);

            $run->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * The executor picks the run up again from its step's last checkpoint (§48.4).
     */
    public function resume(AgentRun $run): void
    {
        if ($this->halt->isHalted($run->organization_id)) {
            throw new OperationsHaltedException;
        }

        DB::transaction(function () use ($run) {
            $locked = AgentRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AgentRunStatus::Paused) {
                return;
            }

            $locked->forceFill(['status' => AgentRunStatus::Running, 'pause_reason' => null, 'heartbeat_at' => now(), 'version' => $locked->version + 1])->save();
            $locked->agent()->update(['status' => AgentStatus::Initializing]);
            $this->append($locked, 'agent.run.resumed', 'Resumed from the last checkpoint.');

            $run->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function cancel(AgentRun $run, string $reason): void
    {
        DB::transaction(function () use ($run, $reason) {
            $locked = AgentRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->terminal()) {
                return;
            }

            $safe = Str::limit($this->redactor->redactText($reason), 500, '');
            $locked->forceFill(['status' => AgentRunStatus::Cancelled, 'last_error' => $safe, 'finished_at' => now(), 'version' => $locked->version + 1])->save();
            $locked->agent()->update(['status' => AgentStatus::Cancelled]);
            $this->append($locked, 'agent.run.cancelled', $safe);

            $run->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Scheduler (every minute, §48.5): a run with no heartbeat for the fail
     * window counts as a failed attempt — retried (on the fallback model if
     * there is one) or blocked when attempts run out. Never silent (G7).
     */
    public function recoverStalled(): int
    {
        $seconds = (int) config('eaj.heartbeat.fail_seconds');

        return AgentRun::query()
            ->whereIn('status', [AgentRunStatus::Running, AgentRunStatus::Retrying])
            ->where('heartbeat_at', '<', now()->subSeconds($seconds))
            ->get()
            ->each(fn (AgentRun $run) => $this->fail($run, "Stalled: no activity for {$seconds}s; recovered from the last durable boundary."))
            ->count();
    }

    /** @param array<string, mixed> $metadata */
    private function append(AgentRun $run, string $type, string $message, array $metadata = []): AgentEvent
    {
        $sequence = $run->event_sequence + 1;
        $run->forceFill(['event_sequence' => $sequence])->save();
        $safeMessage = Str::limit($this->redactor->redactText($message), 500, '');
        $safeMetadata = $this->redactor->redact($metadata);

        $event = AgentEvent::forceCreate([
            'organization_id' => $run->organization_id,
            'project_id' => $run->project_id,
            'task_id' => $run->task_id,
            'agent_run_id' => $run->id,
            'sequence' => $sequence,
            'type' => $type,
            'message' => $safeMessage,
            'metadata' => $safeMetadata ?: null,
            'occurred_at' => now(),
        ]);

        $this->events->record($type, [
            'run_id' => $run->public_id,
            'agent' => ['id' => $run->agent_id, 'name' => $run->agent->name, 'role' => $run->role->key],
            'task' => ['id' => $run->task_id, 'key' => $run->task->key(), 'number' => $run->task->number, 'title' => $run->task->title],
            'message' => $safeMessage,
            'metadata' => $safeMetadata,
        ], $run->project);

        return $event;
    }
}
