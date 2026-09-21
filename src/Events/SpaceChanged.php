<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * The broadcast form of SpaceAdvanced.
 *
 * Separate from the event it follows, so a host that only wants the webhook -
 * or nothing - is not made to configure a broadcaster. Which broadcaster is not
 * this package's business either: Laravel already abstracts Reverb, Pusher,
 * Ably and the rest behind one interface, so implementing ShouldBroadcast is
 * the whole of being driver-agnostic.
 *
 * Private, always. The channel name is the tenant boundary.
 */
class SpaceChanged implements ShouldBroadcast
{
    public function __construct(public readonly string $space, public readonly int $watermark) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('sync.'.$this->space);
    }

    /** Named rather than inferred from the class, so moving the class does not break every client. */
    public function broadcastAs(): string
    {
        return 'space.advanced';
    }

    /**
     * @return array{watermark: int}
     *
     * The watermark and nothing else. A subscriber learns that there is
     * something new and how far it goes; what it may actually read is decided
     * when it asks.
     */
    public function broadcastWith(): array
    {
        return ['watermark' => $this->watermark];
    }
}
