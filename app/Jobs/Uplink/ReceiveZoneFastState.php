<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Actions\Uplink\StoreZoneState;
use App\Enums\UplinkMessageType;
use App\Services\Uplink\UplinkMessageReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** `client-zone.fast`: latest plants and building states of a zone; never acked. */
final class ReceiveZoneFastState implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $frame) {}

    public function handle(UplinkMessageReader $reader, StoreZoneState $storeState): void
    {
        $message = $reader->read($this->frame, UplinkMessageType::ZoneFastState);

        if ($message === null || ! is_string($message['colonyId'] ?? null) || ! is_string($message['zoneId'] ?? null)) {
            return;
        }

        $storeState->handle('fast', $message);
    }
}
