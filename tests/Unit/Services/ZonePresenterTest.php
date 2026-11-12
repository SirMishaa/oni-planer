<?php

declare(strict_types=1);

use App\Data\ZoneEntityData;
use App\Data\ZoneTileData;
use App\Models\Element;
use App\Models\PrefabName;
use App\Models\Zone;
use App\Services\Zones\ZonePresenter;
use Database\Factories\ZoneFactory;
use Illuminate\Support\Facades\File;

function zoneCell(int $x, int $y): int
{
    return $y * ZoneFactory::GRID_WIDTH + $x;
}

/** @param  array<string, mixed>  $attributes */
function presentZone(array $attributes = []): App\Data\ZoneShowData
{
    return app(ZonePresenter::class)->present(Zone::factory()->sodicaneFarm()->create($attributes));
}

beforeEach(function (): void {
    PrefabName::factory()->create(['prefab_id' => 'SaltySticksPlant', 'name_key' => 'STRINGS.CREATURES.SPECIES.SALTYSTICKSPLANT.NAME']);
    PrefabName::factory()->create(['prefab_id' => 'FarmStation', 'name_key' => 'STRINGS.BUILDINGS.PREFABS.FARMSTATION.NAME']);
});

it('places the 13 sodicanes of the farm from their cells', function (): void {
    $shown = presentZone();

    $plants = array_values(array_filter($shown->entities, fn (ZoneEntityData $entity): bool => $entity->kind === 'plant'));

    expect($plants)->toHaveCount(13)
        ->and($shown->counts->plants)->toBe(13)
        ->and($shown->counts->buildings)->toBe(1)
        ->and(collect($plants)->map(fn (ZoneEntityData $plant): array => [$plant->x, $plant->y])->sort()->values()->all())
        ->toBe(array_map(fn (int $x): array => [$x, 201], range(100, 112)))
        ->and($plants[0]->name)->toBe('Sodicane')
        ->and($plants[0]->state)->toBe('grown')
        ->and($plants[0]->growth)->toBe(1.0)
        ->and($plants[1]->state)->toBe('growing')
        ->and($plants[1]->growth)->toBe(0.75)
        ->and($plants[1]->secondsUntilHarvest)->toBe(1500.0)
        ->and($plants[1]->autoHarvest)->toBeTrue()
        ->and($shown->map?->plants)->toHaveCount(13)
        ->and($shown->map?->plants[0]->x)->toBe(100)
        ->and($shown->map?->plants[0]->y)->toBe(201)
        ->and($shown->map?->zoneCells)->toHaveCount(40)
        ->and($shown->map?->zoneCells[0])->toBe([100, 201]);
});

it('reports the farm station short of fertilizer as the one problem, first', function (): void {
    $shown = presentZone();

    expect($shown->problems)->toHaveCount(1)
        ->and($shown->counts->problems)->toBe(1)
        ->and($shown->entities[0])->toBe($shown->problems[0])
        ->and($shown->problems[0]->kind)->toBe('building')
        ->and($shown->problems[0]->name)->toBe('Farm Station')
        ->and($shown->problems[0]->state)->toBe('operational')
        ->and($shown->problems[0]->problems)->toBe(['Insufficient Resources: Fertilizer'])
        ->and([$shown->problems[0]->x, $shown->problems[0]->y])->toBe([114, 201]);
});

it('places the buildings of the layout by their footprint', function (): void {
    $building = presentZone()->map?->buildings[0];

    expect([$building?->x, $building?->y, $building?->width, $building?->height])->toBe([114, 201, 2, 2])
        ->and($building?->name)->toBe('Farm Station')
        ->and($building?->orientation)->toBe('Neutral');
});

it('colours the terrain of the box with the element ui colours', function (): void {
    Element::factory()->create(['element_id' => 'Dirt', 'ui_color' => 0x8A5A2B]);
    Element::factory()->create(['element_id' => 'Oxygen', 'ui_color' => null]);

    $terrain = presentZone()->map?->terrain ?? [];

    expect($terrain)->toHaveCount(88)
        ->and([$terrain[0]->x, $terrain[0]->y, $terrain[0]->elementId, $terrain[0]->colour])->toBe([99, 200, 'Dirt', '#8a5a2b'])
        ->and([$terrain[22]->x, $terrain[22]->y, $terrain[22]->elementId, $terrain[22]->colour])->toBe([99, 201, 'Oxygen', null])
        ->and($terrain[22]->mass)->toBe(1.5)
        ->and($terrain[22]->temperature)->toBe(295.15);
});

it('masks each tile with its same-prefab neighbours', function (): void {
    $masks = collect(presentZone()->map?->tiles)->mapWithKeys(fn (ZoneTileData $tile): array => [$tile->x => $tile->connections]);

    // left = 1, right = 2, up = 4, down = 8
    expect($masks[100])->toBe(2)
        ->and($masks[101])->toBe(3)
        ->and($masks[112])->toBe(1)
        ->and($masks[113])->toBe(2)
        ->and($masks[119])->toBe(1);
});

it('connects stacked tiles and never across the grid edge', function (): void {
    $edge = ZoneFactory::GRID_WIDTH - 1;
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update(['layout' => [...$zone->layout, 'tiles' => [
        ['cell' => zoneCell(10, 10), 'prefabId' => 'Tile'],
        ['cell' => zoneCell(10, 11), 'prefabId' => 'Tile'],
        ['cell' => zoneCell(10, 12), 'prefabId' => 'MetalTile'],
        ['cell' => zoneCell($edge, 20), 'prefabId' => 'Tile'],
        ['cell' => zoneCell(0, 21), 'prefabId' => 'Tile'],
    ]]]);

    $tiles = app(ZonePresenter::class)->present($zone)->map?->tiles ?? [];

    expect(array_map(fn (ZoneTileData $tile): array => [$tile->x, $tile->y, $tile->connections], $tiles))->toBe([
        [10, 10, 4],
        [10, 11, 8],
        [10, 12, 0],
        [$edge, 20, 0],
        [0, 21, 0],
    ]);
});

