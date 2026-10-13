<?php

namespace App\Models;

use App\Enums\ToolKind;
use App\Enums\ToolRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of an approved tool in a task workspace and its parsed result.
 * Created and advanced only through App\Tools\ToolRunService.
 */
class ToolRun extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'kind' => ToolKind::class,
            'status' => ToolRunStatus::class,
            'tests' => 'array',
            'failures' => 'array',
            'issues' => 'array',
            'files_changed' => 'array',
            'flaky' => 'array',
            'database' => 'array',
            'notes' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<RepositoryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(RepositoryWorkspace::class, 'repository_workspace_id');
    }

    /** @return BelongsTo<RunnerCommand, $this> */
    public function runnerCommand(): BelongsTo
    {
        return $this->belongsTo(RunnerCommand::class, 'runner_command_id');
    }

    /** "120 passed · 2 failed" / "3 issues" / "2 files formatted" — from parsed output only. */
    public function summary(): ?string
    {
        if ($this->tests) {
            $parts = array_filter([
                ($this->tests['passed'] ?? 0) ? "{$this->tests['passed']} passed" : null,
                ($this->tests['failed'] ?? 0) ? "{$this->tests['failed']} failed" : null,
                ($this->tests['skipped'] ?? 0) ? "{$this->tests['skipped']} skipped" : null,
                $this->flaky ? count($this->flaky).' flaky' : null,
            ]);

            return $parts ? implode(' · ', $parts) : '0 tests';
        }

        $issues = count($this->issues ?? []);
        $errors = collect($this->issues ?? [])->where('severity', 'error')->count();

        return match (true) {
            $this->mode === 'fix' && $this->files_changed !== null => count($this->files_changed).' file(s) formatted',
            $issues > 0 => $errors === $issues ? "{$issues} issue(s)" : "{$errors} error(s) · ".($issues - $errors).' warning(s)',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function toClientArray(bool $detail = true): array
    {
        return [
            'id' => $this->id,
            'tool_key' => $this->tool_key,
            'kind' => $this->kind->value,
            'label' => $this->label,
            'command' => $this->command,
            'mode' => $this->mode,
            'status' => $this->status->value,
            'summary' => $this->summary(),
            'exit_code' => $this->exit_code,
            'duration_ms' => $this->duration_ms,
            'head_sha' => $this->head_sha,
            'progress' => $this->status->isTerminal() ? null : $this->progress,
            'error' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            ...($detail ? [
                'tests' => $this->tests,
                'failures' => $this->failures ?? [],
                'issues' => $this->issues ?? [],
                'files_changed' => $this->files_changed ?? [],
                'flaky' => $this->flaky ?? [],
                'database' => $this->database,
                'notes' => $this->notes ?? [],
                'output_tail' => $this->output_tail,
            ] : []),
        ];
    }
}
