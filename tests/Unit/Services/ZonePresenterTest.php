<?php

declare(strict_types=1);

use App\Data\ZoneEntityData;
use App\Data\ZoneTerrainElementData;
use App\Data\ZoneTileData;
use App\Enums\BuildLocationRule;
use App\Enums\SubstanceTexture;
use App\Models\Building;
use App\Models\Critter;
use App\Models\CritterMorph;
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
        ->and($shown->mapPlants)->toHaveCount(13)
        ->and($shown->mapPlants[0]->x)->toBe(100)
        ->and($shown->mapPlants[0]->y)->toBe(201)
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
        ->and($building?->orientation)->toBe('Neutral')
        ->and($building?->hangsFromCeiling)->toBeFalse();
});

it('marks the buildings the game hangs from the ceiling', function (): void {
    Building::factory()->create(['building_id' => 'FarmStation', 'build_location_rule' => BuildLocationRule::OnCeiling->name]);

    expect(presentZone()->map?->buildings[0]->hangsFromCeiling)->toBeTrue();
});

it('draws a building as tall as its game definition when it occupies fewer cells', function (): void {
    expect(presentZone()->map?->buildings[0]->spriteHeight)->toBe(2);

    Building::factory()->create(['building_id' => 'FarmStation', 'width' => 2, 'height' => 3]);

    $building = presentZone()->map?->buildings[0];

    expect($building?->height)->toBe(2)
        ->and($building?->spriteHeight)->toBe(3);
});

it('colours the terrain of the box with the element ui colours', function (): void {
    Element::factory()->solid()->create(['element_id' => 'Dirt', 'ui_color' => 0x8A5A2B, 'name' => ['en' => 'Dirt']]);
    Element::factory()->gas()->create(['element_id' => 'Oxygen', 'ui_color' => null, 'name' => ['en' => 'Oxygen']]);

    $terrain = presentZone()->mapTerrain;

    expect([$terrain?->box->x, $terrain?->box->y, $terrain?->box->width, $terrain?->box->height])->toBe([99, 200, 22, 4])
        ->and(array_map(fn (ZoneTerrainElementData $entry): array => [$entry->elementId, $entry->colour], $terrain->palette ?? []))
        ->toBe([['Oxygen', null], ['Dirt', '#8a5a2b']])
        ->and(array_map(fn (ZoneTerrainElementData $entry): array => [$entry->name, $entry->state], $terrain->palette ?? []))
        ->toBe([['Oxygen', 'gas'], ['Dirt', 'solid']])
        ->and($terrain?->element)->toHaveCount(88)
        ->and($terrain?->element[0])->toBe(1)
        ->and($terrain?->element[22])->toBe(0)
        ->and($terrain?->mass[22])->toBe(1.5)
        ->and($terrain?->temperature[22])->toBe(295.15);
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
        ->and($shown->mapPlants)->toBeNull()
        ->and($shown->mapTerrain?->palette)->toBe([])
        ->and($shown->mapTerrain?->element)->toBeNull()
        ->and($shown->mapTerrain?->mass)->toHaveCount(88)
        ->and($shown->mapTerrain?->mass[0])->toBe(1.5)
        ->and($shown->entities)->toBe([])
        ->and($shown->plantsKnown)->toBeFalse()
        ->and($shown->buildingStatesKnown)->toBeFalse()
        ->and(app(ZonePresenter::class)->problemSummary($zone))->toBe(['count' => null, 'partial' => false]);
});

it('has no terrain when no layer of the slow state was read', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update(['slow_state' => [...$zone->slow_state, 'palette' => null, 'element' => null, 'mass' => null, 'temperature' => null]]);

    expect(app(ZonePresenter::class)->present($zone)->mapTerrain)->toBeNull();
});

it('presents a zone known only from the zone list', function (): void {
    $zone = Zone::factory()->create(['orphaned' => true]);

    $shown = app(ZonePresenter::class)->present($zone);

    expect($shown->map)->toBeNull()
        ->and($shown->entities)->toBe([])
        ->and($shown->zone->orphaned)->toBeTrue()
        ->and($shown->zone->layoutAt)->toBeNull()
        ->and($shown->plantsKnown)->toBeFalse()
        ->and(app(ZonePresenter::class)->problemSummary($zone))->toBe(['count' => null, 'partial' => false]);
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
        ->and($presenter->problemSummary($zone))->toBe(['count' => 3, 'partial' => false]);
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

    expect($shown->mapPlants[0]->sprite)->toBe('/assets/game/plants/SaltySticksPlant.webp')
        ->and($shown->map?->buildings[0]->sprite)->toBe('/assets/game/buildings/FarmStation.webp')
        ->and($shown->problems[0]->sprite)->toBe('/assets/game/buildings/FarmStation.webp');

    File::deleteDirectory($assets);
});

