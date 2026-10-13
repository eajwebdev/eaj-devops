<?php

namespace App\Jobs;

use App\Events\OutboxEventBroadcast;
use App\Models\OutboxEvent;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Broadcasts unpublished outbox rows in order and marks them sent (Plan §48.6).
 * If the realtime server is down nothing is lost: rows stay unpublished,
 * clients keep catching up over HTTP, and the next run retries.
 */
class PublishOutboxEvents implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const BATCH = 200;

    public function __construct()
    {
        // Own queue so live updates never wait behind long agent/AI jobs on 'default'.
        $this->onQueue('realtime');
    }

    public function handle(): void
    {
        // One publisher at a time keeps per-stream ordering. Wait for the lock rather than skip:
        // the holder may have queried before our event committed (the 10s sweep is only a backstop).
        try {
            Cache::lock('eaj:outbox-publisher', 60)->block(10, fn () => $this->publishPending());
        } catch (LockTimeoutException) {
            // A long-running publisher still holds it; the scheduled sweep picks up what is left.
        }
    }

    private function publishPending(): void
    {
        OutboxEvent::query()
            ->whereNull('published_at')
            ->with('actor:id,name')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get()
            ->each(function (OutboxEvent $event) {
                try {
                    broadcast(new OutboxEventBroadcast($event));
                } catch (Throwable $e) {
                    $event->forceFill([
                        'publish_attempts' => $event->publish_attempts + 1,
                        'last_publish_error' => mb_substr($e->getMessage(), 0, 1000),
                    ])->save();

                    // Stop at the first failure so later events are not delivered out of order.
                    return false;
                }

                $event->forceFill([
                    'published_at' => now(),
                    'publish_attempts' => $event->publish_attempts + 1,
                    'last_publish_error' => null,
                ])->save();
            });
    }
}
