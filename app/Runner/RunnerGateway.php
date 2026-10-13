<?php

namespace App\Runner;

use App\Enums\RunnerCommandStatus;
use App\Enums\RunnerCommandType;
use App\Enums\RunnerStatus;
use App\Models\LocalRunner;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectIndexFile;
use App\Models\ProjectIndexSymbol;
use App\Models\RunnerCommand;
use App\Models\User;
use App\Reliability\EventRecorder;
use App\Reliability\SystemHalt;
use App\Runner\Exceptions\RunnerUnavailableException;
use App\Security\SecretRedactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Studio side of the runner protocol (Plan §89.2).
 *
 * - Commands are persisted first; the runner pulls them (outbound only).
 * - Each delivery is HMAC-signed with the runner's secret at claim time.
 * - A claim is a lease: if the runner goes quiet the command is offered again,
 *   and the runner answers re-deliveries from its own state (guarantee G4).
 * - Everything a runner reports is redacted again before it is stored (§16).
 */
class RunnerGateway
{
    public const COMMAND_TTL_HOURS = 24;

    public function __construct(
        private readonly EventRecorder $events,
        private readonly SystemHalt $halt,
        private readonly SecretRedactor $redactor,
        private readonly RunnerResultProcessor $results,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(
        LocalRunner $runner,
        RunnerCommandType $type,
        array $payload,
        ?Project $project = null,
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): RunnerCommand {
        if ($runner->revoked_at) {
            throw new RunnerUnavailableException("Runner \"{$runner->name}\" has been revoked. Pair a new runner.");
        }

        if ($idempotencyKey && ($existing = RunnerCommand::where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        return DB::transaction(function () use ($runner, $type, $payload, $project, $actor, $idempotencyKey) {
            $command = RunnerCommand::forceCreate([
                'local_runner_id' => $runner->id,
                'organization_id' => $runner->organization_id,
                'project_id' => $project?->id,
                'type' => $type,
                'payload' => $payload,
                'status' => RunnerCommandStatus::Queued,
                'idempotency_key' => $idempotencyKey,
                'attempts' => 0,
                'max_attempts' => 3,
                'created_by' => $actor?->id,
                'expires_at' => now()->addHours(self::COMMAND_TTL_HOURS),
            ]);

            $this->record($command, 'runner.command.queued', $actor);

            return $command;
        });
    }

    /**
     * Hand the next queued command to the runner, signed. Nothing is handed
     * out while AI operations are stopped for the runner's organization.
     *
     * @return array{id: string, type: string, issued_at: string, payload_json: string, signature: string}|null
     */
    public function claim(LocalRunner $runner): ?array
    {
        if ($this->halt->isHalted($runner->organization_id)) {
            return null;
        }

        $command = DB::transaction(function () use ($runner) {
            $command = RunnerCommand::query()
                ->where('local_runner_id', $runner->id)
                ->where('status', RunnerCommandStatus::Queued)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $command) {
                return null;
            }

            $command->forceFill([
                'status' => RunnerCommandStatus::Claimed,
                'attempts' => $command->attempts + 1,
                'claimed_at' => now(),
                'lease_expires_at' => now()->addSeconds($command->type->leaseSeconds()),
            ])->save();

            $this->record($command, 'runner.command.started');
            $this->results->claimed($command);

            return $command;
        });

        return $command ? $this->sign($command, $runner) : null;
    }

    /**
     * @return array{id: string, type: string, issued_at: string, payload_json: string, signature: string}
     */
    public function sign(RunnerCommand $command, LocalRunner $runner): array
    {
        $issuedAt = now()->toIso8601ZuluString('millisecond');
        $payloadJson = json_encode($command->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', "{$command->id}\n{$command->type->value}\n{$issuedAt}\n{$payloadJson}", $runner->signing_secret);

        return [
            'id' => $command->id,
            'type' => $command->type->value,
            'issued_at' => $issuedAt,
            'payload_json' => $payloadJson,
            'signature' => $signature,
        ];
    }

    /**
     * Real step text from the PC (Plan §7.7 "Detecting framework… Laravel 10").
     * Each message also extends the lease: the runner is demonstrably alive.
     *
     * @return bool whether the runner should stop the command (cancel requested)
     */
    public function progress(RunnerCommand $command, string $message): bool
    {
        if ($command->status !== RunnerCommandStatus::Claimed) {
            return $command->status->isTerminal();
        }

        DB::transaction(function () use ($command, $message) {
            $command->forceFill([
                'progress' => Str::limit($this->redactor->redactText($message), 500, ''),
                'lease_expires_at' => now()->addSeconds($command->type->leaseSeconds()),
            ])->save();

            // Tool runs report their own, richer progress event.
            $command->type === RunnerCommandType::ToolRun
                ? $this->results->progressed($command, $message)
                : $this->record($command, 'runner.command.progress');
        });

        return $command->cancel_requested_at !== null;
    }

    /**
     * A queued command is cancelled at once; a running one is told to stop on its
     * next progress report (the runner then kills the process tree, §57).
     */
    public function cancel(RunnerCommand $command, ?User $by = null, string $reason = 'Cancelled'): void
    {
        DB::transaction(function () use ($command, $by, $reason) {
            $locked = RunnerCommand::whereKey($command->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === RunnerCommandStatus::Queued) {
                $this->markCancelled($locked, $reason, $by);
            } elseif ($locked->status === RunnerCommandStatus::Claimed && ! $locked->cancel_requested_at) {
                $locked->forceFill(['cancel_requested_at' => now()])->save();
                $this->record($locked, 'runner.command.cancel_requested', $by);
            }

            $command->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Idempotent: a result for a command that already finished is ignored.
     *
     * @param  array<string, mixed>|null  $result
     */
    public function complete(RunnerCommand $command, bool $succeeded, ?array $result, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        DB::transaction(function () use ($command, $succeeded, $result, $errorCode, $errorMessage) {
            $locked = RunnerCommand::whereKey($command->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                return;
            }

            $locked->forceFill([
                'status' => $succeeded ? RunnerCommandStatus::Succeeded : RunnerCommandStatus::Failed,
                'result' => $succeeded ? $this->redactor->redact($result ?? []) : null,
                'error_code' => $succeeded ? null : Str::limit((string) $errorCode, 64, ''),
                'error_message' => $succeeded ? null : Str::limit($this->redactor->redactText((string) $errorMessage), 2000),
                'completed_at' => now(),
                'lease_expires_at' => null,
            ])->save();

            $this->record($locked, $succeeded ? 'runner.command.succeeded' : 'runner.command.failed');

            $succeeded ? $this->results->succeeded($locked) : $this->results->failed($locked);

            $command->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Store one batch of the code index the runner streams for project.index.
     *
     * @param  list<array<string, mixed>>  $files  validated by the controller
     */
    public function storeIndexChunk(RunnerCommand $command, array $files): int
    {
        if ($command->type !== RunnerCommandType::ProjectIndex || $command->status !== RunnerCommandStatus::Claimed || ! $command->project_id) {
            return 0;
        }

        return DB::transaction(function () use ($command, $files) {
            $stored = 0;

            foreach ($files as $file) {
                $path = str_replace('\\', '/', (string) $file['path']);
                $key = ['project_id' => $command->project_id, 'build_id' => $command->id, 'path_hash' => sha1($path)];

                if (ProjectIndexFile::where($key)->exists()) {
                    continue; // re-sent chunk: already stored
                }

                $row = ProjectIndexFile::forceCreate([
                    ...$key,
                    'path' => $path,
                    'language' => $file['language'],
                    'size' => (int) $file['size'],
                    'sha1' => ($file['sha1'] ?? '') ?: null,
                    'symbol_count' => count($file['symbols'] ?? []),
                ]);

                $symbols = array_map(fn (array $s) => [
                    'project_id' => $command->project_id,
                    'file_id' => $row->id,
                    'build_id' => $command->id,
                    'kind' => $s['kind'],
                    'name' => Str::limit($this->redactor->redactText((string) $s['name']), 255, ''),
                    'line' => (int) $s['line'],
                    'detail' => isset($s['detail']) ? Str::limit($this->redactor->redactText((string) $s['detail']), 500, '') : null,
                ], $file['symbols'] ?? []);

                foreach (array_chunk($symbols, 500) as $batch) {
                    ProjectIndexSymbol::insert($batch);
                }

                $stored++;
            }

            $command->forceFill(['lease_expires_at' => now()->addSeconds($command->type->leaseSeconds())])->save();

            return $stored;
        });
    }

    /**
     * @param  array{version?: string|null, allowed_roots?: list<string>, workspaces_root?: string|null, capabilities?: list<string>}  $data
     * @return array{halted: bool}
     */
    public function heartbeat(LocalRunner $runner, array $data, ?string $ip): array
    {
        $wasAway = $runner->status() !== RunnerStatus::Online;

        $runner->forceFill([
            'last_heartbeat_at' => now(),
            'last_ip' => $ip,
            'version' => $data['version'] ?? $runner->version,
            'allowed_roots' => array_values($data['allowed_roots'] ?? $runner->allowed_roots ?? []),
            'workspaces_root' => $data['workspaces_root'] ?? $runner->workspaces_root,
            'capabilities' => array_values($data['capabilities'] ?? $runner->capabilities ?? []),
        ])->save();

        if ($wasAway) {
            $this->events->record('runner.connected', ['runner' => ['id' => $runner->id, 'name' => $runner->name]], organization: $runner->organization);
        }

        return ['halted' => $this->halt->isHalted($runner->organization_id)];
    }

    /**
     * Scheduler (every minute): re-offer commands whose lease ran out, expire
     * commands no runner picked up, and report runners that went offline.
     *
     * @return array{requeued: int, failed: int, expired: int, disconnected: int}
     */
    public function recover(): array
    {
        $counts = ['requeued' => 0, 'failed' => 0, 'expired' => 0, 'disconnected' => 0];

        RunnerCommand::query()
            ->where('status', RunnerCommandStatus::Claimed)
            ->where('lease_expires_at', '<', now())
            ->pluck('id')
            ->each(function (string $id) use (&$counts) {
                DB::transaction(function () use ($id, &$counts) {
                    $command = RunnerCommand::whereKey($id)->lockForUpdate()->first();

                    if ($command?->status !== RunnerCommandStatus::Claimed || $command->lease_expires_at?->isFuture()) {
                        return;
                    }

                    // Stopped by its owner while the runner was away: never offer it again.
                    if ($command->cancel_requested_at) {
                        $this->markCancelled($command, 'Cancelled while the runner was not responding.');
                        $counts['failed']++;

                        return;
                    }

                    if ($command->attempts < $command->max_attempts) {
                        $command->forceFill(['status' => RunnerCommandStatus::Queued, 'lease_expires_at' => null])->save();
                        $this->record($command, 'runner.command.requeued');
                        $counts['requeued']++;

                        return;
                    }

                    $command->forceFill([
                        'status' => RunnerCommandStatus::Failed,
                        'error_code' => 'LEASE_EXPIRED',
                        'error_message' => 'The runner stopped responding while running this command, '.$command->attempts.' times.',
                        'completed_at' => now(),
                    ])->save();
                    $this->record($command, 'runner.command.failed');
                    $this->results->failed($command);
                    $counts['failed']++;
                });
            });

        RunnerCommand::query()
            ->where('status', RunnerCommandStatus::Queued)
            ->where('expires_at', '<', now())
            ->pluck('id')
            ->each(function (string $id) use (&$counts) {
                DB::transaction(function () use ($id, &$counts) {
                    $command = RunnerCommand::whereKey($id)->lockForUpdate()->first();

                    if ($command?->status !== RunnerCommandStatus::Queued) {
                        return;
                    }

                    $command->forceFill([
                        'status' => RunnerCommandStatus::Expired,
                        'error_code' => 'EXPIRED',
                        'error_message' => 'No runner picked this up within '.self::COMMAND_TTL_HOURS.' hours. Start the runner on that PC and try again.',
                        'completed_at' => now(),
                    ])->save();
                    $this->record($command, 'runner.command.failed');
                    $this->results->failed($command);
                    $counts['expired']++;
                });
            });

        // Runners whose heartbeat stopped: one "disconnected" event per outage.
        $failCutoff = now()->subSeconds((int) config('eaj.heartbeat.fail_seconds'));

        LocalRunner::query()
            ->active()
            ->where('last_heartbeat_at', '<', $failCutoff)
            ->where('last_heartbeat_at', '>=', $failCutoff->copy()->subMinute())
            ->get()
            ->each(function (LocalRunner $runner) use (&$counts) {
                $this->events->record('runner.disconnected', ['runner' => ['id' => $runner->id, 'name' => $runner->name]], organization: $runner->organization);
                $counts['disconnected']++;
            });

        return $counts;
    }

    public function revoke(LocalRunner $runner, User $by): void
    {
        DB::transaction(function () use ($runner, $by) {
            $runner->forceFill(['revoked_at' => now()])->save();

            $runner->commands()
                ->whereIn('status', [RunnerCommandStatus::Queued, RunnerCommandStatus::Claimed])
                ->get()
                ->each(function (RunnerCommand $command) {
                    $command->forceFill([
                        'status' => RunnerCommandStatus::Cancelled,
                        'error_code' => 'RUNNER_REVOKED',
                        'error_message' => 'The runner was revoked.',
                        'completed_at' => now(),
                    ])->save();
                    $this->results->failed($command);
                });

            $this->events->record('runner.revoked', ['runner' => ['id' => $runner->id, 'name' => $runner->name]], organization: $runner->organization, actor: $by);
        });
    }

    private function markCancelled(RunnerCommand $command, string $reason, ?User $by = null): void
    {
        $command->forceFill([
            'status' => RunnerCommandStatus::Cancelled,
            'error_code' => 'CANCELLED',
            'error_message' => Str::limit($reason, 2000),
            'completed_at' => now(),
            'lease_expires_at' => null,
        ])->save();
        $this->record($command, 'runner.command.failed', $by);
        $this->results->failed($command);
    }

    private function record(RunnerCommand $command, string $type, ?User $actor = null): void
    {
        $project = $command->project_id ? Project::find($command->project_id) : null;

        $this->events->record($type, [
            'command' => $command->toClientArray(),
            'runner_id' => $command->local_runner_id,
        ], $project, $project ? null : Organization::find($command->organization_id), $actor);
    }
}