it('shows the element a missing delivery names, in any stored language', function (): void {
    $assets = sys_get_temp_dir().'/zone-assets-'.uniqid();
    File::ensureDirectoryExists("{$assets}/elements");
    File::put("{$assets}/elements/Fertilizer.webp", '');
    config(['game.assets_path' => $assets]);
    Element::factory()->create(['element_id' => 'Fertilizer', 'name' => ['en' => 'Fertilizer', 'fr' => 'Engrais']]);

    $withStatus = function (string $name): App\Data\ZoneShowData {
        $zone = Zone::factory()->sodicaneFarm()->create();
        $fast = $zone->fast_state;
        $fast['buildings'][0]['statusItems'] = [['id' => 'MaterialsUnavailable', 'name' => $name]];
        $zone->update(['fast_state' => $fast]);

        return app(ZonePresenter::class)->present($zone);
    };

    $english = presentZone();
    $french = $withStatus('Ressources insuffisantes : <link="FERTILIZER">Engrais</link>');
    $unknown = $withStatus('Insufficient Resources: Moonbeams');

    expect($english->problems[0]->neededItemSprite)->toBe('/assets/game/elements/Fertilizer.webp')
        ->and($french->problems[0]->neededItemSprite)->toBe('/assets/game/elements/Fertilizer.webp')
        ->and($unknown->problems[0]->neededItemSprite)->toBeNull();

    File::deleteDirectory($assets);
});

it('marks the problem count partial when only plants or buildings were read', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update(['fast_state' => [...$zone->fast_state, 'plants' => null]]);

    expect(app(ZonePresenter::class)->problemSummary($zone))->toBe(['count' => 1, 'partial' => true]);
});

it('reads unknown terrain cells as -1 and fits each layer to the box', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update(['slow_state' => [...$zone->slow_state, 'palette' => ['', 'Dirt'], 'element' => [0, 7, 'x'], 'mass' => array_fill(0, 100, 2.0), 'temperature' => null]]);

    $terrain = app(ZonePresenter::class)->present($zone)->mapTerrain;

    expect($terrain?->palette[0]->elementId)->toBeNull()
        ->and(array_slice($terrain->element ?? [], 0, 4))->toBe([0, -1, -1, -1])
        ->and($terrain?->element)->toHaveCount(88)
        ->and($terrain?->mass)->toHaveCount(88)
        ->and($terrain?->temperature)->toBeNull();
});

it('skips layout entries it cannot place', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update([
        'layout' => [...$zone->layout,
            'tiles' => [['prefabId' => 'Tile'], ['cell' => 'x', 'prefabId' => 'Tile'], ['cell' => zoneCell(5, 5), 'prefabId' => 'Tile']],
            'buildings' => [['instanceId' => '9', 'prefabId' => 'FarmStation', 'width' => 2, 'height' => 2]],
        ],
        'fast_state' => [...$zone->fast_state, 'plants' => [['id' => 'SaltySticksPlant', 'instanceId' => '1']]],
    ]);

    $shown = app(ZonePresenter::class)->present($zone);
    $map = $shown->map;

    expect(array_map(fn (ZoneTileData $tile): array => [$tile->x, $tile->y], $map->tiles ?? []))->toBe([[5, 5]])
        ->and($map?->buildings)->toBe([])
        ->and($shown->mapPlants)->toBe([]);
});

