<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Tells the user's browsers that something of theirs changed: only what and why, never the data, which the
 * browser fetches through the API. So the event stays tiny whatever the content (Pusher takes up to 10 KB),
 * and the user's data does not pass through Pusher. Sent with App\Support\Realtime (docs/realtime.md).
 */
class EntityChanged implements ShouldBroadcastNow
{
    /**
     * @param  string  $type  What changed: "asset", later e.g. "listing"
     * @param  string  $id  Its public id (ulid)
     * @param  string  $reason  What happened, e.g. "metadata"
     */
    public function __construct(
        public User $user,
        public string $type,
        public string $id,
        public string $reason,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->user->ulid}");
    }

    public function broadcastAs(): string
    {
        return 'entity.changed';
    }

    /**
     * @return array{type: string, id: string, reason: string}
     */
    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'reason' => $this->reason];
    }
}
