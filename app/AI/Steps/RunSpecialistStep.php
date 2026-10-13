<?php

namespace App\AI\Steps;

use App\AI\Contracts\AgentRunnerInterface;
use App\AI\Exceptions\SpecialistRunFailedException;
use App\Enums\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\WorkflowStep;
use App\Workflow\Contracts\StepHandler;
use App\Workflow\Exceptions\NonRetryableStepException;
use App\Workflow\StepContext;
use App\Workflow\StepResult;
use RuntimeException;

/**
 * Workflow step "agent.specialist": one specialist of the orchestrated plan.
 * Retries are the agent run's (same key, next attempt); a run that cannot
 * continue blocks the workflow so the owner decides (§2.6).
 */
class RunSpecialistStep implements StepHandler
{
    public const NAME = 'agent.specialist';

    public function __construct(private readonly AgentRunnerInterface $runner) {}

    public function handle(WorkflowStep $step, StepContext $context): StepResult
    {
        $workflow = $step->workflow()->with('subject')->firstOrFail();
        $task = $workflow->subject;
        $role = (string) ($step->input['role'] ?? '');
        $agent = $task instanceof Task
            ? Agent::where('organization_id', $workflow->organization_id)->whereHas('role', fn ($q) => $q->where('key', $role))->first()
            : null;

        if (! $agent) {
            throw new NonRetryableStepException("No {$role} agent is available for this step.");
        }

        if ($context->shouldStop()) {
            throw new RuntimeException('AI operations are stopped; the step resumes from here.');
        }

        // Same key = resume the step's run. A run that already ended (failed, blocked, cancelled) and
        // is retried by the owner resuming the workflow gets a new run, keeping the old one as evidence.
        $runs = AgentRun::where('workflow_step_id', $step->id)->orderBy('id')->get(['id', 'status']);
        $ended = $runs->last() && in_array($runs->last()->status, [AgentRunStatus::Failed, AgentRunStatus::Blocked, AgentRunStatus::Cancelled], true);
        $key = "workflow-step:{$step->id}:run:".max(1, $runs->count() + ($ended ? 1 : 0));

        try {
            $result = $this->runner->run(
                $task,
                $agent,
                (string) ($step->input['purpose'] ?? 'planning'),
                $key,
                $step->id,
                $context->heartbeat(...),
            );
        } catch (SpecialistRunFailedException $e) {
            throw $e->retryable ? new RuntimeException($e->getMessage(), 0, $e) : new NonRetryableStepException($e->getMessage(), 0, $e);
        }

        return new StepResult(output: $result, state: ['agent_run_id' => $result['agent_run_id'] ?? null]);
    }
}