it('gives each palette entry its render properties', function (): void {
    Element::factory()->liquid()->create([
        'element_id' => 'Water', 'render_color' => 0x55BED2FF, 'max_mass' => 1000.0,
        'substance_texture' => SubstanceTexture::None,
    ])->thermalProperties()->create([
        'specific_heat_capacity' => 4.18, 'thermal_conductivity' => 0.6, 'low_temp' => 273.15, 'high_temp' => 373.15,
        'default_temperature' => 293.15, 'light_absorption_factor' => 0, 'radiation_absorption_factor' => 0, 'radiation_per_1000_mass' => 0,
    ]);
    Element::factory()->solid()->create(['element_id' => 'SandStone', 'terrain_texture' => 'sand_stone', 'glows' => true]);
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update(['slow_state' => [...$zone->slow_state, 'palette' => ['Water', 'SandStone', 'Nope']]]);

    [$water, $stone, $unknown] = app(ZonePresenter::class)->present($zone)->mapTerrain?->palette;

    expect($water->renderColour)->toBe('#55bed2ff')
        ->and($water->maxMass)->toBe(1000.0)
        ->and($water->substanceTexture)->toBe(SubstanceTexture::None)
        ->and($water->lowTemp)->toEqualWithDelta(273.15, 0.001)
        ->and($water->highTemp)->toEqualWithDelta(373.15, 0.001)
        ->and($water->terrainTexture)->toBeNull()
        ->and($stone->terrainTexture)->toBe('/assets/game/terrain/sand_stone.webp')
        ->and($stone->glows)->toBeTrue()
        ->and($stone->lowTemp)->toBeNull()
        ->and($unknown->renderColour)->toBeNull()
        ->and($unknown->glows)->toBeFalse();
});

it('encodes the terrain texture name in its URL', function (): void {
    Element::factory()->solid()->create(['element_id' => 'Granite', 'terrain_texture' => 'granite rough']);
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update(['slow_state' => [...$zone->slow_state, 'palette' => ['Granite']]]);

    $granite = app(ZonePresenter::class)->present($zone)->mapTerrain?->palette[0];

    expect($granite?->terrainTexture)->toBe('/assets/game/terrain/granite%20rough.webp');
});

it('gives the previous temperatures and the seconds between readings', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();
    $box = $zone->slow_state['box'];
    $zone->update([
        'slow_state_at' => now(),
        'previous_slow_temperature' => ['box' => $box, 'temperature' => array_fill(0, 88, 290.0), 'at' => now()->subSeconds(30)->toIso8601String()],
    ]);

    $terrain = app(ZonePresenter::class)->present($zone->refresh())->mapTerrain;

    expect($terrain?->previousSeconds)->toBe(30.0)
        ->and($terrain?->previousTemperature)->toHaveCount(88)
        ->and($terrain?->previousTemperature[0])->toBe(290.0);
});

it('drops the previous temperatures when the box changed', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update([
        'previous_slow_temperature' => ['box' => [...$zone->slow_state['box'], 'width' => 21], 'temperature' => array_fill(0, 84, 290.0), 'at' => now()->subSeconds(30)->toIso8601String()],
    ]);
    $terrain = app(ZonePresenter::class)->present($zone->refresh())->mapTerrain;

    expect($terrain?->previousTemperature)->toBeNull()
        ->and($terrain?->previousSeconds)->toBeNull();

    $zone->update(['previous_slow_temperature' => ['box' => $zone->slow_state['box'], 'temperature' => [1.0], 'at' => now()->toIso8601String()]]);
    expect(app(ZonePresenter::class)->present($zone->refresh())->mapTerrain?->previousTemperature)->toBeNull();
});

it('names the eight temperature bands in the current locale', function (): void {
    $dir = sys_get_temp_dir().'/oni-bands-'.uniqid();
    File::ensureDirectoryExists($dir);
    File::put($dir.'/fr.json', json_encode(['STRINGS.UI.OVERLAYS.TEMPERATURE.TEMPERATE' => 'Tempéré']));
    app('translator')->addJsonPath($dir);
    app()->setLocale('fr');

    $bands = presentZone()->map?->temperatureBands;

    expect($bands)->toHaveCount(8)
        ->and($bands[3])->toBe('Tempéré');

    File::deleteDirectory($dir);
});

it('drops the previous temperatures when their time is unreadable', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update(['previous_slow_temperature' => ['box' => $zone->slow_state['box'], 'temperature' => array_fill(0, 88, 290.0), 'at' => 'not a date']]);

    $terrain = app(ZonePresenter::class)->present($zone->refresh())->mapTerrain;

    expect($terrain?->previousTemperature)->toBeNull()
        ->and($terrain?->previousSeconds)->toBeNull();
});

/** @param  list<array<string, mixed>>  $buildings */
function zoneWithFastBuildings(array $buildings): Zone
{
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update(['fast_state' => [...$zone->fast_state, 'plants' => [], 'buildings' => $buildings]]);

    return $zone;
}

