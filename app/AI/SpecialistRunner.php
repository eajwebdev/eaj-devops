<?php

namespace App\AI;

use App\Agents\AgentRunService;
use App\AI\Contracts\AgentRunnerInterface;
use App\AI\Data\ProviderRequest;
use App\AI\Exceptions\InvalidStructuredOutputException;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\RunInterruptedException;
use App\AI\Exceptions\SpecialistRunFailedException;
use App\Enums\AgentRunStatus;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\PromptVersion;
use App\Models\Task;
use Closure;

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
    ) {}

    public function run(Task $task, Agent $agent, string $purpose, string $idempotencyKey, ?int $workflowStepId = null, ?Closure $heartbeat = null): array
    {
        $task->loadMissing('project.organization');
        $agent->loadMissing('role');
        $active = $this->prompts->activeFor($task->project->organization, $agent->role->key);

        $run = $this->runs->start($task, $agent, $purpose, $idempotencyKey, ['task' => $task->key(), 'purpose' => $purpose], $workflowStepId, $active->id);
        $run = $this->runs->beginAttempt($run);

        if ($run->status === AgentRunStatus::Completed) {
            return $this->result($run);
        }
        $this->assertRunning($run);

        // Re-entry keeps the prompt version the run started with, so the result is attributable (§81).
        $prompt = $run->prompt_version_id ? PromptVersion::find($run->prompt_version_id) ?? $active : $active;
        $skills = $run->skillVersions()->get();
        $context = $this->context->build($task, $skills);
        $files = count($context['impact']['expected_files'] ?? []);
        $this->runs->report($run, AgentStatus::Planning, "Planning {$task->key()} from the task, impact analysis and {$files} indexed files");

        $messages = $this->builder->build($prompt, $agent, $context);
        $run->load('model');

        try {
            $output = $this->structured->generate($run, new ProviderRequest(
                $run->model->model_key,
                $messages['system'],
                $messages['input'],
                $prompt->output_schema,
                'agent_result',
                (int) config('ai.max_output_tokens'),
                "run:{$run->public_id}:attempt:{$run->attempt}",
                $heartbeat,
            ));
        } catch (RunInterruptedException $e) {
            throw new SpecialistRunFailedException($e->getMessage(), retryable: true, previous: $e);
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
        $this->runs->complete($run, ['result' => $this->guard($output, $task, $agent), 'prompt_version' => $prompt->version]);

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
