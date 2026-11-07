<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Element;
use App\Models\Mod;
use App\Models\Recipe;
use App\Services\GameImport\OniExport\ModCatalog;
use App\Services\GameImport\OniExport\OniExport;
use App\Services\GameImport\OniExport\RecipeReader;
use App\Services\GameImport\RecipeImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $supported
 */
function fixtureRecipeReader(array $supported = ['3557584850']): RecipeReader
{
    $root = base_path('tests/Fixtures/OniExport');
    $export = new OniExport($root);
    $catalog = ModCatalog::fromExport($export, $root.'/mods', $supported);
    $catalog->persist();

    return new RecipeReader($export, $catalog);
}

/**
 * @param  list<string>  $supported
 * @return array<string, array<string, mixed>>
 */
function readFixtureRecipes(array $supported = ['3557584850']): array
{
    return collect(fixtureRecipeReader($supported)->records())->keyBy('recipe_id')->all();
}

it('reads the katairite electrobank recipe', function (): void {
    $recipe = readFixtureRecipes()['AdvancedCraftingTable_I_Katairite_O_EmptyElectrobank'];

    expect($recipe['building_id'])->toBe('AdvancedCraftingTable')
        ->and($recipe['fabricators'])->toBe(['AdvancedCraftingTable'])
        ->and($recipe['duration'])->toBe(60.0)
        ->and($recipe['inputs'])->toBe([['element_id' => 'Katairite', 'amount' => 200.0, 'alternative_group' => null]])
        ->and($recipe['outputs'])->toBe([['element_id' => 'EmptyElectrobank', 'amount' => 1.0, 'alternative_group' => null]])
        ->and($recipe['mod_id'])->toBeNull();
});

it('attributes a recipe to the mod of its fabricator', function (): void {
    $recipes = readFixtureRecipes();
    $ronivanId = Mod::query()->where('workshop_id', '3557584850')->value('id');

    expect($recipes['Mining_CNCMachine_I_Bioplastic_O_AIO_BioplasticGasket']['mod_id'])->toBe($ronivanId);
});

it('attributes a base fabricator recipe to the mod owning an ingredient or result', function (): void {
    $recipes = readFixtureRecipes();
    $ronivanId = Mod::query()->where('workshop_id', '3557584850')->value('id');

    expect($recipes['CraftingTable_I_Bioplastic_O_AIO_BioplasticGasket']['mod_id'])->toBe($ronivanId)
        ->and($recipes['Kiln_I_Clay_0_O_Brick']['mod_id'])->toBeNull();
});

it('skips a recipe whose fabricator belongs to an unsupported mod', function (): void {
    $reader = fixtureRecipeReader(supported: []);
    $ids = array_column($reader->records(), 'recipe_id');

    expect($ids)->not->toContain('Mining_CNCMachine_I_Bioplastic_O_AIO_BioplasticGasket')
        ->and($ids)->toContain('Kiln_I_Clay_0_O_Brick')
        ->and($reader->skippedRecipes())->toBe(1);
});

it('drops ingredients from unsupported mods and counts them', function (): void {
    $reader = fixtureRecipeReader(supported: []);
    $recipe = collect($reader->records())->keyBy('recipe_id')['CraftingTable_I_Bioplastic_O_AIO_BioplasticGasket'];

    expect($recipe['outputs'])->toBe([])
        ->and($recipe['mod_id'])->toBeNull()
        ->and($reader->skippedReferences())->toBe(2);
});

it('keeps the first of two recipes sharing an id and counts the duplicate', function (): void {
    $reader = fixtureRecipeReader();
    $ids = array_column($reader->records(), 'recipe_id');

    expect(array_count_values($ids)['MissileFabricator_I_0_0_0_O_MissileLongRange'])->toBe(1)
        ->and($reader->duplicateRecipes())->toBe(1);
});

