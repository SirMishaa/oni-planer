<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Data\BuildingCapacityData;
use App\Data\BuildingSettingsData;
use App\Models\Zone;

/** The filter and capacity of the buildings of a zone, from its fast state. Free of database access. */
final class ZoneBuildingSettings
{
    /** @var list<string> */
    private const array CAPACITY_UNITS = ['kg', 'critters'];

    /** @param  array<string, mixed>  $building one item of the fast `buildings` section */
    public static function of(array $building): ?BuildingSettingsData
    {
        $settings = $building['settings'] ?? null;
        if (! is_array($settings)) {
            return null;
        }

        $filter = is_array($settings['filter'] ?? null)
            ? array_values(array_filter($settings['filter'], is_string(...)))
            : null;
        $capacity = self::capacityOf($settings['capacity'] ?? null);

        if ($filter === null && $capacity === null) {
            return null;
        }

        return new BuildingSettingsData($filter, $capacity);
    }

    /**
     * The filter of each feeder by instance id; null when no feeder carries settings (an older mod),
     * where a null entry means the feeder has no filter.
     *
     * @return array<string, list<string>|null>|null
     */
    public static function feederFilters(Zone $zone): ?array
    {
        $buildings = $zone->fast_state['buildings'] ?? null;

        $filters = [];
        $known = false;
        foreach (is_array($buildings) ? $buildings : [] as $building) {
            if (! is_array($building) || ! in_array($building['prefabId'] ?? null, ZoneInventory::FEEDER_PREFABS, true)) {
                continue;
            }

            $settings = self::of($building);
            $known = $known || $settings !== null;
            $filters[(string) ($building['instanceId'] ?? '')] = $settings?->filter;
        }

        return $known ? $filters : null;
    }

    private static function capacityOf(mixed $capacity): ?BuildingCapacityData
    {
        if (! is_array($capacity) || ! in_array($capacity['unit'] ?? null, self::CAPACITY_UNITS, true)) {
            return null;
        }

        $number = fn (mixed $value): ?float => is_numeric($value) && is_finite((float) $value) ? (float) $value : null;

        return new BuildingCapacityData($number($capacity['limit'] ?? null), $number($capacity['max'] ?? null), $number($capacity['stored'] ?? null), $capacity['unit']);
    }
}
