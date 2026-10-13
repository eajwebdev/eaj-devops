<?php

namespace App\Tools;

use App\Enums\RunnerCommandType;
use App\Enums\ToolKind;
use App\Enums\ToolRunStatus;
use App\Enums\WorkspaceStatus;
use App\Models\ProjectTool;
use App\Models\RepositoryWorkspace;
use App\Models\ToolRun;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Reliability\EventRecorder;
use App\Runner\Exceptions\RunnerUnavailableException;
use App\Runner\RunnerGateway;
use App\Tools\Exceptions\ToolUnavailableException;
use Illuminate\Support\Facades\DB;

/**
 * Asks a task workspace's runner to run one approved tool (Plan §84) and keeps
 * the persisted ToolRun the only source of what happened. The Studio sends a
 * tool key; the runner builds the command from the workspace's own files.
 */
class ToolRunService
{
    public function __construct(
        private readonly RunnerGateway $gateway,
        private readonly EventRecorder $events,
    ) {}

    /**
     * Idempotent per key (G4): the same key returns the same run.
     *
     * @param  array{scope?: 'changed'|'all', filter?: string|null, force?: bool}  $options
     */
    public function request(
        RepositoryWorkspace $workspace,
        string $toolKey,
        string $mode = 'check',
        ?User $actor = null,
        ?WorkflowStep $step = null,
        ?string $idempotencyKey = null,
        array $options = [],
    ): ToolRun {
        $idempotencyKey ??= 'tool-run:'.$workspace->id.':'.$toolKey.':'.now()->format('Uu');

        if ($existing = ToolRun::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $workspace->loadMissing('project.connection', 'runner', 'task');
        $tool = $this->tool($workspace, $toolKey);

        if (! in_array($mode, ['check', 'fix'], true) || ($mode === 'fix' && ! $tool->supports_fix)) {
            throw new ToolUnavailableException("{$tool->label} has no {$mode} mode.");
        }

        return DB::transaction(function () use ($workspace, $tool, $mode, $actor, $step, $idempotencyKey, $options) {
            $project = $workspace->project;

            $run = ToolRun::forceCreate([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'task_id' => $workspace->task_id,
                'repository_workspace_id' => $workspace->id,
                'workflow_id' => $step?->workflow_id,
                'workflow_step_id' => $step?->id,
                'idempotency_key' => $idempotencyKey,
                'tool_key' => $tool->key,
                'kind' => $tool->kind,
                'label' => $tool->label,
                'command' => $tool->command,
                'mode' => $mode,
                'scope' => $options['scope'] ?? 'changed',
                'status' => ToolRunStatus::Queued,
                'requested_by' => $actor?->id,
            ]);

            $command = $this->gateway->dispatch($workspace->runner, RunnerCommandType::ToolRun, [
                'tool_run_id' => $run->id,
                'tool' => $tool->key,
                'workspace_path' => $workspace->path,
                'project_path' => $project->connection?->local_path,
                'task_key' => $workspace->task_key,
                'mode' => $mode,
                'scope' => $run->scope,
                'base_sha' => $workspace->base_sha,
                'filter' => $options['filter'] ?? null,
                'force' => (bool) ($options['force'] ?? false),
                // §48.8: failing tests are re-run once on their own to tell flaky from broken.
                'rerun_failed' => $tool->kind === ToolKind::Test || $tool->kind === ToolKind::E2e,
            ], $project, $actor, "tool-run:{$run->id}");

            $run->forceFill(['runner_command_id' => $command->id])->save();

            $this->events->record('tool.run.queued', ['tool_run' => $run->toClientArray(detail: false), 'task_id' => $run->task_id, 'workflow_id' => $run->workflow_id], $project, actor: $actor);

            return $run;
        });
    }

    /** Stops a queued or running tool; the runner kills the process tree (§57). */
    public function cancel(ToolRun $run, ?User $by = null, string $reason = 'Cancelled by owner'): void
    {
        if ($run->status->isTerminal() || ! $run->runnerCommand) {
            return;
        }

        $this->gateway->cancel($run->runnerCommand, $by, $reason);
    }

    private function tool(RepositoryWorkspace $workspace, string $key): ProjectTool
    {
        if ($workspace->status !== WorkspaceStatus::Ready || ! $workspace->path || ! $workspace->runner || $workspace->runner->revoked_at) {
            throw new RunnerUnavailableException("Workspace {$workspace->task_key} is not ready.");
        }

        $tool = $workspace->project->tools()->where('key', $key)->first();

        return match (true) {
            ! $tool => throw new ToolUnavailableException("This project has no \"{$key}\" tool. Re-scan the project if it was added recently."),
            ! $tool->available => throw new ToolUnavailableException("{$tool->label} was not found in the last scan."),
            (bool) $tool->blocked => throw new ToolUnavailableException($tool->blocked),
            ! $tool->enabled => throw new ToolUnavailableException("{$tool->label} is switched off for this project."),
            default => $tool,
        };
    }
}
