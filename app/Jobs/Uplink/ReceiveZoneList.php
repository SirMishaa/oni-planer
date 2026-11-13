<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Actions\Uplink\SyncZoneList;
use App\Uplink\UplinkMessageType;

/** `client-zone.list`: creates, updates and archives the colony's zones. */
final class ReceiveZoneList extends UplinkMessageJob
{
    public static function type(): UplinkMessageType
    {
        return UplinkMessageType::ZoneList;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function process(array $message): bool
    {
        if (! is_string($message['id'] ?? null) || ! is_string($message['colonyId'] ?? null) || ! is_array($message['zones'] ?? null)) {
            return false;
        }

        app(SyncZoneList::class)->handle($message);

        return true;
    }
}