it('keeps the options of an any-of ingredient as alternatives of one group', function (): void {
    $recipe = readFixtureRecipes()['MetalRefinery_I_IronOre_0_O_Iron_SolidSlag'];

    expect($recipe['inputs'])->toBe([
        ['element_id' => 'IronOre', 'amount' => 100.0, 'alternative_group' => null],
        ['element_id' => 'Carbon', 'amount' => 20.0, 'alternative_group' => 2],
        ['element_id' => 'Peat', 'amount' => 20.0, 'alternative_group' => 2],
        ['element_id' => 'RefinedCarbon', 'amount' => 20.0, 'alternative_group' => 2],
    ])->and($recipe['outputs'])->toBe([
        ['element_id' => 'Iron', 'amount' => 80.0, 'alternative_group' => null],
        ['element_id' => 'SolidSlag', 'amount' => 40.0, 'alternative_group' => null],
    ]);
});

it('keeps the inputs of a recipe made only of any-of ingredients', function (): void {
    $recipe = readFixtureRecipes()['RockCrusher_I_0_O_Sand'];

    expect($recipe['inputs'])->toHaveCount(13)
        ->and(array_unique(array_column($recipe['inputs'], 'alternative_group')))->toBe([1])
        ->and($recipe['inputs'][0])->toBe(['element_id' => 'SuperInsulator', 'amount' => 100.0, 'alternative_group' => 1])
        ->and(array_column($recipe['inputs'], 'element_id'))->toContain('Granite', 'Basalt');
});

it('drops any-of options from unsupported mods and counts them', function (): void {
    $reader = fixtureRecipeReader(supported: []);
    $recipe = collect($reader->records())->keyBy('recipe_id')['CraftingTable_I_0_O_AIO_SimpleDrillbit_Hardened'];

    expect(array_column($recipe['inputs'], 'element_id'))->not->toContain('PhosphorBronze')
        ->and(array_column($recipe['inputs'], 'element_id'))->toContain('Steel')
        ->and($reader->skippedReferences())->toBe(2);
});

it('imports recipes, dropping recipes without an imported fabricator and items that are not elements', function (): void {
    Building::factory()->create(['building_id' => 'AdvancedCraftingTable']);
    Element::factory()->create(['element_id' => 'Katairite']);

    $importer = new RecipeImporter;
    $importer->importRecords(fixtureRecipeReader()->records());

    $recipe = Recipe::query()->with('items')->sole();

    expect($recipe->duration)->toBe(60.0)
        ->and($recipe->inputs)->toHaveCount(1)
        ->and($recipe->inputs->first()->element_id)->toBe('Katairite')
        ->and($recipe->outputs)->toHaveCount(0)
        ->and($importer->skippedRecipes())->toBe(7)
        ->and($importer->skippedReferences())->toBe(1);
});

it('stores the recipe mod id', function (): void {
    Building::factory()->create(['building_id' => 'CraftingTable']);
    Element::factory()->create(['element_id' => 'Bioplastic']);

    $reader = fixtureRecipeReader();
    $ronivanId = Mod::query()->where('workshop_id', '3557584850')->value('id');
    new RecipeImporter()->importRecords($reader->records());

    expect(Recipe::query()->whereRelation('items', 'element_id', 'Bioplastic')->sole()->mod_id)->toBe($ronivanId);
});

it('stores the alternative group of recipe items', function (): void {
    Building::factory()->create(['building_id' => 'MetalRefinery']);
    foreach (['IronOre', 'Carbon', 'Peat', 'Iron'] as $elementId) {
        Element::factory()->create(['element_id' => $elementId]);
    }

    new RecipeImporter()->importRecords(fixtureRecipeReader()->records());

    $inputs = Recipe::query()->sole()->inputs()->orderBy('id')->get();

    expect($inputs->pluck('alternative_group', 'element_id')->all())->toBe(['IronOre' => null, 'Carbon' => 2, 'Peat' => 2]);
});
