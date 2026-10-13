<?php

namespace App\Delivery;

use App\Enums\RunnerCommandStatus;
use App\Enums\RunnerCommandType;
use App\Enums\WorkspaceStatus;
use App\Models\RepositoryWorkspace;
use App\Models\RunnerCommand;
use App\Runner\RunnerGateway;
use App\Tools\RunnerWaiter;
use App\Workflow\Exceptions\NonRetryableStepException;
use App\Workflow\StepContext;
use RuntimeException;

/**
 * Workspace file access for workflow steps: one signed runner command,
 * idempotent per key (G4), waited for with heartbeats. A refusal by the
 * runner's safety rules is final; anything else is retried by the engine.
 */
class WorkspaceCalls
{
    /** Runner refusals a retry cannot change. */
    private const FINAL = ['INVALID_PATH', 'PATH_NOT_ALLOWED', 'SECRET_FILE', 'SECRET_CONTENT', 'REDACTION_MARKER', 'INVALID_INPUT', 'NOT_A_WORKSPACE'];

    public function __construct(
        private readonly RunnerGateway $gateway,
        private readonly RunnerWaiter $waiter,
    ) {}

    /**
     * @param  list<string>  $paths
     * @return array{head_sha: string, files: list<array<string, mixed>>}
     */
    public function read(RepositoryWorkspace $workspace, array $paths, StepContext $context, string $key): array
    {
        return $this->call($workspace, RunnerCommandType::WorkspaceRead, ['paths' => array_values($paths)], $context, $key);
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return array{applied: int, files: list<array{path: string, additions: int|null, deletions: int|null}>, diff: string, diff_truncated: bool}
     */
    public function apply(RepositoryWorkspace $workspace, array $changes, StepContext $context, string $key): array
    {
        return $this->call($workspace, RunnerCommandType::WorkspaceApply, ['changes' => $changes], $context, $key);
    }

    /**
     * @return array{head_sha: string, base_sha: string, files: list<array{path: string, additions: int|null, deletions: int|null}>, diff: string, diff_truncated: bool}
     */
    public function diff(RepositoryWorkspace $workspace, StepContext $context, string $key): array
    {
        return $this->call($workspace, RunnerCommandType::WorkspaceDiff, ['base_sha' => $workspace->base_sha], $context, $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(RepositoryWorkspace $workspace, RunnerCommandType $type, array $payload, StepContext $context, string $key): array
    {
        if ($workspace->status !== WorkspaceStatus::Ready || ! $workspace->runner || ! $workspace->path) {
            throw new NonRetryableStepException("Workspace {$workspace->task_key} is not ready.");
        }

        $command = $this->gateway->dispatch($workspace->runner, $type, [
            'workspace_id' => $workspace->id,
            'workspace_path' => $workspace->path,
            ...$payload,
        ], $workspace->project, idempotencyKey: $key);

        $command = $this->waiter->command($command, $context, 15 * 60);

        if ($command->status === RunnerCommandStatus::Succeeded) {
            return (array) $command->result;
        }

        $message = "{$type->label()} failed: ".($command->error_message ?? $command->status->value);

        throw $this->isFinal($command) ? new NonRetryableStepException($message) : new RuntimeException($message);
    }

    public function isFinal(RunnerCommand $command): bool
    {
        return in_array($command->error_code, self::FINAL, true);
    }
}