/** @return array<string, mixed> */
function fastBuilding(string $instanceId, string $prefabId, bool $operational = true): array
{
    return [
        'instanceId' => $instanceId,
        'prefabId' => $prefabId,
        'operational' => $operational,
        'active' => $operational,
        'statusItems' => $operational ? [] : [['id' => 'NeedPower', 'name' => null]],
        'storage' => null,
    ];
}

it('counts a fishing station on the border as zone content', function (): void {
    $shown = app(ZonePresenter::class)->present(zoneWithFastBuildings([
        fastBuilding('9001', 'FishingStation', operational: false),
        fastBuilding('9002', 'Door'),
    ]));

    expect(collect($shown->entities)->pluck('instanceId')->all())->toBe(['9001'])
        ->and(collect($shown->problems)->pluck('instanceId')->all())->toBe(['9001'])
        ->and($shown->counts->buildings)->toBe(1)
        ->and(collect($shown->borderEntities)->pluck('instanceId')->all())->toBe(['9002']);
});

it('keeps passages out of the problems even with a problem status', function (): void {
    $zone = zoneWithFastBuildings([
        fastBuilding('9101', 'Door', operational: false),
        fastBuilding('9102', 'Stairs', operational: false),
    ]);
    $presenter = app(ZonePresenter::class);
    $shown = $presenter->present($zone);

    expect($shown->problems)->toBe([])
        ->and($shown->borderEntities)->toHaveCount(2)
        ->and(collect($shown->borderEntities)->every(fn (ZoneEntityData $entity): bool => $entity->problems === []))->toBeTrue()
        ->and($presenter->problemSummary($zone)['count'])->toBe(0);
});

it('treats an unknown modded building as content', function (): void {
    $shown = app(ZonePresenter::class)->present(zoneWithFastBuildings([fastBuilding('9201', 'SomeModThing')]));

    expect(collect($shown->entities)->pluck('instanceId')->all())->toBe(['9201'])
        ->and($shown->borderEntities)->toBe([]);
});

/**
 * The farm zone with extra layout buildings and tiles, and stair cap assets for Stairs masks 1, 2 and 17.
 *
 * @param  list<array<string, mixed>>  $buildings
 * @param  list<array<string, mixed>>  $tiles
 */
function presentStairs(array $buildings, array $tiles = []): App\Data\ZoneShowData
{
    $assets = sys_get_temp_dir().'/zone-assets-'.uniqid();
    File::ensureDirectoryExists("{$assets}/stair_caps/Stairs");
    foreach ([1, 2, 17] as $mask) {
        File::put("{$assets}/stair_caps/Stairs/{$mask}.webp", '');
    }
    File::put("{$assets}/stair_caps/rects.json", json_encode(['Stairs' => [
        '1' => ['x' => -0.5, 'y' => 0, 'w' => 1.5, 'h' => 1],
        '2' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1],
        '17' => ['x' => -0.5, 'y' => 0, 'w' => 1.5, 'h' => 1.05],
    ]]));
    config(['game.assets_path' => $assets]);

    $zone = Zone::factory()->sodicaneFarm()->create();
    $layout = $zone->layout;
    $layout['buildings'] = [...$layout['buildings'], ...$buildings];
    $layout['tiles'] = [...($layout['tiles'] ?? []), ...$tiles];
    $zone->update(['layout' => $layout]);

    $shown = app(ZonePresenter::class)->present($zone);
    File::deleteDirectory($assets);

    return $shown;
}

/** @return array<string, mixed> */
function stair(int $x, int $y, string $orientation = 'Neutral'): array
{
    return ['instanceId' => "stair-{$x}-{$y}", 'prefabId' => 'Stairs', 'anchorCell' => zoneCell($x, $y), 'width' => 1, 'height' => 1, 'orientation' => $orientation];
}

it('draws each stair with the end caps its neighbours give it, placed and flipped like the game', function (): void {
    $buildings = collect(presentStairs([stair(117, 201), stair(118, 202), stair(105, 201, 'FlipH')])->map?->buildings)
        ->keyBy(fn (App\Data\ZoneBuildingData $building): string => (string) $building->instanceId);

    expect($buildings['stair-117-201']->sprite)->toBe('/assets/game/stair_caps/Stairs/1.webp')
        ->and($buildings['stair-117-201']->spriteRect?->toArray())->toBe(['x' => -0.5, 'y' => 0.0, 'w' => 1.5, 'h' => 1.0])
        ->and($buildings['stair-117-201']->flipped)->toBeFalse()
        ->and($buildings['stair-118-202']->sprite)->toBe('/assets/game/stair_caps/Stairs/17.webp')
        ->and($buildings['stair-105-201']->sprite)->toBe('/assets/game/stair_caps/Stairs/17.webp')
        ->and($buildings['stair-105-201']->flipped)->toBeTrue();
});

