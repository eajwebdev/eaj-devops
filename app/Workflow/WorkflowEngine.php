<?php

namespace App\Workflow;

use App\Enums\BlockedReason;
use App\Enums\StepStatus;
use App\Enums\WorkflowStatus;
use App\Jobs\RunWorkflowStep;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Workflow;
use App\Models\WorkflowCheckpoint;
use App\Models\WorkflowStep;
use App\Reliability\EventRecorder;
use App\Reliability\Exceptions\ConcurrencyConflictException;
use App\Reliability\Exceptions\InvalidTransitionException;
use App\Reliability\SystemHalt;
use App\Workflow\Contracts\StepHandler;
use App\Workflow\Contracts\WorkflowListener;
use App\Workflow\Exceptions\NonRetryableStepException;
use App\Workflow\Exceptions\OperationsHaltedException;
use App\Workflow\Exceptions\StepLimitReachedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Durable workflow engine (Plan §48.2–48.5).
 *
 * - The database is the source of truth; the queue only delivers "run step N".
 * - Every mutation happens under SELECT ... FOR UPDATE plus an optimistic
 *   `version` check, so two workers can never advance the same workflow.
 * - Completed steps are never re-run (G4); every completed step leaves a
 *   checkpoint (G3); attempts are hard-capped outside the AI (G5); every
 *   failure becomes a persisted event and a visible state (G7).
 */
class WorkflowEngine
{
    public const MAX_STEPS = 200;

    public function __construct(
        private readonly EventRecorder $events,
        private readonly SystemHalt $halt,
    ) {}

    /**
     * @param  list<array{name: string, input?: array<string, mixed>}>  $steps
     * @param  array<string, int>  $limits  overrides for config('eaj.limits')
     */
    public function start(
        Model $subject,
        string $type,
        array $steps,
        Organization $organization,
        ?Project $project = null,
        array $limits = [],
    ): Workflow {
        if ($steps === []) {
            throw new InvalidArgumentException('A workflow needs at least one step.');
        }

        if ($this->halt->isHalted($organization->getKey())) {
            throw new OperationsHaltedException;
        }

        return DB::transaction(function () use ($subject, $type, $steps, $organization, $project, $limits) {
            $workflow = Workflow::forceCreate([
                'organization_id' => $organization->getKey(),
                'project_id' => $project?->getKey(),
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'type' => $type,
                'status' => WorkflowStatus::Pending,
                'limits' => array_merge(config('eaj.limits'), $limits),
                // Set explicitly: the version check needs real values, not unloaded DB defaults.
                'current_step' => 0,
                'version' => 0,
            ]);

            foreach (array_values($steps) as $position => $step) {
                WorkflowStep::forceCreate([
                    'workflow_id' => $workflow->id,
                    'position' => $position,
                    'name' => $step['name'],
                    'status' => StepStatus::Pending,
                    'input' => $step['input'] ?? null,
                ]);
            }

            $this->transition($workflow, WorkflowStatus::Running, ['started_at' => now()]);
            $this->dispatch($workflow->steps()->first());

            return $workflow;
        });
    }

    /**
     * Entry point for the RunWorkflowStep job. Safe to call any number of
     * times for the same step: only one call can claim a PENDING step.
     */
    public function runStep(int $stepId): void
    {
        $step = DB::transaction(function () use ($stepId) {
            $step = WorkflowStep::whereKey($stepId)->lockForUpdate()->first();
            $workflow = $step ? Workflow::whereKey($step->workflow_id)->lockForUpdate()->first() : null;

            if (! $step || ! $workflow
                || $workflow->status !== WorkflowStatus::Running
                || $workflow->current_step !== $step->position) {
                return null;
            }

            // Already done: never re-run (§48.3). Advance in case the crash
            // happened between completing the step and dispatching the next.
            if ($step->status === StepStatus::Completed) {
                $this->advanceFrom($workflow, $step);

                return null;
            }

            // RUNNING means another worker owns it; FAILED/CANCELLED are final.
            if ($step->status !== StepStatus::Pending || $this->halt->isHalted($workflow->organization_id)) {
                return null;
            }

            if ($step->attempt >= $workflow->limit('max_attempts')) {
                $this->block($workflow, BlockedReason::LimitReached, "Step \"{$step->name}\" used all {$step->attempt} attempts.");

                return null;
            }

            $step->forceFill([
                'status' => StepStatus::Running,
                'attempt' => $step->attempt + 1,
                'started_at' => now(),
                'heartbeat_at' => now(),
                'finished_at' => null,
                'version' => $step->version + 1,
            ])->save();

            $this->record($workflow, 'workflow.step.started', $step, ['attempt' => $step->attempt]);

            return $step;
        });

        if (! $step) {
            return;
        }

        try {
            $result = $this->resolveHandler($step->name)->handle($step, new StepContext($this, $step));
        } catch (StepLimitReachedException $e) {
            $this->failStep($step->id, $e->getMessage(), retryable: false, reason: BlockedReason::LimitReached);

            return;
        } catch (NonRetryableStepException $e) {
            $this->failStep($step->id, $e->getMessage(), retryable: false);

            return;
        } catch (Throwable $e) {
            report($e);
            $this->failStep($step->id, $e->getMessage());

            return;
        }

        $this->completeStep($step->id, $result);
    }

