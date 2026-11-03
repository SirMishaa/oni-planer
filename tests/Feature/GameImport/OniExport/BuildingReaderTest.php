<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Services\GameImport\OniExport\BuildingReader;
use App\Services\GameImport\OniExport\ModCatalog;
use App\Services\GameImport\OniExport\OniExport;

/** @return array<string, array<string, mixed>> */
function readFixtureBuildings(): array
{
    $root = base_path('tests/Fixtures/OniExport');
    $export = new OniExport($root);
    $catalog = ModCatalog::fromExport($export, $root.'/mods', ['3557584850']);
    $catalog->persist();

    return collect(new BuildingReader($export, $catalog)->read())->keyBy('building_id')->all();
}

it('reads electrolyzer ports and size', function (): void {
    $electrolyzer = readFixtureBuildings()['Electrolyzer'];

    expect($electrolyzer['ports'])->toBe([
        ['type' => 'LiquidInput', 'x' => 0, 'y' => 0, 'secondary' => false],
        ['type' => 'PowerInput', 'x' => 1, 'y' => 0, 'secondary' => false],
        ['type' => 'LogicInput', 'x' => 1, 'y' => 1, 'secondary' => false],
    ])
        ->and($electrolyzer['width'])->toBe(2)
        ->and($electrolyzer['height'])->toBe(2)
        ->and($electrolyzer['power_consumption'])->toBe(120.0)
        ->and($electrolyzer['power_generation'])->toBeNull();
});

it('reads flush toilet placement rules', function (): void {
    $toilet = readFixtureBuildings()['FlushToilet'];

    expect($toilet['build_location_rule'])->toBe('OnFloor')
        ->and($toilet['permitted_rotations'])->toBe('FlipH')
        ->and($toilet['object_layer'])->toBe('Building')
        ->and($toilet['scene_layer'])->toBe(19)
        ->and($toilet['overlay'])->toBe('Liquid')
        ->and($toilet['is_foundation'])->toBeFalse()
        ->and($toilet['is_tile'])->toBeFalse()
        ->and($toilet['replaceable'])->toBeTrue()
        ->and($toilet['placement_rect'])->toBe(['x' => 0.18, 'y' => -0.265, 'w' => 1.92, 'h' => 3.535]);
});

it('reads the build menu category, subcategory and name key', function (): void {
    $electrolyzer = readFixtureBuildings()['Electrolyzer'];

    expect($electrolyzer['category'])->toBe('Oxygen')
        ->and($electrolyzer['subcategory'])->toBe('producers')
        ->and($electrolyzer['name_localization_id'])->toBe('STRINGS.BUILDINGS.PREFABS.ELECTROLYZER.NAME')
        ->and($electrolyzer['tags'])->toBe(['RoomProberBuilding', 'IndustrialMachinery'])
        ->and($electrolyzer['materials'])->toBe([['material_category' => 'Metal', 'amount' => 200.0]])
        ->and($electrolyzer['dlc_id'])->toBeNull()
        ->and($electrolyzer['mod_id'])->toBeNull();
});

it('attaches ronivan buildings to their mod', function (): void {
    $drill = readFixtureBuildings()['Mining_MineralDrill'];

    expect($drill['mod_id'])->toBe(Mod::query()->where('workshop_id', '3557584850')->value('id'))
        ->and($drill['category'])->toBe('Utilities');
});

it('skips buildings hidden from the build menu', function (): void {
    $buildings = readFixtureBuildings();

    expect($buildings)->toHaveKeys(['Electrolyzer', 'FlushToilet', 'Generator', 'Mining_MineralDrill'])
        ->not->toHaveKey('GravitasDoor')
        ->not->toHaveKey('AtomicGarden')
        ->not->toHaveKey('Stairs');
});

it('takes construction time and heat from planner extras', function (): void {
    $buildings = readFixtureBuildings();

    expect($buildings['Electrolyzer']['construction_time'])->toBe(30.0)
        ->and($buildings['Electrolyzer']['heat_generation'])->toBe(1.25)
        ->and($buildings['Generator']['power_generation'])->toBe(600.0)
        ->and($buildings['Generator']['heat_generation'])->toBe(9.0);
});

it('imports rocket modules listed in the rocket module menu', function (): void {
    $engine = readFixtureBuildings()['CO2Engine'];

    expect($engine['category'])->toBe('Rocketry')
        ->and($engine['subcategory'])->toBe('module')
        ->and($engine['width'])->toBe(3)
        ->and($engine['height'])->toBe(2)
        ->and($engine['construction_time'])->toBe(30.0)
        ->and($engine['dlc_id'])->toBe('EXPANSION1_ID');
});
