<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Models\Colony;
use App\Models\Zone;
use App\Models\ZoneVersion;

/**
 * The critters of a zone, derived: the mod sends critters for the whole colony only, so a
 * critter belongs to a zone when its cell is in the zone's latest cell set and it is in the
 * zone's world. Derived, not read: callers must say so.
 */
final class ZoneCritters
{
    /**
     * @return list<array<string, mixed>> the colony critter rows, as the mod sent them
     */
    public static function in(Zone $zone, Colony $colony): array
    {
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
