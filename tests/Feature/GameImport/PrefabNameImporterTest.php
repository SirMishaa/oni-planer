<?php

declare(strict_types=1);

use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use App\Models\Element;
use App\Models\Mod;
use App\Models\PrefabName;
use App\Services\GameImport\OniExport\ModCatalog;
use App\Services\GameImport\OniExport\OniExport;
use App\Services\GameImport\PrefabNameImporter;
use App\Services\GameImport\StringResolver;

function importPrefabNames(array $critters = [], array $plants = [], array $buildings = [], array $geysers = [], ?ModCatalog $mods = null): void
{
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));

    new PrefabNameImporter($resolver, $mods)->import(compact('critters', 'plants', 'buildings', 'geysers'));
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

it('records the mod owning a prefab', function (): void {
    $root = base_path('tests/Fixtures/OniExport');
    $catalog = ModCatalog::fromExport(new OniExport($root), $root.'/mods', ['3557584850']);
    $catalog->persist();
    $modId = Mod::query()->value('id');
    Element::factory()->create(['element_id' => 'Oxygen']);

    importPrefabNames(
        buildings: [['building_id' => 'TestBuilding', 'mod_id' => $modId, 'name_localization_id' => 'STRINGS.BUILDINGS.PREFABS.TESTBUILDING.NAME']],
        mods: $catalog,
    );

    expect(PrefabName::query()->where('prefab_id', 'TestBuilding')->value('mod_id'))->toBe($modId)
        ->and(PrefabName::query()->where('prefab_id', 'Oxygen')->value('mod_id'))->toBeNull();
});
