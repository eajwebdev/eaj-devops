<?php

namespace App\Runner;

use App\Enums\ProjectConnectionStatus;
use App\Enums\RunnerCommandType;
use App\Enums\WorkspaceStatus;
use App\Models\Project;
use App\Models\ProjectIndexFile;
use App\Models\ProjectIndexSymbol;
use App\Models\ProjectSnapshot;
use App\Models\RepositoryWorkspace;
use App\Models\RunnerCommand;
use App\Reliability\EventRecorder;
use App\Security\SecretRedactor;
use App\Tools\ToolCatalog;
use App\Tools\ToolRunResults;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Turns runner results into project state. Runs inside the transaction that
 * completes the command, so state and events change together.
 */
class RunnerResultProcessor
{
    public function __construct(
        private readonly EventRecorder $events,
        private readonly SecretRedactor $redactor,
        private readonly ToolRunResults $tools,
        private readonly ToolCatalog $catalog,
    ) {}

    public function claimed(RunnerCommand $command): void
    {
        if ($command->type === RunnerCommandType::ToolRun) {
            $this->tools->claimed($command);
        }
    }

    public function progressed(RunnerCommand $command, string $message): void
    {
        if ($command->type === RunnerCommandType::ToolRun) {
            $this->tools->progressed($command, $message);
        }
    }

    public function succeeded(RunnerCommand $command): void
    {
        match ($command->type) {
            RunnerCommandType::ProjectScan => $this->scanned($command),
            RunnerCommandType::ProjectIndex => $this->indexed($command),
            RunnerCommandType::GitInit => $this->gitInitialized($command),
            RunnerCommandType::WorkspaceCreate => $this->workspace($command, fn (RepositoryWorkspace $w, array $r) => $w->forceFill([
                'status' => WorkspaceStatus::Ready,
                'path' => $r['path'] ?? null,
                'branch' => $r['branch'] ?? $w->branch,
                'base_branch' => $r['base_branch'] ?? null,
                'base_sha' => $r['base_sha'] ?? null,
                'safety_stash' => $r['safety_stash'] ?? null,
                'error' => null,
            ]), 'workspace.created'),
            RunnerCommandType::WorkspaceCheckpoint => $this->workspace($command, fn (RepositoryWorkspace $w, array $r) => $w->forceFill([
                'last_checkpoint_sha' => $r['sha'] ?? $w->last_checkpoint_sha,
            ]), 'workspace.checkpointed'),
            RunnerCommandType::WorkspaceReset => $this->workspace($command, fn (RepositoryWorkspace $w) => $w, 'workspace.reset'),
            RunnerCommandType::WorkspaceRemove => $this->workspace($command, fn (RepositoryWorkspace $w) => $w->forceFill([
                'status' => WorkspaceStatus::Removed,
                'removed_at' => now(),
                'error' => null,
            ]), 'workspace.deleted'),
            RunnerCommandType::WorkspacePush => $this->workspace($command, fn (RepositoryWorkspace $w, array $r) => $w->forceFill([
                'published_sha' => $r['sha'] ?? null,
                'published_at' => now(),
                'error' => null,
            ]), 'workspace.published'),
            RunnerCommandType::ToolRun => $this->tools->succeeded($command),
            // Read by the waiting workflow step itself.
            RunnerCommandType::WorkspaceRead, RunnerCommandType::WorkspaceApply, RunnerCommandType::WorkspaceDiff => null,
        };
    }

    public function failed(RunnerCommand $command): void
    {
        $project = $command->project_id ? Project::with('connection')->find($command->project_id) : null;
        $message = $command->error_message ?? 'The runner reported a failure.';

        switch ($command->type) {
            case RunnerCommandType::ProjectScan:
                $project?->connection?->forceFill(['status' => ProjectConnectionStatus::Error, 'last_error' => Str::limit($message, 1000)])->save();
                break;

            case RunnerCommandType::ProjectIndex:
                // A failed build never replaces a good index.
                ProjectIndexSymbol::where('build_id', $command->id)->delete();
                ProjectIndexFile::where('build_id', $command->id)->delete();
                $project?->connection?->forceFill(['status' => ProjectConnectionStatus::Connected, 'last_error' => Str::limit("Code index failed: {$message}", 1000)])->save();
                break;

            case RunnerCommandType::WorkspaceCreate:
                $this->workspace($command, fn (RepositoryWorkspace $w) => $w->forceFill(['status' => WorkspaceStatus::Failed, 'error' => $message]), 'workspace.failed', requireSuccess: false);
                break;

            case RunnerCommandType::WorkspaceRemove:
                $this->workspace($command, fn (RepositoryWorkspace $w) => $w->forceFill(['status' => WorkspaceStatus::Ready, 'error' => $message]), 'workspace.remove_failed', requireSuccess: false);
                break;

            case RunnerCommandType::WorkspacePush:
                $this->workspace($command, fn (RepositoryWorkspace $w) => $w->forceFill(['error' => $message]), 'workspace.publish_failed', requireSuccess: false);
                break;

            case RunnerCommandType::ToolRun:
                $this->tools->failed($command);
                break;

            default:
                break;
        }
    }