    public function completeStep(int $stepId, StepResult $result): void
    {
        DB::transaction(function () use ($stepId, $result) {
            [$step, $workflow] = $this->lockStep($stepId);

            // A late result for a step that was already recovered/retried is discarded.
            if ($step->status !== StepStatus::Running) {
                return;
            }

            $step->forceFill([
                'status' => StepStatus::Completed,
                'output' => $result->output,
                'last_error' => null,
                'finished_at' => now(),
                'version' => $step->version + 1,
            ])->save();

            $checkpoint = WorkflowCheckpoint::forceCreate([
                'workflow_id' => $workflow->id,
                'workflow_step_id' => $step->id,
                'commit_sha' => $result->commitSha,
                'state' => $result->state ?: null,
            ]);

            $this->record($workflow, 'workflow.step.completed', $step, [
                'checkpoint_id' => $checkpoint->id,
                'commit_sha' => $result->commitSha,
            ]);

            $this->appendSteps($workflow, $result->append);

            $this->advanceFrom($workflow, $step);
        });
    }

    /**
     * Retry with back-off while attempts remain, then BLOCK and ask the owner.
     * The order is always: retry → fall back → pause and ask (§2.6).
     */
    public function failStep(int $stepId, string $error, bool $retryable = true, bool $stalled = false, ?BlockedReason $reason = null): void
    {
        DB::transaction(function () use ($stepId, $error, $retryable, $stalled, $reason) {
            [$step, $workflow] = $this->lockStep($stepId);

            if ($step->status !== StepStatus::Running) {
                return;
            }

            $checkpointId = $workflow->checkpoints()->latest('id')->value('id');

            if ($retryable && $step->attempt < $workflow->limit('max_attempts')) {
                $backoff = config('eaj.backoff_seconds');
                $delay = (int) ($backoff[$step->attempt - 1] ?? end($backoff));

                $step->forceFill([
                    'status' => StepStatus::Pending,
                    'last_error' => $error,
                    'finished_at' => now(),
                    'resume_checkpoint_id' => $checkpointId,
                    'available_at' => now()->addSeconds($delay),
                    'version' => $step->version + 1,
                ])->save();

                $this->record($workflow, $stalled ? 'workflow.step.recovered' : 'workflow.step.retrying', $step, [
                    'attempt' => $step->attempt,
                    'max_attempts' => $workflow->limit('max_attempts'),
                    'error' => $error,
                    'retry_in_seconds' => $delay,
                    'resume_checkpoint_id' => $checkpointId,
                ]);

                if ($workflow->status === WorkflowStatus::Running) {
                    $this->dispatch($step, $delay);
                }

                return;
            }

            $step->forceFill([
                'status' => StepStatus::Failed,
                'last_error' => $error,
                'finished_at' => now(),
                'resume_checkpoint_id' => $checkpointId,
                'version' => $step->version + 1,
            ])->save();

            $this->record($workflow, 'workflow.step.failed', $step, ['attempt' => $step->attempt, 'error' => $error]);

            $this->block(
                $workflow,
                $reason ?? ($retryable ? BlockedReason::LimitReached : BlockedReason::NeedsDecision),
                $error,
            );
        });
    }

    public function heartbeat(int $stepId): void
    {
        WorkflowStep::whereKey($stepId)
            ->where('status', StepStatus::Running)
            ->update(['heartbeat_at' => now()]);
    }

    public function shouldStop(int $workflowId): bool
    {
        $workflow = Workflow::select(['id', 'organization_id', 'status'])->find($workflowId);

        return ! $workflow
            || $workflow->status !== WorkflowStatus::Running
            || $this->halt->isHalted($workflow->organization_id);
    }

