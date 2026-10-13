<?php

namespace App\Runner;

use App\Models\LocalRunner;
use App\Models\Organization;
use App\Models\RunnerPairingCode;
use App\Models\User;
use App\Reliability\AuditLogger;
use App\Reliability\EventRecorder;
use App\Runner\Exceptions\InvalidPairingCodeException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pairing a developer machine (Plan §89.2): the Studio shows a short-lived,
 * single-use code; the runner exchanges it for a revocable per-machine token
 * and a signing secret. Only hashes of the code and token are stored.
 */
class RunnerPairing
{
    public const CODE_TTL_MINUTES = 10;

    /** No 0/O/1/I/L — codes are read off a screen and typed. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly EventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{code: string, expires_at: Carbon}
     */
    public function createCode(Organization $organization, User $user): array
    {
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $code = substr($raw, 0, 4).'-'.substr($raw, 4);

        $pairing = RunnerPairingCode::forceCreate([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'code_hash' => self::hash($code),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        $this->audit->log('runner.pairing_code.created', $user, $organization);

        return ['code' => $code, 'expires_at' => $pairing->expires_at];
    }

    /**
     * @param  array{name: string, hostname?: string|null, os?: string|null, platform?: string|null, version?: string|null}  $machine
     * @return array{runner: LocalRunner, token: string, signing_secret: string}
     */
    public function redeem(string $code, array $machine, ?string $ip): array
    {
        return DB::transaction(function () use ($code, $machine, $ip) {
            $pairing = RunnerPairingCode::query()
                ->where('code_hash', self::hash(strtoupper(trim($code))))
                ->lockForUpdate()
                ->first();

            if (! $pairing || $pairing->used_at || $pairing->expires_at->isPast()) {
                throw new InvalidPairingCodeException;
            }

            $token = Str::random(64);
            $secret = bin2hex(random_bytes(32));

            $runner = LocalRunner::forceCreate([
                'organization_id' => $pairing->organization_id,
                'user_id' => $pairing->user_id,
                'name' => Str::limit($machine['name'], 120, ''),
                'hostname' => isset($machine['hostname']) ? Str::limit($machine['hostname'], 255, '') : null,
                'os' => isset($machine['os']) ? Str::limit($machine['os'], 120, '') : null,
                'platform' => $machine['platform'] ?? null,
                'version' => $machine['version'] ?? null,
                'token_hash' => hash('sha256', $token),
                'signing_secret' => $secret,
                'last_ip' => $ip,
            ]);

            $pairing->forceFill(['used_at' => now(), 'local_runner_id' => $runner->id])->save();

            $organization = Organization::findOrFail($pairing->organization_id);
            $user = User::find($pairing->user_id);

            $this->events->record('runner.paired', ['runner' => ['id' => $runner->id, 'name' => $runner->name, 'hostname' => $runner->hostname]], organization: $organization, actor: $user);
            $this->audit->log('runner.paired', $user, $organization, subject: $runner, metadata: ['hostname' => $runner->hostname, 'platform' => $runner->platform]);

            return ['runner' => $runner, 'token' => $token, 'signing_secret' => $secret];
        });
    }

    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
