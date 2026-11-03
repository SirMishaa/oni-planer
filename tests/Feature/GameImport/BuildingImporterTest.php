<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Element;
use App\Models\Mod;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Services\GameImport\BuildingImporter;
use App\Services\GameImport\RecipeImporter;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Element::query()->create([
        'element_id' => 'Carbon', 'state' => 'solid', 'molar_mass' => 12.0,
        'toxicity' => 0, 'material_category' => 'Mineral', 'tags' => [],
        'name' => ['en' => 'Carbon'], 'is_disabled' => false,
    ]);
    Element::query()->create([
        'element_id' => 'CarbonDioxide', 'state' => 'gas', 'molar_mass' => 44.01,
        'toxicity' => 0, 'material_category' => 'Unbreathable', 'tags' => [],
        'name' => ['en' => 'Carbon Dioxide'], 'is_disabled' => false,
    ]);
});

it('imports buildings from json', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    $importer = new BuildingImporter($resolver);

    $importer->import(base_path('tests/Fixtures/GameImport/buildings.json'));

    expect(Building::query()->count())->toBe(1);
    $building = Building::query()->first();
    expect($building->building_id)->toBe('CoalGenerator')
        ->and($building->category)->toBe('Power')
        ->and($building->power_generation)->toBe(600.0)
        ->and($building->getTranslation('name', 'en'))->toBe('Coal Generator');
});

it('imports building records with editor columns', function (): void {
    $mod = Mod::query()->create(['workshop_id' => '3557584850', 'static_id' => 'RonivansLegacy_ChemicalProcessing', 'title' => 'Ronivans Legacy']);
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));

    new BuildingImporter($resolver)->importRecords([[
        'building_id' => 'Electrolyzer',
        'category' => 'Oxygen',
        'subcategory' => 'producers',
        'name_localization_id' => 'STRINGS.BUILDINGS.PREFABS.ELECTROLYZER.NAME',
        'power_consumption' => 120.0,
        'power_generation' => null,
        'heat_generation' => 1.25,
        'width' => 2,
        'height' => 2,
        'construction_time' => 30.0,
        'tags' => ['IndustrialMachinery'],
        'dlc_id' => null,
        'materials' => [['material_category' => 'Metal', 'amount' => 200.0]],
        'ports' => [['type' => 'PowerInput', 'x' => 1, 'y' => 0, 'secondary' => false]],
        'build_location_rule' => 'OnFloor',
        'permitted_rotations' => 'Unrotatable',
        'object_layer' => 'Building',
        'scene_layer' => 19,
        'overlay' => 'Oxygen',
        'is_foundation' => false,
        'is_tile' => false,
        'is_utility' => false,
        'drag_build' => false,
        'replaceable' => true,
        'placement_rect' => ['x' => -0.015, 'y' => -0.11, 'w' => 2.135, 'h' => 1.83],
        'mod_id' => $mod->id,
    ]]);

    $building = Building::query()->with(['constructionMaterials', 'mod'])->sole();

    expect($building->subcategory)->toBe('producers')
        ->and($building->ports)->toBe([['type' => 'PowerInput', 'x' => 1, 'y' => 0, 'secondary' => false]])
        ->and($building->build_location_rule)->toBe('OnFloor')
        ->and($building->permitted_rotations)->toBe('Unrotatable')
        ->and($building->object_layer)->toBe('Building')
        ->and($building->scene_layer)->toBe(19)
        ->and($building->overlay)->toBe('Oxygen')
        ->and($building->replaceable)->toBeTrue()
        ->and($building->is_tile)->toBeFalse()
        ->and($building->placement_rect)->toBe(['x' => -0.015, 'y' => -0.11, 'w' => 2.135, 'h' => 1.83])
        ->and($building->heat_generation)->toBe(1.25)
        ->and($building->mod->is($mod))->toBeTrue()
        ->and($building->constructionMaterials)->toHaveCount(1)
        ->and($building->constructionMaterials->first()->material_category)->toBe('Metal')
        ->and($building->constructionMaterials->first()->amount)->toBe(200.0);
});

it('imports recipes with items from json', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new BuildingImporter($resolver)->import(base_path('tests/Fixtures/GameImport/buildings.json'));
    new RecipeImporter()->import(base_path('tests/Fixtures/GameImport/recipes.json'));

    expect(Recipe::query()->count())->toBe(1)
        ->and(RecipeItem::query()->count())->toBe(2);

    $recipe = Recipe::query()->first();
    expect($recipe->inputs)->toHaveCount(1)
        ->and($recipe->outputs)->toHaveCount(1)
        ->and($recipe->inputs->first()->element_id)->toBe('Carbon');
});

it('skips recipe items whose element_id is a crafted object, not a game element', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new BuildingImporter($resolver)->import(base_path('tests/Fixtures/GameImport/buildings.json'));

    $json = json_encode([[
        'building_id' => 'CoalGenerator',
        'duration' => 60,
        'fabricators' => null,
        'name_localization_id' => null,
        'inputs' => [['element_id' => 'Carbon', 'amount' => 1.0]],
        'outputs' => [['element_id' => 'EmptyElectrobank', 'amount' => 1.0]],
    ]]);

    $path = tempnam(sys_get_temp_dir(), 'recipes_test_').'.json';
    file_put_contents($path, $json);

    new RecipeImporter()->import($path);
    unlink($path);

    expect(Recipe::query()->count())->toBe(1)
        ->and(RecipeItem::query()->where('role', 'input')->count())->toBe(1)
        ->and(RecipeItem::query()->where('role', 'output')->count())->toBe(0);
});
