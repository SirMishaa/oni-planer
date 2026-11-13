<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Actions\Uplink\StoreZoneLayout;
use App\Uplink\UplinkMessageType;

/** `client-zone.layout`: stores a zone's cells, tiles and buildings; not acked for a zone the list has not created yet. */
final class ReceiveZoneLayout extends UplinkMessageJob
{
    public static function type(): UplinkMessageType
    {
        return UplinkMessageType::ZoneLayout;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function process(array $message): bool
    {
        if (! is_string($message['id'] ?? null) || ! is_string($message['colonyId'] ?? null)
            || ! is_string($message['zoneId'] ?? null) || ! is_array($message['cells'] ?? null)) {
            return false;
        }

        return app(StoreZoneLayout::class)->handle($message);
    }
}