it('closes a stair under a tile that makes its cell solid', function (): void {
    Building::factory()->create(['building_id' => 'Tile', 'sim_cell_occupier' => true]);

    $stair = collect(presentStairs([stair(117, 201)], [['cell' => zoneCell(117, 202), 'prefabId' => 'Tile']])->map?->buildings)
        ->firstWhere('prefabId', 'Stairs');

    expect($stair?->sprite)->toBe('/assets/game/stair_caps/Stairs/2.webp');
});

it('keeps the building image of a stair whose cap variant was not imported', function (): void {
    $stair = collect(presentStairs([stair(117, 201), stair(117, 200)])->map?->buildings)
        ->firstWhere('instanceId', 'stair-117-201');

    expect($stair?->sprite)->toBeNull()
        ->and($stair?->spriteRect)->toBeNull()
        ->and($stair?->flipped)->toBeFalse();
});

it('leaves other buildings unflipped and without a sprite rect', function (): void {
    $farmStation = presentZone()->map?->buildings[0];

    expect($farmStation?->flipped)->toBeFalse()
        ->and($farmStation?->spriteRect)->toBeNull();
});

it('lists the zone captures newest first and says which match the current layout', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();
    $layout = $zone->layout;
    $layout['signature'] = 'current';
    $zone->update(['layout' => $layout]);
    App\Models\ZoneCapture::factory()->for($zone)->create(['path' => 'zone-captures/z/old.png', 'taken_at' => now()->subHour(), 'layout_signature' => 'older']);
    App\Models\ZoneCapture::factory()->for($zone)->create(['path' => 'zone-captures/z/new.png', 'taken_at' => now(), 'layout_signature' => 'current', 'game_seconds' => 247_500.0, 'trigger' => 'manual']);

    $captures = app(ZonePresenter::class)->present($zone)->captures;

    expect($captures)->toHaveCount(2)
        ->and($captures[0]->url)->toBe('/storage/zone-captures/z/new.png')
        ->and($captures[0]->matchesLayout)->toBeTrue()
        ->and($captures[0]->cycle)->toBe(412)
        ->and($captures[0]->trigger)->toBe('manual')
        ->and($captures[1]->matchesLayout)->toBeFalse();
});

it('reports the latest capture request of the zone', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();

    expect(app(ZonePresenter::class)->present($zone)->captureRequest)->toBeNull();

    App\Models\UplinkCommand::factory()->create(['colony_guid' => $zone->colony_guid, 'type' => 'zone.capture', 'payload' => ['zoneId' => 'another-zone'], 'issued_at' => now()]);
    App\Models\UplinkCommand::factory()->create(['colony_guid' => $zone->colony_guid, 'type' => 'zone.capture', 'payload' => ['zoneId' => $zone->zone_guid], 'issued_at' => now()->subMinute()]);
    App\Models\UplinkCommand::factory()->create([
        'colony_guid' => $zone->colony_guid, 'type' => 'zone.capture', 'payload' => ['zoneId' => $zone->zone_guid],
        'issued_at' => now(), 'status' => App\Models\UplinkCommand::REFUSED, 'reason' => 'zone on another world',
    ]);

    $request = app(ZonePresenter::class)->present($zone)->captureRequest;

    expect($request?->status)->toBe('refused')
        ->and($request?->reason)->toBe('zone on another world');
});

it('gives when the zone colony was last seen by the link, paused game included', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();

    expect(app(ZonePresenter::class)->present($zone)->linkSeenAt)->toBeNull();

    App\Models\Colony::factory()->create(['guid' => $zone->colony_guid, 'last_seen_at' => '2026-11-14 18:00:00']);
    App\Models\Colony::factory()->create(['guid' => 'another-colony', 'last_seen_at' => '2026-11-14 19:00:00']);

    expect(app(ZonePresenter::class)->present($zone)->linkSeenAt)->toStartWith('2026-11-14T18:00:00');
});

/**
 * A pond of 104 cells with 11 Pacu of 8 cells each (88 occupied) and 24 cells of eggs at hatching, as the mod sends it.
 *
 * @param  array<string, mixed>  $fast
 */
