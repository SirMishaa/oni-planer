<?php

declare(strict_types=1);

use App\Models\Colony;
use App\Models\Zone;
use App\Models\ZoneVersion;
use App\Services\Zones\ZoneCritters;

it('keeps the colony critters standing on a cell of the zone in its world', function (): void {
    $zone = Zone::factory()->create(['world_id' => 0]);
    ZoneVersion::factory()->create(['zone_id' => $zone->id, 'version' => 1, 'cells' => [10, 11]]);
    $colony = Colony::factory()->create(['state' => ['critters' => [
        ['id' => 'Crab', 'worldId' => 0, 'cell' => 10],
        ['id' => 'Crab', 'worldId' => 1, 'cell' => 11],
        ['id' => 'Hatch', 'worldId' => 0, 'cell' => 99],
    ]]]);

    expect(ZoneCritters::in($zone, $colony))->toBe([['id' => 'Crab', 'worldId' => 0, 'cell' => 10]]);
});

it('reads the latest zone version', function (): void {
    $zone = Zone::factory()->create(['world_id' => 0]);
    ZoneVersion::factory()->create(['zone_id' => $zone->id, 'version' => 1, 'cells' => [10]]);
    ZoneVersion::factory()->create(['zone_id' => $zone->id, 'version' => 2, 'cells' => [20]]);
    $colony = Colony::factory()->create(['state' => ['critters' => [
        ['id' => 'Crab', 'worldId' => 0, 'cell' => 10],
        ['id' => 'Hatch', 'worldId' => 0, 'cell' => 20],
    ]]]);

    expect(array_column(ZoneCritters::in($zone, $colony), 'id'))->toBe(['Hatch']);
});

it('finds no critter when the colony sent none', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();

    expect(ZoneCritters::in($zone, Colony::factory()->create(['state' => ['schema' => 1]])))->toBe([]);
});

it('prefers the zone fast section over the colony snapshot', function (): void {
    $zone = Zone::factory()->create(['world_id' => 0, 'fast_state' => ['schema' => 1, 'critters' => [
        ['instanceId' => '1', 'prefabId' => 'Pacu', 'cell' => 10, 'x' => 1.0, 'y' => 2.0, 'tame' => true],
    ]]]);
    ZoneVersion::factory()->create(['zone_id' => $zone->id, 'version' => 1, 'cells' => [10, 11]]);
    $colony = Colony::factory()->create(['state' => ['critters' => [['id' => 'Crab', 'worldId' => 0, 'cell' => 11]]]]);

    expect(ZoneCritters::in($zone, $colony))->toBe([['id' => 'Pacu', 'cell' => 10, 'worldId' => 0, 'tame' => true]]);

    $zone->update(['fast_state' => ['schema' => 1, 'critters' => []]]);

    expect(ZoneCritters::in($zone->refresh(), $colony))->toBe([]);
});
