<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Data\GameRules\InventoryItemData;
use App\Models\Zone;

/**
 * The food a zone's critters can reach, from its fast state: the storage of each feeder. Free of
 * database access.
 *
 * The game's `SolidConsumerMonitor.FindFood` looks in the storages of the creature feeders and at
 * loose items on the floor only. A storage locker or a building that consumes its own contents (an
 * algae terrarium) is never eaten from. Loose items are not in the wire, so the stock can
 * under-count. A crop still on its plant is not food for an item eater until it is harvested.
 */
final class ZoneInventory
{
    /**
     * Buildings critters eat from (the game's `CreatureFeeder` component); a mod feeder can be added here.
     *
     * @var list<string>
     */
    public const array FEEDER_PREFABS = ['CreatureFeeder', 'FishFeeder'];

    /** @return list<InventoryItemData> one item per feeder and resource */
    public static function of(Zone $zone): array
    {
        $buildings = $zone->fast_state['buildings'] ?? null;

        $items = [];
        foreach (is_array($buildings) ? $buildings : [] as $building) {
            if (! is_array($building) || ! in_array($building['prefabId'] ?? null, self::FEEDER_PREFABS, true)) {
                continue;
            }

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

    /** False until a fast state carried the building states: the stock is then unknown, not empty. */
    public static function storageKnown(Zone $zone): bool
    {
        return is_array($zone->fast_state['buildings'] ?? null);
    }
}
