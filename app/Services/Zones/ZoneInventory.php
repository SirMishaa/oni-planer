<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Data\GameRules\InventoryItemData;
use App\Models\Zone;

/**
 * What a zone holds, from its fast state: the storage of each content building (border passages
 * left out), then the crop of each plant (its harvest when grown, else 0 kg). Free of database
 * access. A crop's `cropUnits` is the game's yield: kg for an element crop, units of about 1 kg
 * for a food item.
 */
final class ZoneInventory
{
    /** @return list<InventoryItemData> */
    public static function of(Zone $zone): array
    {
        return [...self::storages($zone), ...self::crops($zone)];
    }

    /** False until a fast state carried the building states: the stock is then unknown, not empty. */
    public static function storageKnown(Zone $zone): bool
    {
        return is_array($zone->fast_state['buildings'] ?? null);
    }

    /** @return list<InventoryItemData> one item per building and resource */
    private static function storages(Zone $zone): array
    {
        $buildings = $zone->fast_state['buildings'] ?? null;
        [$content] = ZoneProblems::splitPassages(is_array($buildings) ? array_values(array_filter($buildings, is_array(...))) : null);

        $items = [];
        foreach ($content ?? [] as $building) {
            $totals = [];
            foreach (is_array($building['storage'] ?? null) ? $building['storage'] : [] as $stored) {
                if (is_array($stored) && is_string($stored['id'] ?? null) && $stored['id'] !== '') {
                    $totals[$stored['id']] = ($totals[$stored['id']] ?? 0.0) + (is_numeric($stored['mass'] ?? null) ? (float) $stored['mass'] : 0.0);
                }
            }
            foreach ($totals as $resource => $kg) {
                $items[] = new InventoryItemData($resource, $kg, 'building', (string) ($building['instanceId'] ?? ''), $zone->id);
            }
        }

        return $items;
    }

    /** @return list<InventoryItemData> */
    private static function crops(Zone $zone): array
    {
        $plants = $zone->fast_state['plants'] ?? null;

        $items = [];
        foreach (is_array($plants) ? $plants : [] as $plant) {
            if (! is_array($plant) || ! is_string($plant['cropId'] ?? null) || $plant['cropId'] === '') {
                continue;
            }

            $grown = ($plant['grown'] ?? false) === true;
            $units = is_numeric($plant['cropUnits'] ?? null) ? (float) $plant['cropUnits'] : 0.0;
            $items[] = new InventoryItemData($plant['cropId'], $grown ? $units : 0.0, 'plant', (string) ($plant['instanceId'] ?? ''), $zone->id);
        }

        return $items;
    }
}
