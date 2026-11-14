<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Data\ZoneCritterData;
use App\Models\Colony;
use App\Models\Zone;
use App\Models\ZoneVersion;

/**
 * The critters of a zone. When the zone's fast state has a `critters` section they are read
 * from it. Older mod versions sent critters for the whole colony only: then a critter belongs
 * to a zone when its cell is in the zone's latest cell set and it is in the zone's world,
 * which is derived, not read.
 */
final class ZoneCritters
{
    /**
     * @return list<array<string, mixed>> the zone section mapped to id, cell, worldId and tame, else the colony critter rows as the mod sent them
     */
    public static function in(Zone $zone, Colony $colony): array
    {
        $section = ZoneCritterState::critters($zone);
        if ($section !== null) {
            return array_map(fn (ZoneCritterData $critter): array => [
                'id' => $critter->prefabId,
                'cell' => $critter->cell,
                'worldId' => $zone->world_id,
                'tame' => $critter->tame,
            ], $section);
        }

        $critters = $colony->state['critters'] ?? null;
        $cells = self::cells($zone);

        if (! is_array($critters) || $cells === []) {
            return [];
        }

        $inZone = array_flip($cells);

        return array_values(array_filter($critters, fn (mixed $critter): bool => is_array($critter)
            && is_int($critter['cell'] ?? null)
            && isset($inZone[$critter['cell']])
            && ($critter['worldId'] ?? null) === $zone->world_id));
    }

    /**
     * The zone's latest cell set (its newest version, else the layout's cells).
     *
     * @return list<int>
     */
    public static function cells(Zone $zone): array
    {
        $cells = ZoneVersion::query()->where('zone_id', $zone->id)->orderByDesc('version')->value('cells')
            ?? $zone->layout['cells']
            ?? [];

        return is_array($cells) ? array_values(array_filter($cells, is_int(...))) : [];
    }
}
