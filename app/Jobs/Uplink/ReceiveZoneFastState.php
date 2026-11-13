<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Actions\Uplink\StoreZoneState;
use App\Uplink\UplinkMessageType;

/** `client-zone.fast`: latest plants and building states of a zone. */
final class ReceiveZoneFastState extends UplinkMessageJob
{
    public static function type(): UplinkMessageType
    {
        return UplinkMessageType::ZoneFastState;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function process(array $message): bool
    {
        if (! is_string($message['colonyId'] ?? null) || ! is_string($message['zoneId'] ?? null)) {
            return false;
        }

        app(StoreZoneState::class)->handle('fast', $message);

        return true;
    }
}
