<?php

declare(strict_types=1);

use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use App\Models\Element;
use App\Models\PrefabName;
use App\Services\GameImport\PrefabNameImporter;
use App\Services\GameImport\StringResolver;

function importPrefabNames(array $critters = [], array $plants = [], array $buildings = [], array $geysers = []): void
{
    $cachePath = sys_get_temp_dir().'/prefab-cache-'.uniqid();
    mkdir($cachePath);
    file_put_contents($cachePath.'/critters.json', json_encode($critters));
    file_put_contents($cachePath.'/plants.json', json_encode($plants));
    file_put_contents($cachePath.'/buildings.json', json_encode($buildings));
    file_put_contents($cachePath.'/geyser_types.json', json_encode($geysers));
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));

    new PrefabNameImporter($resolver)->import($cachePath);
}

function nameKeyOf(string $prefabId): ?string
{
    return PrefabName::query()->where('prefab_id', $prefabId)->value('name_key');
}

it('keys elements by their STRINGS.ELEMENTS entry', function (): void {
    Element::factory()->create(['element_id' => 'Oxygen']);

    importPrefabNames();

    expect(nameKeyOf('Oxygen'))->toBe('STRINGS.ELEMENTS.OXYGEN.NAME');
});

it('keys critter morphs and their eggs from the critter name key', function (): void {
    importPrefabNames(critters: [[
        'critter_id' => 'TestHatch',
        'name_localization_id' => 'STRINGS.CREATURES.SPECIES.TESTHATCH.NAME',
        'outputs' => [['element_id' => 'TestHatchEgg', 'output_type' => 'egg']],
    ]]);

    expect(nameKeyOf('TestHatch'))->toBe('STRINGS.CREATURES.SPECIES.TESTHATCH.NAME')
        ->and(nameKeyOf('TestHatchEgg'))->toBe('STRINGS.CREATURES.SPECIES.TESTHATCH.EGG_NAME');
});

it('keys plants from their nested-type name key', function (): void {
    importPrefabNames(plants: [[
        'plant_id' => 'TestMealwood',
        'name_localization_id' => 'STRINGS.CREATURES/SPECIES/TESTMEALWOOD::NAME',
    ]]);

    expect(nameKeyOf('TestMealwood'))->toBe('STRINGS.CREATURES.SPECIES.TESTMEALWOOD.NAME');
});

it('keys diet items that are not elements by looking them up in STRINGS', function (): void {
    CritterMorphDiet::factory()->create([
        'consumed_element_id' => 'DinosaurMeat',
        'produced_element_id' => 'NoSuchThing',
    ]);

    importPrefabNames();

    expect(nameKeyOf('DinosaurMeat'))->toBe('STRINGS.ITEMS.FOOD.DINOSAURMEAT.NAME')
        ->and(nameKeyOf('NoSuchThing'))->toBeNull();
});

it('skips names whose key does not exist in the strings', function (): void {
    importPrefabNames(critters: [[
        'critter_id' => 'Ghost',
        'name_localization_id' => 'STRINGS.CREATURES.SPECIES.GHOST.NAME',
        'outputs' => [],
    ]]);

    expect(nameKeyOf('Ghost'))->toBeNull();
});

it('keys outputs that differ from a morph id only by case, and babies laid directly', function (): void {
    CritterMorphOutput::factory()->create(['element_id' => 'Testhatch']);
    CritterMorphOutput::factory()->create(['element_id' => 'TestHatchBaby']);

    importPrefabNames(critters: [[
        'critter_id' => 'TestHatch',
        'name_localization_id' => 'STRINGS.CREATURES.SPECIES.TESTHATCH.NAME',
        'outputs' => [],
    ]]);

    expect(nameKeyOf('Testhatch'))->toBe('STRINGS.CREATURES.SPECIES.TESTHATCH.NAME')
        ->and(nameKeyOf('TestHatchBaby'))->toBe('STRINGS.CREATURES.SPECIES.TESTHATCH.BABY.NAME');
});

it('keys buildings and geysers from their extracted name keys', function (): void {
    importPrefabNames(
        buildings: [['building_id' => 'TestBuilding', 'name_localization_id' => 'STRINGS.BUILDINGS.PREFABS.TESTBUILDING.NAME']],
        geysers: [['geyser_id' => 'steam', 'name_localization_id' => 'STRINGS.CREATURES.SPECIES.GEYSER.STEAM.NAME']],
    );

    expect(nameKeyOf('TestBuilding'))->toBe('STRINGS.BUILDINGS.PREFABS.TESTBUILDING.NAME')
        ->and(nameKeyOf('steam'))->toBe('STRINGS.CREATURES.SPECIES.GEYSER.STEAM.NAME');
});
