<?php

namespace App\Events;

use App\Models\OutboxEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * The socket-side copy of a persisted outbox event. Clients receive the same
 * shape here as from the catch-up API, so both paths reconcile identically.
 */
class OutboxEventBroadcast implements ShouldBroadcastNow
{
    public function __construct(public readonly OutboxEvent $event) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->event->organization_id) {
            $channels[] = new PrivateChannel("organization.{$this->event->organization_id}");
        }

        if ($this->event->project_id) {
            $channels[] = new PrivateChannel("project.{$this->event->project_id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'outbox.event';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->event->toClientPayload();
    }
}
