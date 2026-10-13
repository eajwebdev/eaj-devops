<?php

namespace App\Tools;

use App\Enums\ToolRunStatus;
use App\Models\RunnerCommand;
use App\Models\ToolRun;
use App\Reliability\EventRecorder;
use App\Security\SecretRedactor;
use Illuminate\Support\Str;

/**
 * Applies what the runner reports about a tool run. Called by the runner
 * gateway inside the transaction that changes the command, so the tool run,
 * the command and their events always agree.
 */
class ToolRunResults
{
    private const STATUSES = ['passed', 'failed', 'flaky', 'error', 'skipped'];

    public function __construct(
        private readonly EventRecorder $events,
        private readonly SecretRedactor $redactor,
    ) {}

    public function claimed(RunnerCommand $command): void
    {
        $run = $this->run($command);

        if (! $run || $run->status->isTerminal()) {
            return;
        }

        $run->forceFill(['status' => ToolRunStatus::Running, 'started_at' => $run->started_at ?? now(), 'progress' => null])->save();
        $this->record($run, 'tool.run.started');
    }

    public function progressed(RunnerCommand $command, string $message): void
    {
        $run = $this->run($command);

        if (! $run || $run->status->isTerminal()) {
            return;
        }

        $run->forceFill(['status' => ToolRunStatus::Running, 'progress' => Str::limit($this->redactor->redactText($message), 500, '')])->save();
        $this->events->record('tool.run.progress', ['tool_run' => ['id' => $run->id, 'task_id' => $run->task_id, 'label' => $run->label], 'message' => $run->progress], $run->project);
    }

    public function succeeded(RunnerCommand $command): void
    {
        $run = $this->run($command);

        if (! $run || $run->status->isTerminal()) {
            return;
        }

        $r = (array) ($command->result ?? []);
        $status = in_array($r['status'] ?? null, self::STATUSES, true) ? ToolRunStatus::from($r['status']) : ToolRunStatus::Error;

        $run->forceFill([
            'status' => $status,
            'command' => is_string($r['command'] ?? null) ? Str::limit($r['command'], 500, '') : $run->command,
            'exit_code' => isset($r['exit_code']) && is_int($r['exit_code']) ? $r['exit_code'] : null,
            'duration_ms' => isset($r['duration_ms']) && is_numeric($r['duration_ms']) ? max(0, (int) $r['duration_ms']) : null,
            'head_sha' => is_string($r['head_sha'] ?? null) && preg_match('/^[0-9a-f]{40}$/', $r['head_sha']) ? $r['head_sha'] : null,
            'tests' => $this->tests($r['tests'] ?? null),
            'failures' => $this->failures($r['failures'] ?? []),
            'issues' => $this->issues($r['issues'] ?? []),
            'files_changed' => $this->paths($r['files_changed'] ?? []),
            'flaky' => array_slice(array_values(array_filter((array) ($r['flaky'] ?? []), 'is_string')), 0, 50),
            'database' => $this->database($r['database'] ?? null),
            'notes' => array_slice(array_map(fn ($n) => Str::limit((string) $n, 500), array_values(array_filter((array) ($r['notes'] ?? []), 'is_string'))), 0, 20),
            'output_tail' => is_string($r['output_tail'] ?? null) ? Str::limit($r['output_tail'], 64 * 1024, '') : null,
            'progress' => null,
            'finished_at' => now(),
        ])->save();

        $this->record($run, 'tool.run.finished');
    }

    public function failed(RunnerCommand $command): void
    {
        $run = $this->run($command);

        if (! $run || $run->status->isTerminal()) {
            return;
        }

        $status = match ($command->error_code) {
            'CANCELLED', 'RUNNER_REVOKED' => ToolRunStatus::Cancelled,
            'COMMAND_BLOCKED', 'TEST_DB_UNSAFE' => ToolRunStatus::Blocked,
            default => ToolRunStatus::Error,
        };

        $run->forceFill([
            'status' => $status,
            'error_code' => $command->error_code,
            'error_message' => $command->error_message,
            'progress' => null,
            'finished_at' => now(),
        ])->save();

        $this->record($run, 'tool.run.finished');
    }

    private function run(RunnerCommand $command): ?ToolRun
    {
        return ToolRun::where('runner_command_id', $command->id)->lockForUpdate()->first();
    }

    private function record(ToolRun $run, string $type): void
    {
        $this->events->record($type, ['tool_run' => $run->toClientArray(detail: false), 'task_id' => $run->task_id, 'workflow_id' => $run->workflow_id], $run->project);
    }

    /** @return array{total: int, passed: int, failed: int, skipped: int}|null */
    private function tests(mixed $tests): ?array
    {
        if (! is_array($tests)) {
            return null;
        }

        return collect(['total', 'passed', 'failed', 'skipped'])->mapWithKeys(fn ($k) => [$k => max(0, (int) ($tests[$k] ?? 0))])->all();
    }

    /** @return list<array{name: string, file: string|null, message: string, flaky: bool}> */
    private function failures(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn ($f) => is_array($f) && is_string($f['name'] ?? null))
            ->take(50)
            ->map(fn (array $f) => [
                'name' => Str::limit($f['name'], 500, ''),
                'file' => $this->path($f['file'] ?? null),
                'message' => Str::limit((string) ($f['message'] ?? ''), 2000),
                'flaky' => (bool) ($f['flaky'] ?? false),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function issues(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn ($i) => is_array($i) && is_string($i['message'] ?? null))
            ->take(200)
            ->map(fn (array $i) => [
                'file' => $this->path($i['file'] ?? null),
                'line' => is_int($i['line'] ?? null) ? $i['line'] : null,
                'column' => is_int($i['column'] ?? null) ? $i['column'] : null,
                'severity' => ($i['severity'] ?? 'error') === 'warning' ? 'warning' : 'error',
                'message' => Str::limit($i['message'], 1000),
                'rule' => is_string($i['rule'] ?? null) ? Str::limit($i['rule'], 120, '') : null,
            ])
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function paths(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])->map(fn ($p) => $this->path($p))->filter()->take(500)->values()->all();
    }

    /** Workspace-relative paths only; anything that looks like a local absolute path is reduced to its name. */
    private function path(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = str_replace('\\', '/', $path);

        return Str::limit(preg_match('#^([A-Za-z]:/|/)#', $path) ? basename($path) : $path, 1024, '');
    }

    /** @return array{connection: string, database: string, source: string}|null */
    private function database(mixed $db): ?array
    {
        if (! is_array($db) || ! is_string($db['connection'] ?? null)) {
            return null;
        }

        return [
            'connection' => Str::limit($db['connection'], 32, ''),
            'database' => Str::limit((string) ($db['database'] ?? ''), 128, ''),
            'source' => in_array($db['source'] ?? null, ['phpunit.xml', '.env.testing', 'injected'], true) ? $db['source'] : 'injected',
        ];
    }
}
