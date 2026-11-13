<?php

declare(strict_types=1);

namespace App\Events\Uplink;

use App\Uplink\UplinkMessageType;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Acknowledges a stored reliable message to the mod, which stops resending
 * it. Broadcast from the worker (it has no socket of its own) on the data
 * channel the mod listens to; the frame is the `uplink.ack` the mod parses.
 */
final class UplinkMessageAccepted implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly string $id) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel(UplinkMessageType::DATA_CHANNEL)];
    }

    public function broadcastAs(): string
    {
        return 'uplink.ack';
    }

    /** @return array{id: string} */
    public function broadcastWith(): array
    {
        return ['id' => $this->id];
    }
}
