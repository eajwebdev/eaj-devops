<?php

namespace App\Actions\Projects;

use App\Enums\ProjectConnectionStatus;
use App\Enums\ProjectConnectionType;
use App\Enums\RunnerCommandStatus;
use App\Enums\RunnerCommandType;
use App\Models\Project;
use App\Models\RunnerCommand;
use App\Models\User;
use App\Reliability\AuditLogger;
use App\Reliability\SystemHalt;
use App\Runner\Exceptions\RunnerUnavailableException;
use App\Runner\RunnerGateway;
use App\Workflow\Exceptions\OperationsHaltedException;
use Illuminate\Support\Facades\DB;

/**
 * Project-level runner work (Plan §12, §14): scan, rebuild the code index,
 * initialize Git. One pending command of each kind per project at a time.
 */
class RequestRunnerWork
{
    public function __construct(
        private readonly RunnerGateway $gateway,
        private readonly AuditLogger $audit,
        private readonly SystemHalt $halt,
    ) {}

    public function scan(Project $project, ?User $user): RunnerCommand
    {
        return $this->request($project, $user, RunnerCommandType::ProjectScan, ProjectConnectionStatus::Scanning);
    }

    public function rebuildIndex(Project $project, User $user): RunnerCommand
    {
        return $this->request($project, $user, RunnerCommandType::ProjectIndex, ProjectConnectionStatus::Indexing);
    }

    /**
     * Only after the owner approved it (Plan §14, §56): explained in the UI,
     * recorded in the audit log.
     */
    public function initializeGit(Project $project, User $user): RunnerCommand
    {
        if ($project->connection?->git_enabled) {
            throw new RunnerUnavailableException('This project already uses Git.');
        }

        $command = $this->request($project, $user, RunnerCommandType::GitInit, null);
        $this->audit->log('project.git_init.approved', $user, project: $project, subject: $project);

        return $command;
    }

    private function request(Project $project, ?User $user, RunnerCommandType $type, ?ProjectConnectionStatus $status): RunnerCommand
    {
        $connection = $project->connection;

        if (! $connection || $connection->type !== ProjectConnectionType::Local || ! $connection->local_path) {
            throw new RunnerUnavailableException('Only local projects are scanned by a runner.');
        }

        if (! $connection->runner || $connection->runner->revoked_at) {
            throw new RunnerUnavailableException('Assign an active EAJ Local Runner to this project first.');
        }

        if ($this->halt->isHalted($project->organization_id)) {
            throw new OperationsHaltedException;
        }

        return DB::transaction(function () use ($project, $user, $type, $status, $connection) {
            $pending = RunnerCommand::query()
                ->where('project_id', $project->id)
                ->where('type', $type)
                ->whereIn('status', [RunnerCommandStatus::Queued, RunnerCommandStatus::Claimed])
                ->lockForUpdate()
                ->first();

            if ($pending) {
                return $pending;
            }

            if ($status) {
                $connection->forceFill(['status' => $status, 'last_error' => null])->save();
            }

            return $this->gateway->dispatch($connection->runner, $type, ['project_path' => $connection->local_path], $project, $user);
        });
    }
}