function populatedPond(array $fast = []): Zone
{
    CritterMorph::factory()->for(Critter::factory())->create(['morph_id' => 'Pacu', 'overcrowding_threshold' => 8]);

    return Zone::factory()->sodicaneFarm()->create(['fast_state' => [
        'schema' => 1,
        'critters' => array_map(fn (int $index): array => [
            'instanceId' => (string) (1200 + $index), 'prefabId' => 'Pacu', 'cell' => 100 + $index, 'x' => 1.0, 'y' => 1.0,
            'aquatic' => true, 'regionId' => 'pond:1201',
        ], range(1, 11)),
        'crowding' => [[
            'id' => 'pond:1201', 'kind' => 'pond', 'cellCount' => 104, 'occupied' => 88, 'eggOccupied' => 24,
            'counts' => ['Pacu' => 11],
            'states' => ['Pacu' => ['overcrowded' => false, 'cramped' => true, 'confined' => false, 'modifier' => 0]],
        ]],
        ...$fast,
    ]]);
}

it('presents one population block per crowding region with the derived removal', function (): void {
    $population = app(ZonePresenter::class)->present(populatedPond())->population;

    expect($population)->toHaveCount(1)
        ->and($population[0])->toMatchObject([
            'regionId' => 'pond:1201', 'kind' => 'pond', 'cellCount' => 104, 'occupied' => 88, 'eggOccupied' => 24,
            'overcrowded' => false, 'cramped' => true, 'confined' => false,
        ])
        ->and($population[0]->types)->toBe([['prefabId' => 'Pacu', 'name' => 'Pacu', 'count' => 11, 'personalSpace' => 8]])
        ->and($population[0]->perType)->toHaveCount(1)
        ->and($population[0]->perType[0]->toArray())->toMatchArray(['prefabId' => 'Pacu', 'toRemove' => 1, 'derived' => true]);
});

it('takes the egg space of the population from the game, not from the lagging egg list', function (): void {
    $zone = populatedPond();
    $zone->update(['slow_state' => [...$zone->slow_state, 'eggs' => []]]);

    $population = app(ZonePresenter::class)->present($zone->fresh())->population;

    expect($population[0]->eggOccupied)->toBe(24)
        ->and($population[0]->perType[0]->toRemove)->toBe(1);
});

it('keeps the raw game states in the population block, confined included', function (): void {
    $zone = populatedPond();
    $fast = $zone->fast_state;
    $fast['crowding'][0]['states']['Pacu']['confined'] = true;
    $zone->update(['fast_state' => $fast]);

    $block = app(ZonePresenter::class)->present($zone->fresh())->population[0];

    expect($block->confined)->toBeTrue()
        ->and($block->cramped)->toBeTrue();
});

it('presents unknown population when the fast state has no critters', function (): void {
    $shown = app(ZonePresenter::class)->present(Zone::factory()->sodicaneFarm()->create());

    expect($shown->population)->toBeNull();
});

it('presents an empty population when the game sent no region', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create(['fast_state' => ['schema' => 1, 'critters' => [], 'crowding' => []]]);

    expect(app(ZonePresenter::class)->present($zone)->population)->toBe([]);
});

it('leaves the removal empty when a species of the population is unknown to the game data', function (): void {
    $zone = populatedPond();
    CritterMorph::query()->where('morph_id', 'Pacu')->delete();

    $block = app(ZonePresenter::class)->present($zone->fresh())->population[0];

    expect($block->perType)->toBe([])
        ->and($block->types[0]['personalSpace'])->toBeNull();
});

