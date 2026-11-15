<?php

declare(strict_types=1);

use App\Data\GameRules\InventoryItemData;
use App\Models\Zone;
use App\Services\Zones\ZoneInventory;

/** @param  array<string, mixed>  $fast */
function inventoryZone(array $fast): Zone
{
    return Zone::factory()->make(['id' => 61, 'fast_state' => ['schema' => 1, ...$fast]]);
}

/** @param  list<InventoryItemData>  $items */
function stockOf(array $items, string $resource): float
{
    return array_sum(array_map(fn (InventoryItemData $item): float => $item->resource === $resource ? $item->kg : 0.0, $items));
}

it('sums feeder storage per resource', function (): void {
    $items = ZoneInventory::of(inventoryZone(['buildings' => [
        ['instanceId' => '9001', 'prefabId' => 'CreatureFeeder', 'storage' => [['id' => 'Algae', 'mass' => 300.0], ['id' => 'Algae', 'mass' => 6.0]]],
        ['instanceId' => '9002', 'prefabId' => 'CreatureFeeder', 'storage' => [['id' => 'Algae', 'mass' => 150.0], ['id' => 'Sand', 'mass' => 100.0]]],
        ['instanceId' => '9003', 'prefabId' => 'StorageLocker', 'storage' => null],
        ['instanceId' => '9004', 'prefabId' => 'StorageLocker', 'storage' => ['nope', ['mass' => 5.0], ['id' => 'Kelp', 'mass' => 'heavy']]],
    ]]));

    expect($items)->toHaveCount(4)
        ->and($items[0]->toArray())->toBe(['resource' => 'Algae', 'kg' => 306.0, 'placeKind' => 'building', 'placeId' => '9001', 'zoneId' => 61])
        ->and(stockOf($items, 'Algae'))->toBe(456.0)
        ->and(stockOf($items, 'Sand'))->toBe(100.0)
        ->and(stockOf($items, 'Kelp'))->toBe(0.0);
});

it('adds grown edible plants', function (): void {
    $items = ZoneInventory::of(inventoryZone(['plants' => [
        ['id' => 'SeaLettuce', 'instanceId' => '4501', 'cropId' => 'Lettuce', 'cropUnits' => 12.0, 'grown' => true],
        ['id' => 'SeaLettuce', 'instanceId' => '4502', 'cropId' => 'Lettuce', 'cropUnits' => 12.0, 'grown' => false],
        ['id' => 'GardenDecorPlant', 'instanceId' => '4503', 'cropId' => null, 'cropUnits' => null, 'grown' => true],
    ]]));

    expect($items)->toHaveCount(2)
        ->and($items[0]->toArray())->toBe(['resource' => 'Lettuce', 'kg' => 12.0, 'placeKind' => 'plant', 'placeId' => '4501', 'zoneId' => 61])
        ->and($items[1]->kg)->toBe(0.0);
});

it('leaves out border passages and has nothing when no state was read', function (): void {
    expect(ZoneInventory::of(inventoryZone(['buildings' => [
        ['instanceId' => '9101', 'prefabId' => 'Door', 'storage' => [['id' => 'Algae', 'mass' => 10.0]]],
    ]])))->toBe([])
        ->and(ZoneInventory::of(Zone::factory()->make(['fast_state' => null])))->toBe([]);
});

it('knows whether the building storages were read', function (): void {
    expect(ZoneInventory::storageKnown(inventoryZone(['buildings' => []])))->toBeTrue()
        ->and(ZoneInventory::storageKnown(inventoryZone(['buildings' => null])))->toBeFalse()
        ->and(ZoneInventory::storageKnown(Zone::factory()->make(['fast_state' => null])))->toBeFalse();
});
