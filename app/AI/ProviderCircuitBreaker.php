<?php

namespace App\AI;

use App\AI\Exceptions\CircuitOpenException;
use Illuminate\Support\Facades\Cache;

class ProviderCircuitBreaker
{
    public function guard(string $provider): void
    {
        $state = $this->state($provider);
        if (($state['open_until'] ?? 0) > now()->timestamp) {
            throw new CircuitOpenException("The {$provider} circuit is open until ".now()->setTimestamp($state['open_until'])->toIso8601String().'.', $provider, 'circuit_open', true);
        }
    }

    public function success(string $provider): void
    {
        $this->mutate($provider, function (array $state) {
            $state['consecutive'] = 0;
            $state['open_until'] = 0;
            $state['outcomes'][] = ['at' => now()->timestamp, 'failed' => false];

            return $this->trim($state);
        });
    }

    public function failure(string $provider): void
    {
        $this->mutate($provider, function (array $state) {
            $state = $this->trim($state);
            $state['consecutive'] = ($state['consecutive'] ?? 0) + 1;
            $state['outcomes'][] = ['at' => now()->timestamp, 'failed' => true];
            $count = count($state['outcomes']);
            $failures = count(array_filter($state['outcomes'], fn ($outcome) => $outcome['failed']));
            $config = config('ai.circuit_breaker');
            if ($state['consecutive'] >= $config['consecutive_failures'] || ($count >= $config['minimum_samples'] && ($failures / $count) * 100 > $config['error_percentage'])) {
                $state['open_until'] = now()->addSeconds($config['open_seconds'])->timestamp;
            }

            return $state;
        });
    }

    /** @return array{open: bool, open_until: ?string, consecutive_failures: int, samples: int} */
    public function snapshot(string $provider): array
    {
        $state = $this->trim($this->state($provider));
        $until = (int) ($state['open_until'] ?? 0);

        return [
            'open' => $until > now()->timestamp,
            'open_until' => $until ? now()->setTimestamp($until)->toIso8601String() : null,
            'consecutive_failures' => (int) ($state['consecutive'] ?? 0),
            'samples' => count($state['outcomes']),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function snapshots(): array
    {
        return collect(array_keys(config('ai.providers')))->mapWithKeys(fn ($provider) => [$provider => $this->snapshot($provider)])->all();
    }

    /** @return array{consecutive: int, open_until: int, outcomes: list<array{at: int, failed: bool}>} */
    private function state(string $provider): array
    {
        return Cache::get($this->key($provider), ['consecutive' => 0, 'open_until' => 0, 'outcomes' => []]);
    }

    private function mutate(string $provider, callable $callback): void
    {
        Cache::lock($this->key($provider).':lock', 5)->block(2, function () use ($provider, $callback) {
            Cache::put($this->key($provider), $callback($this->state($provider)), now()->addDay());
        });
    }

    /** @param array<string, mixed> $state */
    private function trim(array $state): array
    {
        $cutoff = now()->subSeconds((int) config('ai.circuit_breaker.window_seconds'))->timestamp;
        $state['outcomes'] = array_values(array_filter($state['outcomes'] ?? [], fn ($outcome) => $outcome['at'] >= $cutoff));

        return $state;
    }

    private function key(string $provider): string
    {
        return 'ai:circuit:'.strtolower($provider);
    }
}