it('presents critters and eggs with counts', function (): void {
    CritterMorph::factory()->for(Critter::factory())->create(['morph_id' => 'PacuTropical', 'incubation_time' => 5, 'overcrowding_threshold' => 8]);
    CritterMorph::factory()->baby()->for(Critter::factory())->create(['morph_id' => 'PacuTropicalBaby']);
    $zone = populatedPond();
    $zone->update(['slow_state' => [...$zone->slow_state, 'eggs' => [
        ['instanceId' => '5678', 'prefabId' => 'PacuTropicalEgg', 'cell' => 133900, 'incubation' => 38.0, 'viability' => 100.0,
            'tame' => true, 'spawns' => 'PacuTropicalBaby', 'location' => 'floor', 'holderId' => null, 'regionId' => 'pond:1201'],
        ['instanceId' => '5679', 'prefabId' => 'MysteryEgg', 'cell' => 133901, 'incubation' => 10.0, 'viability' => 90.0,
            'tame' => false, 'spawns' => 'MysteryBaby', 'location' => 'incubator', 'holderId' => '42', 'regionId' => null],
    ]]]);

    $shown = app(ZonePresenter::class)->present($zone->fresh());

    expect($shown->critters)->toHaveCount(11)
        ->and($shown->critters[0]->prefabId)->toBe('Pacu')
        ->and($shown->eggs)->toHaveCount(2)
        ->and($shown->eggs[0]->incubationCycles)->toBe(5.0)
        ->and($shown->eggs[1]->incubationCycles)->toBeNull()
        ->and($shown->counts->critters)->toBe(11)
        ->and($shown->counts->eggs)->toBe(2);
});

it('keeps critters and eggs unknown until the game sent them', function (): void {
    $shown = presentZone();

    expect($shown->critters)->toBeNull()
        ->and($shown->eggs)->toBeNull()
        ->and($shown->counts->critters)->toBe(0)
        ->and($shown->counts->eggs)->toBe(0);
});

it('counts the critter alerts in the zone problems', function (): void {
    $zone = populatedPond();
    $fast = $zone->fast_state;
    $fast['critters'][0]['statuses'] = [['id' => 'Scalded', 'bad' => true]];
    $zone->update(['fast_state' => $fast]);

    $shown = app(ZonePresenter::class)->present($zone->fresh());

    expect($shown->counts->problems)->toBe(2)
        ->and(array_column($shown->problems, 'kind'))->toBe(['critter_group', 'critter'])
        ->and($shown->problems[0]->problems[0])->toContain('Cramped')->toContain('remove 1')
        ->and($shown->problems[1]->problems)->toBe(['Scalded'])
        ->and($shown->entities)->not->toContain($shown->problems[1]);
});

/** The pond's Pacu eat algae (7.5 kg each per cycle, 2/3 dropped as polluted sand) or hunt nothing, and lay eggs. */
function feedingPond(array $fast = []): Zone
{
    $zone = populatedPond($fast);
    $morph = CritterMorph::query()->where('morph_id', 'Pacu')->firstOrFail();
    App\Models\CritterMorphDiet::factory()->create(['critter_morph_id' => $morph->id, 'consumed_element_id' => 'Algae', 'amount_per_cycle' => 7.5, 'produced_element_id' => 'ToxicSand', 'conversion_ratio' => 0.5, 'food_kind' => 'item']);
    App\Models\CritterMorphDiet::factory()->create(['critter_morph_id' => $morph->id, 'consumed_element_id' => 'Kelp', 'amount_per_cycle' => 20.0, 'produced_element_id' => 'ToxicSand', 'conversion_ratio' => 0.5, 'food_kind' => 'item']);
    App\Models\CritterMorphOutput::factory()->create(['critter_morph_id' => $morph->id, 'element_id' => 'PacuEgg', 'amount_per_cycle' => 0.05, 'output_type' => 'egg']);
    PrefabName::factory()->create(['prefab_id' => 'Algae', 'name_key' => 'STRINGS.ELEMENTS.ALGAE.NAME']);

    return $zone;
}

it('presents a feeding balance per flock group', function (): void {
    $zone = feedingPond(['buildings' => [
        ['instanceId' => '9001', 'prefabId' => 'CreatureFeeder', 'storage' => [['id' => 'Algae', 'mass' => 132.0], ['id' => 'Sand', 'mass' => 100.0]]],
    ]]);

    $feeding = app(ZonePresenter::class)->present($zone)->feeding;

    expect($feeding)->toHaveCount(1)
        ->and($feeding[0]->groupKey)->toBe('Pacu:wild')
        ->and($feeding[0]->count)->toBe(11)
        ->and($feeding[0]->foods[0]->toArray())->toMatchArray(['resource' => 'Algae', 'name' => 'Algae', 'perCycle' => 82.5, 'kind' => 'item'])
        ->and($feeding[0]->foods[1]->resource)->toBe('Kelp')
        ->and($feeding[0]->otherFoods)->toBe(0)
        ->and(array_map(fn ($line): array => [$line->resource, $line->kind, round($line->perCycle, 4)], $feeding[0]->produces))
        ->toBe([['ToxicSand', 'dropping', 41.25], ['PacuEgg', 'egg', 0.55]])
        ->and($feeding[0]->availableKg)->toBe(132.0)
        ->and($feeding[0]->autonomyCycles)->toBe(1.6)
        ->and($feeding[0]->deficitKgPerCycle)->toBeNull()
        ->and($feeding[0]->derived)->toBeTrue();
});

