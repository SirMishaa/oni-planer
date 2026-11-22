<?php

declare(strict_types=1);

namespace App\Listeners\Zones;

use App\Actions\Zones\ReconcileZonePlan;
use App\Events\Uplink\ZoneLayoutStored;
use App\Events\ZoneUpdated;
use App\Models\Zone;

/**
 * Follows the game on a zone plan each time its layout is stored, and tells open pages when the
 * plan changed. Broadcasts on its own: listener order is not guaranteed, so it cannot rely on the
 * layout broadcast coming after it.
 */
final readonly class ReconcilePlanOnLayout
{
    public function __construct(private ReconcileZonePlan $reconcile) {}

    public function handle(ZoneLayoutStored $event): void
    {
        $zone = Zone::query()
            ->where('colony_guid', $event->colonyGuid)
            ->where('zone_guid', $event->zoneGuid)
            ->whereHas('plan')
            ->first();

        if ($zone !== null && $this->reconcile->handle($zone)) {
            ZoneUpdated::dispatch($event->zoneGuid, $event->colonyGuid, 'plan');
        }
    }
}
