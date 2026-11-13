<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Actions\Uplink\StoreZoneLayout;
use App\Enums\UplinkMessageType;
use App\Services\Uplink\UplinkMessageReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** `client-zone.layout`: stores a zone's cells, tiles and buildings; not acked for a zone the list has not created yet (the mod resends it). */
final class ReceiveZoneLayout implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $frame) {}

    public function handle(UplinkMessageReader $reader, StoreZoneLayout $storeLayout): void
    {
        $message = $reader->read($this->frame, UplinkMessageType::ZoneLayout);

        if ($message === null || ! is_string($message['id'] ?? null) || ! is_string($message['colonyId'] ?? null)
            || ! is_string($message['zoneId'] ?? null) || ! is_array($message['cells'] ?? null)) {
            return;
        }

        if ($storeLayout->handle($message)) {
            $reader->acknowledge($message);
        }
    }
}
