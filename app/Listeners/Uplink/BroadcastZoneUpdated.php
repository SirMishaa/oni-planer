<?php

declare(strict_types=1);

namespace App\Listeners\Uplink;

use App\Events\Uplink\ZoneLayoutStored;
use App\Events\Uplink\ZoneListSynced;
use App\Events\Uplink\ZoneStateStored;
use App\Events\ZoneUpdated;
use Illuminate\Support\Facades\Cache;

/**
 * Tells open pages a zone changed. Fast states arrive every few seconds per
 * zone: their update is sent at most once per zone every 2 seconds.
 */
final class BroadcastZoneUpdated
{
    private const int FAST_BROADCAST_SECONDS = 2;

    public function handle(ZoneListSynced|ZoneLayoutStored|ZoneStateStored $event): void
    {
        match (true) {
            $event instanceof ZoneListSynced => $this->listSynced($event),
            $event instanceof ZoneLayoutStored => ZoneUpdated::dispatch($event->zoneGuid, $event->colonyGuid, 'layout'),
            default => $this->stateStored($event),
        };
    }

    private function listSynced(ZoneListSynced $event): void
    {
        foreach ($event->zoneGuids as $zoneGuid) {
            ZoneUpdated::dispatch($zoneGuid, $event->colonyGuid, 'list');
        }
    }

    private function stateStored(ZoneStateStored $event): void
    {
        if ($event->kind === 'fast' && ! Cache::lock("zone-updated:{$event->zoneGuid}", self::FAST_BROADCAST_SECONDS)->get()) {
            return;
        }

        ZoneUpdated::dispatch($event->zoneGuid, $event->colonyGuid, $event->kind);
    }
}
