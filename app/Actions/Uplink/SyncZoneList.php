<?php

declare(strict_types=1);

namespace App\Actions\Uplink;

use App\Events\ZoneUpdated;
use App\Models\Zone;

/**
 * Mirrors the zone list of one colony: upserts the listed zones (restoring
 * archived ones) and archives the colony's zones that are absent from it.
 * Zones are never deleted.
 */
final class SyncZoneList
{
    /**
     * @param  array{colonyId: string, zones: list<array{zoneId: string, name: string, worldId: int, version: int, cellCount: int, orphaned: bool}>}  $message
     */
    public function handle(array $message): void
    {
        $colonyGuid = $message['colonyId'];
        $listed = [];

        foreach ($message['zones'] as $entry) {
            $listed[] = $entry['zoneId'];

            Zone::query()->updateOrCreate(
                ['colony_guid' => $colonyGuid, 'zone_guid' => $entry['zoneId']],
                [
                    'name' => $entry['name'],
                    'world_id' => (int) $entry['worldId'],
                    'version' => (int) $entry['version'],
                    'cell_count' => (int) $entry['cellCount'],
                    'orphaned' => (bool) $entry['orphaned'],
                    'archived_at' => null,
                ],
            );
        }

        $missing = Zone::query()
            ->where('colony_guid', $colonyGuid)
            ->whereNull('archived_at')
            ->whereNotIn('zone_guid', $listed)
            ->pluck('zone_guid');

        if ($missing->isNotEmpty()) {
            Zone::query()
                ->where('colony_guid', $colonyGuid)
                ->whereIn('zone_guid', $missing)
                ->update(['archived_at' => now()]);
        }

        foreach ([...$listed, ...$missing->all()] as $zoneGuid) {
            ZoneUpdated::dispatch($zoneGuid, $colonyGuid, 'list');
        }
    }
}
