<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Actions\Uplink\StoreZoneState;
use App\Uplink\UplinkMessageType;

/** `client-zone.slow`: latest terrain and temperatures of a zone. */
final class ReceiveZoneSlowState extends UplinkMessageJob
{
    public static function type(): UplinkMessageType
    {
        return UplinkMessageType::ZoneSlowState;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function process(array $message): bool
    {
        if (! is_string($message['colonyId'] ?? null) || ! is_string($message['zoneId'] ?? null)) {
            return false;
        }

        app(StoreZoneState::class)->handle('slow', $message);

        return true;
    }
}