it('keeps sections the game could not read unknown', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update([
        'layout' => [...$zone->layout, 'tiles' => null, 'buildings' => null],
        'slow_state' => [...$zone->slow_state, 'palette' => null, 'element' => null],
        'fast_state' => [...$zone->fast_state, 'plants' => null, 'buildings' => null],
    ]);

    $shown = app(ZonePresenter::class)->present($zone);

    expect($shown->map?->tiles)->toBeNull()
        ->and($shown->map?->buildings)->toBeNull()
        ->and($shown->map?->plants)->toBeNull()
        ->and($shown->map?->terrain)->toHaveCount(88)
        ->and($shown->map?->terrain[0]->elementId)->toBeNull()
        ->and($shown->map?->terrain[0]->colour)->toBeNull()
        ->and($shown->map?->terrain[0]->mass)->toBe(1.5)
        ->and($shown->entities)->toBe([])
        ->and($shown->plantsKnown)->toBeFalse()
        ->and($shown->buildingStatesKnown)->toBeFalse()
        ->and(app(ZonePresenter::class)->problemCount($zone))->toBe(0);
});

it('has no terrain when no layer of the slow state was read', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update(['slow_state' => [...$zone->slow_state, 'palette' => null, 'element' => null, 'mass' => null, 'temperature' => null]]);

    expect(app(ZonePresenter::class)->present($zone)->map?->terrain)->toBeNull();
});

it('presents a zone known only from the zone list', function (): void {
    $zone = Zone::factory()->create(['orphaned' => true]);

    $shown = app(ZonePresenter::class)->present($zone);

    expect($shown->map)->toBeNull()
        ->and($shown->entities)->toBe([])
        ->and($shown->zone->orphaned)->toBeTrue()
        ->and($shown->zone->layoutAt)->toBeNull()
        ->and($shown->plantsKnown)->toBeFalse()
        ->and(app(ZonePresenter::class)->problemCount($zone))->toBeNull();
});

it('reports wilting plants and buildings the game stopped', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();
    $plants = $zone->fast_state['plants'];
    $plants[3] = [...$plants[3], 'wilting' => true, 'secondsUntilHarvest' => null];
    $zone->update(['fast_state' => [...$zone->fast_state, 'plants' => $plants, 'buildings' => [
        ['instanceId' => '8001', 'prefabId' => 'Unknown', 'operational' => false, 'active' => false, 'statusItems' => null, 'storage' => null],
        ['instanceId' => '8002', 'prefabId' => 'Unknown', 'operational' => false, 'active' => false, 'statusItems' => [['id' => 'NeedPower', 'name' => null]], 'storage' => null],
    ]]]);

    $presenter = app(ZonePresenter::class);
    $problems = collect($presenter->present($zone)->problems)->keyBy(fn (ZoneEntityData $entity): string => (string) $entity->instanceId);

    expect($problems)->toHaveCount(3)
        ->and($problems['5103']->state)->toBe('wilting')
        ->and($problems['5103']->problems)->toBe(['Wilting'])
        ->and($problems['5103']->growth)->toBeNull()
        ->and($problems['8001']->state)->toBe('not_operational')
        ->and($problems['8001']->problems)->toBe(['Not operational'])
        ->and($problems['8002']->problems)->toBe(['NeedPower'])
        ->and($presenter->problemCount($zone))->toBe(3);
});

it('falls back to the prefab id and no position for what the data does not know', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update(['fast_state' => [...$zone->fast_state, 'plants' => [['id' => 'ModdedPlant', 'grown' => false], 'garbage'], 'buildings' => [
        ['prefabId' => 'ModdedBuilding', 'operational' => null, 'statusItems' => [['id' => 'Unlisted', 'name' => 'Fine']]],
    ]]]);

    $entities = app(ZonePresenter::class)->present($zone)->entities;

    expect($entities)->toHaveCount(2)
        ->and([$entities[0]->name, $entities[0]->sprite, $entities[0]->x, $entities[0]->growth, $entities[0]->autoHarvest])->toBe(['ModdedPlant', null, null, null, null])
        ->and([$entities[1]->name, $entities[1]->state, $entities[1]->x, $entities[1]->problems])->toBe(['ModdedBuilding', 'operational', null, []]);
});

it('links sprites only when the asset file exists', function (): void {
    $assets = sys_get_temp_dir().'/zone-assets-'.uniqid();
    File::ensureDirectoryExists("{$assets}/plants");
    File::ensureDirectoryExists("{$assets}/buildings");
    File::put("{$assets}/plants/SaltySticksPlant.webp", '');
    File::put("{$assets}/buildings/FarmStation.webp", '');
    config(['game.assets_path' => $assets]);

    $shown = presentZone();

    expect($shown->map?->plants[0]->sprite)->toBe('/assets/game/plants/SaltySticksPlant.webp')
        ->and($shown->map?->buildings[0]->sprite)->toBe('/assets/game/buildings/FarmStation.webp')
        ->and($shown->problems[0]->sprite)->toBe('/assets/game/buildings/FarmStation.webp');

    File::deleteDirectory($assets);
});
