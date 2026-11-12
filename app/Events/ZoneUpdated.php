<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells open pages that a zone changed so they reload it. Queued on purpose:
 * it is dispatched from the Reverb process, which must never broadcast
 * synchronously.
 */
final class ZoneUpdated implements ShouldBroadcast
{
    use Dispatchable;

    /**
     * @param  string  $kind  `list`, `layout`, `slow` or `fast`
     */
    public function __construct(
        public readonly string $zoneGuid,
        public readonly string $colonyGuid,
        public readonly string $kind,
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('uplink')];
    }

    public function broadcastAs(): string
    {
        return 'zone.updated';
    }

    /** @return array{zoneGuid: string, colonyGuid: string, kind: string} */
    public function broadcastWith(): array
    {
        return ['zoneGuid' => $this->zoneGuid, 'colonyGuid' => $this->colonyGuid, 'kind' => $this->kind];
    }
}
