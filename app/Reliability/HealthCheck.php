<?php

namespace App\Reliability;

use App\AI\ProviderCircuitBreaker;
use App\AI\ProviderRegistry;
use App\Models\LocalRunner;
use App\Models\ProjectConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Health of every moving part (Plan §77, §90). Used by the public /health
 * endpoint (monitored externally) and by the Command Center.
 *
 * Status: ok | degraded | down. "down" makes /health return 503.
 */
class HealthCheck
{
    public function __construct(
        private readonly SystemHalt $halt,
        private readonly ProviderRegistry $providers,
        private readonly ProviderCircuitBreaker $circuits,
    ) {}

    /**
     * @param  int|null  $organizationId  include that organization's emergency stop; null = platform only (public /health)
     * @return array{status: string, checks: list<array{key: string, label: string, status: string, detail: string}>}
     */
    public function run(?int $organizationId = null): array
    {
        $checks = [
            $this->database(),
            $this->cache(),
            $this->redis(),
            $this->queue(),
            $this->outbox(),
            $this->realtime(),
            $this->runners($organizationId),
            $this->operations($organizationId),
            $this->aiProviders(),
        ];

        $status = match (true) {
            collect($checks)->contains('status', 'down') => 'down',
            collect($checks)->contains('status', 'degraded') => 'degraded',
            default => 'ok',
        };

        return ['status' => $status, 'checks' => $checks];
    }

    private function database(): array
    {
        try {
            $started = microtime(true);
            DB::select('select 1');
            $ms = (int) round((microtime(true) - $started) * 1000);

            return $this->check('database', 'Database', 'ok', "Responding in {$ms} ms");
        } catch (Throwable) {
            return $this->check('database', 'Database', 'down', 'Not reachable — workers are paused');
        }
    }

    private function cache(): array
    {
        try {
            $key = 'eaj:health:'.Str::random(8);
            Cache::put($key, 'ok', 10);
            $ok = Cache::pull($key) === 'ok';

            return $this->check('cache', 'Cache', $ok ? 'ok' : 'down', $ok ? 'Read/write OK ('.config('cache.default').')' : 'Read/write failed');
        } catch (Throwable) {
            return $this->check('cache', 'Cache', 'down', 'Not reachable');
        }
    }

    private function queue(): array
    {
        $connection = config('queue.default');

        if ($connection === 'sync') {
            return $this->check('queue', 'Queue', 'ok', 'Synchronous (no worker needed)');
        }

        try {
            $failed = DB::table('failed_jobs')->count();

            if ($connection !== 'database') {
                return $this->check('queue', 'Queue', $failed > 0 ? 'degraded' : 'ok', "{$failed} failed job(s) ({$connection})");
            }

            $pending = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', now()->getTimestamp())->min('available_at');
            $waitSeconds = $oldest ? now()->getTimestamp() - (int) $oldest : 0;

            if ($waitSeconds > 300) {
                return $this->check('queue', 'Queue', 'degraded', "Jobs waiting {$this->human($waitSeconds)} — is a queue worker running?");
            }

            return $this->check('queue', 'Queue', $failed > 0 ? 'degraded' : 'ok', "{$pending} pending · {$failed} failed");
        } catch (Throwable) {
            return $this->check('queue', 'Queue', 'down', 'Queue tables not reachable');
        }
    }

    private function redis(): array
    {
        $required = config('queue.default') === 'redis'
            || config('cache.default') === 'redis'
            || (bool) config('reverb.servers.reverb.scaling.enabled');

        if (! $required) {
            return $this->check('redis', 'Redis', 'ok', 'Ready; database fallback active locally');
        }

        try {
            Redis::connection()->ping();

            return $this->check('redis', 'Redis', 'ok', 'Responding');
        } catch (Throwable) {
            return $this->check('redis', 'Redis', 'down', 'Required by queue, cache, or Reverb scaling but not reachable');
        }
    }

