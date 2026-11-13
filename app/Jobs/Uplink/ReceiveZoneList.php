<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Actions\Uplink\SyncZoneList;
use App\Enums\UplinkMessageType;
use App\Services\Uplink\UplinkMessageReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** `client-zone.list`: creates, updates and archives the colony's zones; acked once stored. */
final class ReceiveZoneList implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $frame) {}

    public function handle(UplinkMessageReader $reader, SyncZoneList $syncZoneList): void
    {
        $message = $reader->read($this->frame, UplinkMessageType::ZoneList);

        if ($message === null || ! is_string($message['id'] ?? null) || ! is_string($message['colonyId'] ?? null) || ! is_array($message['zones'] ?? null)) {
            return;
        }

        // A malformed entry would fail on every resend: acknowledge and drop the whole list.
        if ($reader->entriesMatch($message['zones'], [
            'zoneId' => is_string(...), 'name' => is_string(...), 'worldId' => is_int(...), 'version' => is_int(...), 'cellCount' => is_int(...), 'orphaned' => is_bool(...),
        ])) {
            $syncZoneList->handle($message);
        } else {
            $reader->drop($message, UplinkMessageType::ZoneList, 'malformed zone entry');
        }

        $reader->acknowledge($message);
    }
}