    private function scanned(RunnerCommand $command): void
    {
        $project = Project::with('connection')->findOrFail($command->project_id);
        $result = $command->result ?? [];

        $stack = Arr::except((array) ($result['stack'] ?? []), ['warnings']);
        $stack['envShape'] = isset($stack['envShape']) && is_array($stack['envShape']) ? $this->redactor->envShape($stack['envShape']) : null;
        $git = (array) ($result['git'] ?? []);
        $warnings = array_values(array_map('strval', (array) ($result['warnings'] ?? [])));

        ProjectSnapshot::forceCreate([
            'project_id' => $project->id,
            'local_runner_id' => $command->local_runner_id,
            'runner_command_id' => $command->id,
            'stack' => $stack,
            'git' => $git,
            'warnings' => $warnings,
        ]);

        $project->connection->forceFill([
            'detected_stack' => $stack,
            'git_state' => $git,
            'git_enabled' => (bool) ($git['enabled'] ?? false),
            'default_branch' => $git['branch'] ?? $project->connection->default_branch,
            'warnings' => $warnings,
            'last_scanned_at' => now(),
            'last_error' => null,
            'status' => ProjectConnectionStatus::Indexing,
        ])->save();

        // Runners older than 0.2 report no tools; keep the approved set as it is then.
        $tools = array_key_exists('tools', $result) ? $this->catalog->sync($project, (array) $result['tools']) : null;

        $this->events->record('project.scanned', [
            'summary' => $this->summary($stack),
            'git_enabled' => (bool) ($git['enabled'] ?? false),
            'warnings' => count($warnings),
            'tools' => $tools['tools'] ?? null,
        ], $project);

        // Plan §12: Scan → Detect stack → Profile → Build code index → Ready.
        app(RunnerGateway::class)->dispatch(
            $command->runner,
            RunnerCommandType::ProjectIndex,
            ['project_path' => $command->payload['project_path']],
            $project,
            idempotencyKey: "index-after-scan:{$command->id}",
        );
    }

    private function indexed(RunnerCommand $command): void
    {
        $project = Project::with('connection')->findOrFail($command->project_id);

        // Swap to the new build atomically; older builds are removed.
        $previous = $project->index_build_id;
        $project->forceFill(['index_build_id' => $command->id])->save();

        ProjectIndexSymbol::where('project_id', $project->id)->where('build_id', '!=', $command->id)->delete();
        ProjectIndexFile::where('project_id', $project->id)->where('build_id', '!=', $command->id)->delete();

        $kinds = ProjectIndexSymbol::query()
            ->where('build_id', $command->id)
            ->selectRaw('kind, count(*) as aggregate')
            ->groupBy('kind')
            ->pluck('aggregate', 'kind')
            ->map(fn ($n) => (int) $n)
            ->all();

        $stats = [
            'files' => ProjectIndexFile::where('build_id', $command->id)->count(),
            'symbols' => array_sum($kinds),
            'kinds' => $kinds,
            'listed' => (int) ($command->result['listed'] ?? 0),
        ];

        $project->connection->forceFill([
            'indexed_at' => now(),
            'index_stats' => $stats,
            'status' => ProjectConnectionStatus::Connected,
        ])->save();

        $this->events->record('project.indexed', ['files' => $stats['files'], 'symbols' => $stats['symbols'], 'replaced_build' => $previous], $project);
    }

    private function gitInitialized(RunnerCommand $command): void
    {
        $project = Project::with('connection')->findOrFail($command->project_id);
        $result = $command->result ?? [];

        $project->connection->forceFill(['git_enabled' => true, 'default_branch' => $result['branch'] ?? 'main'])->save();

        $this->events->record('git.initialized', [
            'head' => $result['head'] ?? null,
            'files_committed' => $result['files_committed'] ?? null,
            'gitignore_created' => $result['gitignore_created'] ?? null,
            'secrets_excluded' => count((array) ($result['secrets_excluded'] ?? [])),
        ], $project);

        // Re-scan so the profile reflects the new repository.
        app(RunnerGateway::class)->dispatch(
            $command->runner,
            RunnerCommandType::ProjectScan,
            ['project_path' => $command->payload['project_path']],
            $project,
            idempotencyKey: "scan-after-git-init:{$command->id}",
        );
        $project->connection->forceFill(['status' => ProjectConnectionStatus::Scanning])->save();
    }

    /**
     * @param  callable(RepositoryWorkspace, array<string, mixed>): RepositoryWorkspace  $apply
     */
    private function workspace(RunnerCommand $command, callable $apply, string $event, bool $requireSuccess = true): void
    {
        $workspace = RepositoryWorkspace::find($command->payload['workspace_id'] ?? null);

        if (! $workspace) {
            return;
        }

        $apply($workspace, $requireSuccess ? ($command->result ?? []) : [])->save();

        $this->events->record($event, ['workspace' => $workspace->toClientArray(), 'error' => $requireSuccess ? null : $command->error_message], $workspace->project);
    }

    /**
     * One line for activity feeds: "Laravel 10.48 · React 18 + Inertia · Vite · Tailwind".
     *
     * @param  array<string, mixed>  $stack
     */
    public function summary(array $stack): string
    {
        $backend = $stack['backend'] ?? null;
        $frontend = $stack['frontend'] ?? null;
        $parts = array_filter([
            $backend ? trim(($backend['framework'] ?? '').' '.$this->shortVersion($backend['version'] ?? $backend['constraint'] ?? null)) : null,
            $frontend ? trim(($frontend['framework'] ?? '').' '.$this->shortVersion($frontend['version'] ?? null).(! empty($stack['bridge']) ? ' + '.$stack['bridge'] : '')) : null,
            $stack['bundler'] ?? null,
            ...(array) ($stack['css'] ?? []),
        ]);

        return $parts ? implode(' · ', array_slice($parts, 0, 5)) : 'No known framework detected';
    }

    private function shortVersion(?string $version): string
    {
        return $version ? implode('.', array_slice(explode('.', ltrim($version, '^~v')), 0, 2)) : '';
    }
}