    private function outbox(): array
    {
        try {
            $oldest = DB::table('outbox_events')->whereNull('published_at')->min('occurred_at');
            $backlog = DB::table('outbox_events')->whereNull('published_at')->count();

            if ($oldest === null) {
                return $this->check('outbox', 'Event outbox', 'ok', 'All events published');
            }

            $age = now()->diffInSeconds($oldest, true);

            return $this->check(
                'outbox',
                'Event outbox',
                $age > 60 ? 'degraded' : 'ok',
                "{$backlog} unpublished · oldest {$this->human((int) $age)} — clients catch up over HTTP",
            );
        } catch (Throwable) {
            return $this->check('outbox', 'Event outbox', 'down', 'Outbox not reachable');
        }
    }

    private function realtime(): array
    {
        $driver = config('broadcasting.default');

        if ($driver !== 'reverb') {
            return in_array($driver, ['pusher', 'ably'], true)
                ? $this->check('realtime', 'Realtime', 'ok', "WebSockets via {$driver} + HTTP catch-up")
                : $this->check('realtime', 'Realtime', 'ok', 'HTTP catch-up polling');
        }

        $host = (string) config('broadcasting.connections.reverb.options.host');
        $port = (int) config('broadcasting.connections.reverb.options.port', 443);
        $socket = $host !== '' ? @fsockopen($host, $port, $errorCode, $errorMessage, 0.25) : false;

        if (is_resource($socket)) {
            fclose($socket);

            return $this->check('realtime', 'Realtime', 'ok', 'Reverb connected + durable HTTP catch-up');
        }

        return $this->check('realtime', 'Realtime', 'degraded', 'Reverb not reachable; durable HTTP polling remains active');
    }

    /**
     * Local runners (Plan §89.2, §48.10): a project whose runner is offline cannot be scanned or worked on.
     */
    private function runners(?int $organizationId): array
    {
        try {
            $runners = LocalRunner::query()->active()->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))->get();
        } catch (Throwable) {
            return $this->check('runners', 'Local runners', 'down', 'Runner registry not reachable');
        }

        if ($runners->isEmpty()) {
            return $this->check('runners', 'Local runners', 'ok', 'None paired yet — pair one in Settings → Local runners');
        }

        $online = $runners->filter->isOnline();

        if ($organizationId !== null) {
            $needed = ProjectConnection::query()
                ->whereIn('local_runner_id', $runners->pluck('id'))
                ->distinct()
                ->pluck('local_runner_id');
            $offlineNeeded = $runners->whereIn('id', $needed)->reject->isOnline();

            if ($offlineNeeded->isNotEmpty()) {
                return $this->check('runners', 'Local runners', 'degraded', 'Offline: '.$offlineNeeded->pluck('name')->join(', ').' — start the runner on that PC');
            }
        }

        return $this->check('runners', 'Local runners', 'ok', "{$online->count()} of {$runners->count()} online");
    }

    private function operations(?int $organizationId): array
    {
        if ($global = $this->halt->state(null)) {
            return $this->check('operations', 'AI operations', 'degraded', 'Stopped platform-wide: '.$global['reason']);
        }

        if ($organizationId !== null && ($local = $this->halt->state($organizationId))) {
            return $this->check('operations', 'AI operations', 'degraded', 'Stopped for this organization: '.$local['reason']);
        }

        return $this->check('operations', 'AI operations', 'ok', 'Running');
    }

    /** §48.7 / §82: configured providers and their circuit breakers. Names only — never keys. */
    private function aiProviders(): array
    {
        $configured = collect(array_keys(config('ai.providers', [])))->filter(fn (string $key) => $this->providers->configured($key));
        $open = $configured->filter(fn (string $key) => $this->circuits->snapshot($key)['open']);
        $label = fn ($keys) => $keys->map(fn ($key) => config("ai.providers.{$key}.label", $key))->join(', ');

        return match (true) {
            $configured->isEmpty() => $this->check('ai', 'AI providers', 'degraded', 'None configured — add an API key in .env before agents can run'),
            $open->isNotEmpty() => $this->check('ai', 'AI providers', 'degraded', "Circuit open for {$label($open)} — calls go to the fallback"),
            $configured->count() === 1 => $this->check('ai', 'AI providers', 'ok', "{$label($configured)} only — add a second provider for fallback"),
            default => $this->check('ai', 'AI providers', 'ok', "{$label($configured)} configured"),
        };
    }

    private function check(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }

    private function human(int $seconds): string
    {
        return $seconds < 120 ? "{$seconds}s" : (int) round($seconds / 60).' min';
    }
}