    public function pause(Workflow $workflow, string $reason = 'Paused by owner'): void
    {
        DB::transaction(function () use ($workflow, $reason) {
            $locked = Workflow::whereKey($workflow->id)->lockForUpdate()->firstOrFail();

            $this->transition($locked, WorkflowStatus::Paused, [], $reason);
            $workflow->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Resume a PAUSED or BLOCKED workflow. Resuming a workflow blocked on its
     * attempt limit grants the current step a fresh set of attempts — that
     * is the owner's explicit decision, recorded as an event.
     */
    public function resume(Workflow $workflow, string $reason = 'Resumed by owner'): void
    {
        DB::transaction(function () use ($workflow, $reason) {
            $locked = Workflow::whereKey($workflow->id)->lockForUpdate()->firstOrFail();
            $step = $locked->steps()->where('position', $locked->current_step)->lockForUpdate()->first();

            if ($locked->status === WorkflowStatus::Blocked && $step && in_array($step->status, [StepStatus::Failed, StepStatus::Pending], true)) {
                $step->forceFill([
                    'status' => StepStatus::Pending,
                    'attempt' => 0,
                    'available_at' => null,
                    'version' => $step->version + 1,
                ])->save();
            }

            $this->transition($locked, WorkflowStatus::Running, ['blocked_reason' => null], $reason);
            $this->listener($locked)?->resumed($locked);
            $workflow->setRawAttributes($locked->getAttributes(), true);

            if ($step) {
                $this->dispatch($step);
            }
        });
    }

    public function cancel(Workflow $workflow, string $reason = 'Cancelled by owner'): void
    {
        DB::transaction(function () use ($workflow, $reason) {
            $locked = Workflow::whereKey($workflow->id)->lockForUpdate()->firstOrFail();

            $this->transition($locked, WorkflowStatus::Cancelled, ['finished_at' => now()], $reason);

            // Workspaces, logs and completed steps are preserved; only unfinished steps are cancelled.
            $locked->steps()
                ->whereIn('status', [StepStatus::Pending, StepStatus::Running])
                ->update(['status' => StepStatus::Cancelled, 'finished_at' => now()]);

            $this->listener($locked)?->cancelled($locked, $reason);

            $workflow->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Scheduler job (every minute): recover stalled steps, enforce wall-time
     * limits and re-deliver lost queue messages (§48.4, §48.5).
     *
     * @return array{recovered: int, timed_out: int, redelivered: int}
     */
    public function recover(): array
    {
        $recovered = 0;
        $failCutoff = now()->subSeconds((int) config('eaj.heartbeat.fail_seconds'));

        WorkflowStep::where('status', StepStatus::Running)
            ->where('heartbeat_at', '<', $failCutoff)
            ->pluck('id')
            ->each(function (int $id) use (&$recovered) {
                $seconds = config('eaj.heartbeat.fail_seconds');
                $this->failStep($id, "Stalled: no heartbeat for {$seconds}s. Resuming from the last checkpoint.", stalled: true);
                $recovered++;
            });

        $timedOut = 0;

        Workflow::where('status', WorkflowStatus::Running)
            ->whereNotNull('started_at')
            ->get()
            ->filter(fn (Workflow $w) => $w->started_at->addSeconds($w->limit('max_workflow_seconds'))->isPast())
            ->each(function (Workflow $workflow) use (&$timedOut) {
                DB::transaction(function () use ($workflow) {
                    $locked = Workflow::whereKey($workflow->id)->lockForUpdate()->first();

                    if ($locked?->status === WorkflowStatus::Running) {
                        $this->block($locked, BlockedReason::LimitReached, 'Workflow exceeded its maximum wall time.');
                    }
                });
                $timedOut++;
            });

        // Re-deliver "run step N" for pending current steps whose message may have been lost.
        // Safe because runStep() claims a step at most once.
        $redelivered = 0;
        $staleCutoff = now()->subMinutes(5);

        WorkflowStep::query()
            ->join('workflows', 'workflows.id', '=', 'workflow_steps.workflow_id')
            ->where('workflows.status', WorkflowStatus::Running)
            ->whereColumn('workflows.current_step', 'workflow_steps.position')
            ->where('workflow_steps.status', StepStatus::Pending)
            ->where('workflow_steps.updated_at', '<', $staleCutoff)
            ->where(fn ($q) => $q->whereNull('workflow_steps.available_at')->orWhere('workflow_steps.available_at', '<', $staleCutoff))
            ->select('workflow_steps.*')
            ->get()
            ->each(function (WorkflowStep $step) use (&$redelivered) {
                $step->touch();
                $this->dispatch($step);
                $redelivered++;
            });

        return ['recovered' => $recovered, 'timed_out' => $timedOut, 'redelivered' => $redelivered];
    }

    /**
     * Version-checked state transition. Call inside a transaction holding the row lock.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transition(Workflow $workflow, WorkflowStatus $to, array $attributes = [], ?string $reason = null): void
    {
        $from = $workflow->status;

        if (! $from->canTransitionTo($to)) {
            throw new InvalidTransitionException("Workflow {$workflow->id} cannot move from {$from->value} to {$to->value}.");
        }

        $this->update($workflow, [...$attributes, 'status' => $to]);

        $this->record($workflow, 'workflow.status.changed', null, array_filter([
            'from' => $from->value,
            'to' => $to->value,
            'reason' => $reason,
            'blocked_reason' => $workflow->blocked_reason?->value,
        ]));
    }

    private function block(Workflow $workflow, BlockedReason $reason, string $detail): void
    {
        $this->transition($workflow, WorkflowStatus::Blocked, ['blocked_reason' => $reason], $detail);

        $this->record($workflow, 'human.approval.required', null, [
            'reason' => $reason->value,
            'detail' => $detail,
            'next_action' => 'Needs your decision: resume to retry, or cancel.',
        ]);

        $this->listener($workflow)?->blocked($workflow, $detail);
    }

    private function listener(Workflow $workflow): ?WorkflowListener
    {
        // Indexed directly: workflow types contain dots, which config() would read as nesting.
        $class = config('eaj.workflow_listeners', [])[$workflow->type] ?? null;

        return $class ? app($class) : null;
    }

    /**
     * Grow a running workflow (fix and review cycles, §48.10). Only from a step's
     * result, inside the transaction that completes it, so a crash cannot add a
     * cycle twice; the hard cap keeps a loop from running forever (G5).
     *
     * @param  list<array{name: string, input?: array<string, mixed>}>  $steps
     */
    private function appendSteps(Workflow $workflow, array $steps): void
    {
        if ($steps === []) {
            return;
        }

        $last = (int) $workflow->steps()->max('position');

        if ($last + count($steps) >= self::MAX_STEPS) {
            throw new InvalidArgumentException("Workflow {$workflow->id} would exceed ".self::MAX_STEPS.' steps.');
        }

        foreach (array_values($steps) as $i => $step) {
            WorkflowStep::forceCreate([
                'workflow_id' => $workflow->id,
                'position' => $last + 1 + $i,
                'name' => $step['name'],
                'status' => StepStatus::Pending,
                'input' => $step['input'] ?? null,
            ]);
        }

        $this->record($workflow, 'workflow.steps.appended', null, ['steps' => array_column($steps, 'name')]);
    }

    private function advanceFrom(Workflow $workflow, WorkflowStep $step): void
    {
        $next = $workflow->steps()->where('position', $step->position + 1)->first();

        if ($next) {
            $this->update($workflow, ['current_step' => $next->position]);

            // A paused workflow keeps its progress; resume() dispatches the step.
            if ($workflow->status === WorkflowStatus::Running) {
                $this->dispatch($next);
            }

            return;
        }

        if ($workflow->status === WorkflowStatus::Running) {
            $this->transition($workflow, WorkflowStatus::Completed, ['finished_at' => now()]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function update(Workflow $workflow, array $attributes): void
    {
        $expected = $workflow->version;
        $workflow->forceFill([...$attributes, 'version' => $expected + 1]);

        $updated = Workflow::whereKey($workflow->id)
            ->where('version', $expected)
            ->update($workflow->getDirty());

        if ($updated === 0) {
            $workflow->forceFill(['version' => $expected]);

            throw new ConcurrencyConflictException("Workflow {$workflow->id} was modified concurrently.");
        }

        $workflow->syncOriginal();
    }

    /**
     * @return array{0: WorkflowStep, 1: Workflow}
     */
    private function lockStep(int $stepId): array
    {
        $step = WorkflowStep::whereKey($stepId)->lockForUpdate()->firstOrFail();
        $workflow = Workflow::whereKey($step->workflow_id)->lockForUpdate()->firstOrFail();

        return [$step, $workflow];
    }

    private function dispatch(WorkflowStep $step, int $delaySeconds = 0): void
    {
        RunWorkflowStep::dispatch($step->id)->delay($delaySeconds)->afterCommit();
    }

    private function resolveHandler(string $name): StepHandler
    {
        // Indexed directly: step names contain dots, which config() would read as nesting.
        $class = config('eaj.step_handlers', [])[$name] ?? null;

        if (! $class) {
            throw new NonRetryableStepException("No handler is registered for step \"{$name}\".");
        }

        $handler = app($class);

        if (! $handler instanceof StepHandler) {
            throw new NonRetryableStepException("Handler for step \"{$name}\" must implement StepHandler.");
        }

        return $handler;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(Workflow $workflow, string $type, ?WorkflowStep $step, array $payload = []): void
    {
        $this->events->record($type, [
            'workflow_id' => $workflow->id,
            'workflow_type' => $workflow->type,
            'subject_type' => $workflow->subject_type,
            'subject_id' => $workflow->subject_id,
            'status' => $workflow->status->value,
            ...($step ? ['step' => ['id' => $step->id, 'name' => $step->name, 'position' => $step->position, 'status' => $step->status->value]] : []),
            ...$payload,
        ], $workflow->project, $workflow->organization);
    }
}
