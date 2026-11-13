<?php

declare(strict_types=1);

namespace App\Listeners\Uplink;

use App\Actions\Uplink\SendUplinkCommand;
use App\Events\Uplink\ZoneStateStored;
use App\Models\UplinkCommand;
use App\Models\Zone;

/**
 * Asks the mod to resend a zone whose layout never arrived. Waits 30 s after
 * the zone was created, so the mod's own resend of unacked layouts (15 s)
 * gets its chance first after a reconnect, and sends one `zone.resync` at a
 * time per zone.
 */
final class ResyncZoneWithoutLayout
{
    private const int GRACE_SECONDS = 30;

    public function __construct(private readonly SendUplinkCommand $sendCommand) {}

    public function handle(ZoneStateStored $event): void
    {
        $zone = Zone::query()
            ->where('colony_guid', $event->colonyGuid)
            ->where('zone_guid', $event->zoneGuid)
            ->first();

        if ($zone === null || $zone->layout !== null || $zone->created_at?->gt(now()->subSeconds(self::GRACE_SECONDS)) !== false) {
            return;
        }

        UplinkCommand::expireStale();

        $pending = UplinkCommand::query()
            ->where('colony_guid', $event->colonyGuid)
            ->where('type', 'zone.resync')
            ->where('status', UplinkCommand::PENDING)
            ->where('payload->zoneId', $event->zoneGuid)
            ->exists();

        if (! $pending) {
            $this->sendCommand->handle($event->colonyGuid, 'zone.resync', ['zoneId' => $event->zoneGuid]);
        }
    }
}
