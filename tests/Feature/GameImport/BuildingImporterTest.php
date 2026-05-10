<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Element;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Services\GameImport\BuildingImporter;
use App\Services\GameImport\RecipeImporter;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Element::create([
        'element_id' => 'Carbon', 'state' => 'solid', 'molar_mass' => 12.0,
        'toxicity' => 0, 'material_category' => 'Mineral', 'tags' => [],
        'name' => ['en' => 'Carbon'], 'is_disabled' => false,
    ]);
    Element::create([
        'element_id' => 'CarbonDioxide', 'state' => 'gas', 'molar_mass' => 44.01,
        'toxicity' => 0, 'material_category' => 'Unbreathable', 'tags' => [],
        'name' => ['en' => 'Carbon Dioxide'], 'is_disabled' => false,
    ]);
});

it('imports buildings from json', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    $importer = new BuildingImporter($resolver);

    $importer->import(base_path('tests/Fixtures/GameImport/buildings.json'));

    expect(Building::count())->toBe(1);
    $building = Building::first();
    expect($building->building_id)->toBe('CoalGenerator')
        ->and($building->category)->toBe('Power')
        ->and($building->power_generation)->toBe(600.0)
        ->and($building->getTranslation('name', 'en'))->toBe('Coal Generator');
});

it('imports recipes with items from json', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    (new BuildingImporter($resolver))->import(base_path('tests/Fixtures/GameImport/buildings.json'));
    (new RecipeImporter())->import(base_path('tests/Fixtures/GameImport/recipes.json'));

    expect(Recipe::count())->toBe(1)
        ->and(RecipeItem::count())->toBe(2);

    $recipe = Recipe::first();
    expect($recipe->inputs)->toHaveCount(1)
        ->and($recipe->outputs)->toHaveCount(1)
        ->and($recipe->inputs->first()->element_id)->toBe('Carbon');
});
