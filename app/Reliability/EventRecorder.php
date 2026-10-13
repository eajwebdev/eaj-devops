<?php

namespace App\Reliability;

use App\Jobs\PublishOutboxEvents;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Transactional outbox writer (Plan §48.6).
 *
 * Call it inside the same transaction as the state change it describes: if
 * the transaction rolls back, the event never existed. Each stream has a
 * gapless, monotonic sequence so clients can detect gaps and catch up.
 */
class EventRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $type,
        array $payload = [],
        ?Project $project = null,
        ?Organization $organization = null,
        ?User $actor = null,
    ): OutboxEvent {
        $organizationId = $project?->organization_id ?? $organization?->getKey();
        $stream = match (true) {
            $project !== null => $project->eventStream(),
            $organizationId !== null => "organization.{$organizationId}",
            default => 'system',
        };

        $event = DB::transaction(function () use ($type, $payload, $project, $organizationId, $actor, $stream) {
            return OutboxEvent::forceCreate([
                'stream' => $stream,
                'sequence' => $this->nextSequence($stream),
                'organization_id' => $organizationId,
                'project_id' => $project?->getKey(),
                'type' => $type,
                'payload' => $payload,
                'actor_user_id' => $actor?->getKey() ?? auth()->id(),
                'occurred_at' => now(),
            ]);
        });

        // Publishing happens only once the surrounding transaction has committed.
        PublishOutboxEvents::dispatch()->afterCommit();

        return $event;
    }

    private function nextSequence(string $stream): int
    {
        DB::table('event_streams')->insertOrIgnore(['stream' => $stream, 'last_sequence' => 0]);

        $current = (int) DB::table('event_streams')
            ->where('stream', $stream)
            ->lockForUpdate()
            ->value('last_sequence');

        DB::table('event_streams')->where('stream', $stream)->update(['last_sequence' => $current + 1]);

        return $current + 1;
    }
}
