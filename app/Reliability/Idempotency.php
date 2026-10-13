<?php

namespace App\Reliability;

use App\Reliability\Exceptions\DuplicateExecutionException;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Run-once guard for jobs, AI calls and tool commands (Plan §48.3, guarantee G4).
 *
 * The callback's result must be JSON-serializable: it is stored so that a
 * retry with the same key returns the stored result instead of re-executing.
 */
class Idempotency
{
    public function once(string $key, Closure $callback, int $lockSeconds = 600): mixed
    {
        $previous = $this->claim($key, $lockSeconds);

        if ($previous !== null) {
            return $previous['result'];
        }

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $this->release($key, 'failed');

            throw $e;
        }

        $this->release($key, 'completed', $result);

        return $result;
    }

    public function hasCompleted(string $key): bool
    {
        return DB::table('idempotency_keys')->where('key', $key)->where('status', 'completed')->exists();
    }

    /**
     * Claims the key for this execution. Returns the stored result wrapper
     * when the key already completed, or null when the caller should execute.
     *
     * @return array{result: mixed}|null
     *
     * @throws DuplicateExecutionException when another live execution holds the key
     */
    private function claim(string $key, int $lockSeconds): ?array
    {
        try {
            DB::table('idempotency_keys')->insert([
                'key' => $key,
                'status' => 'running',
                'locked_until' => now()->addSeconds($lockSeconds),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return null;
        } catch (UniqueConstraintViolationException) {
            // This key has been seen before — decide below.
        }

        return DB::transaction(function () use ($key, $lockSeconds) {
            $row = DB::table('idempotency_keys')->where('key', $key)->lockForUpdate()->first();

            if ($row->status === 'completed') {
                return ['result' => json_decode($row->result, true)];
            }

            if ($row->status === 'running' && $row->locked_until && now()->lt($row->locked_until)) {
                throw new DuplicateExecutionException("Execution for [{$key}] is already in progress.");
            }

            // The previous attempt failed or its lock expired (crashed worker): take over.
            DB::table('idempotency_keys')->where('key', $key)->update([
                'status' => 'running',
                'locked_until' => now()->addSeconds($lockSeconds),
                'updated_at' => now(),
            ]);

            return null;
        });
    }

    private function release(string $key, string $status, mixed $result = null): void
    {
        DB::table('idempotency_keys')->where('key', $key)->update([
            'status' => $status,
            'result' => $status === 'completed' ? json_encode($result) : null,
            'locked_until' => null,
            'updated_at' => now(),
        ]);
    }
}
