<?php

namespace Tests\Feature\Reliability;

use App\Reliability\Exceptions\DuplicateExecutionException;
use App\Reliability\Idempotency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_key_executes_once_and_replays_the_stored_result()
    {
        $guard = app(Idempotency::class);
        $calls = 0;

        $first = $guard->once('task:1:step:2:attempt:1', function () use (&$calls) {
            $calls++;

            return ['tokens' => 1200];
        });
        $second = $guard->once('task:1:step:2:attempt:1', function () use (&$calls) {
            $calls++;

            return ['tokens' => 9999];
        });

        $this->assertSame(1, $calls);
        $this->assertSame(['tokens' => 1200], $first);
        $this->assertSame(['tokens' => 1200], $second);
        $this->assertTrue($guard->hasCompleted('task:1:step:2:attempt:1'));
    }

    public function test_a_key_held_by_a_live_execution_cannot_run_concurrently()
    {
        DB::table('idempotency_keys')->insert([
            'key' => 'busy', 'status' => 'running', 'locked_until' => now()->addMinute(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(DuplicateExecutionException::class);
        app(Idempotency::class)->once('busy', fn () => 'never');
    }

    public function test_failed_or_expired_executions_can_be_taken_over()
    {
        $guard = app(Idempotency::class);

        try {
            $guard->once('flaky', fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        $this->assertSame('ok', $guard->once('flaky', fn () => 'ok'));

        DB::table('idempotency_keys')->insert([
            'key' => 'crashed', 'status' => 'running', 'locked_until' => now()->subMinute(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('recovered', $guard->once('crashed', fn () => 'recovered'));
    }
}