it('reports the need of a flock group without accepted stock as a deficit', function (): void {
    $feeding = app(ZonePresenter::class)->present(feedingPond(['buildings' => []]))->feeding;

    expect($feeding[0]->deficitKgPerCycle)->toBe(82.5)
        ->and($feeding[0]->autonomyCycles)->toBe(0.0);
});

it('keeps the stock unknown while the building storages were not read', function (): void {
    $feeding = app(ZonePresenter::class)->present(feedingPond(['buildings' => null]))->feeding;

    expect($feeding[0]->availableKg)->toBeNull()
        ->and($feeding[0]->deficitKgPerCycle)->toBeNull()
        ->and($feeding[0]->autonomyCycles)->toBeNull();
});

it('names what a plant eater grazes with the matching plants of the zone', function (): void {
    $zone = feedingPond();
    $morph = CritterMorph::factory()->for(Critter::factory())->create(['morph_id' => 'Drecko']);
    App\Models\CritterMorphDiet::factory()->create(['critter_morph_id' => $morph->id, 'consumed_element_id' => 'Algae', 'amount_per_cycle' => 0.75, 'produced_element_id' => null, 'conversion_ratio' => null, 'food_kind' => 'plant']);
    $fast = $zone->fast_state;
    $fast['critters'][] = ['instanceId' => '1300', 'prefabId' => 'Drecko', 'cell' => 100, 'x' => 1.0, 'y' => 1.0, 'tame' => true];
    $fast['plants'] = [['id' => 'Algae', 'instanceId' => '4001'], ['id' => 'Algae', 'instanceId' => '4002'], ['id' => 'Other', 'instanceId' => '4003']];
    $zone->update(['fast_state' => $fast]);

    $drecko = collect(app(ZonePresenter::class)->present($zone->fresh())->feeding)->firstWhere('groupKey', 'Drecko:adult');

    expect($drecko->foods)->toHaveCount(1)
        ->and($drecko->foods[0]->toArray())->toMatchArray(['resource' => 'Algae', 'kind' => 'plant', 'inZone' => 2])
        ->and($drecko->deficitKgPerCycle)->toBeNull()
        ->and($drecko->autonomyCycles)->toBeNull();
});

it('presents no feeding for a group without a diet and unknown feeding without critters', function (): void {
    expect(app(ZonePresenter::class)->present(populatedPond())->feeding)->toBe([])
        ->and(presentZone()->feeding)->toBeNull();
});

it('reloads the feeding with the fast state', function (): void {
    expect(ZonePresenter::PROPS_BY_KIND['fast'])->toContain('feeding')
        ->and(ZonePresenter::PROPS_BY_KIND['slow'])->not->toContain('feeding')
        ->and(ZonePresenter::PROPS_BY_KIND['layout'])->toContain('feeding');
});

it('lists first the prey a hunter finds in the zone and counts the others', function (): void {
    $zone = feedingPond();
    $morph = CritterMorph::factory()->for(Critter::factory())->create(['morph_id' => 'Raptor']);
    foreach (['Hatch', 'HatchBaby', 'Squirrel', 'Pacu'] as $prey) {
        App\Models\CritterMorphDiet::factory()->create(['critter_morph_id' => $morph->id, 'consumed_element_id' => $prey, 'amount_per_cycle' => 1.0, 'produced_element_id' => null, 'conversion_ratio' => null, 'food_kind' => 'prey']);
    }
    $fast = $zone->fast_state;
    $fast['critters'][] = ['instanceId' => '1400', 'prefabId' => 'Raptor', 'cell' => 100, 'x' => 1.0, 'y' => 1.0, 'tame' => true];
    $zone->update(['fast_state' => $fast]);

    $raptor = collect(app(ZonePresenter::class)->present($zone->fresh())->feeding)->firstWhere('groupKey', 'Raptor:adult');

    expect(array_map(fn ($food): array => [$food->resource, $food->inZone], $raptor->foods))->toBe([['Pacu', 11], ['Hatch', 0], ['HatchBaby', 0]])
        ->and($raptor->otherFoods)->toBe(1);
});
