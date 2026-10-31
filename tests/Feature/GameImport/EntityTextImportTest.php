<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Critter;
use App\Models\Element;
use App\Models\Plant;
use App\Services\GameImport\BuildingImporter;
use App\Services\GameImport\CritterImporter;
use App\Services\GameImport\ElementImporter;
use App\Services\GameImport\PlantImporter;
use App\Services\GameImport\StringResolver;

it('imports description and effect for a building from suffix-derived keys', function (): void {
    $json = [[
        'building_id' => 'TestBuilding',
        'category' => 'Oxygen',
        'width' => 2,
        'height' => 2,
        'tags' => [],
        'name_localization_id' => 'STRINGS.BUILDINGS.PREFABS.TESTBUILDING.NAME',
    ]];
    $path = sys_get_temp_dir().'/test_buildings.json';
    file_put_contents($path, json_encode($json));

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new BuildingImporter($resolver)->import($path);

    $building = Building::query()->where('building_id', 'TestBuilding')->sole();

    expect($building->getTranslation('description', 'en'))->toBe('A building for tests.')
        ->and($building->getTranslation('effect', 'en'))->toBe('Does a test thing.');
});

it('imports description for an element from the YAML localization id', function (): void {
    $yaml = sys_get_temp_dir().'/test_elements_gas.yaml';
    file_put_contents($yaml, <<<'YAML'
    elements:
      - elementId: Oxygen
        molarMass: 32.0
        toxicity: 0
        materialCategory: Gas
        tags: []
        localizationID: STRINGS.ELEMENTS.OXYGEN.NAME
        specificHeatCapacity: 1.0
        thermalConductivity: 1.0
        defaultTemperature: 300
    YAML);

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new ElementImporter($resolver)->importYaml($yaml, 'gas');

    expect(Element::query()->where('element_id', 'Oxygen')->sole()->getTranslation('description', 'en'))
        ->toBe('A breathable gas.');
});

it('imports the species description for a base critter morph', function (): void {
    $json = [[
        'critter_id' => 'TestHatch',
        'parent_critter_id' => 'TestHatch',
        'is_base' => true,
        'min_temp' => 243.0,
        'max_temp' => 343.0,
        'calories_per_cycle' => 1000.0,
        'incubation_time' => 20.0,
        'lifespan' => 100.0,
        'overcrowding_threshold' => 8,
        'name_localization_id' => 'STRINGS.CREATURES.SPECIES.TESTHATCH.NAME',
        'diets' => [],
        'outputs' => [],
    ]];
    $path = sys_get_temp_dir().'/test_critters.json';
    file_put_contents($path, json_encode($json));

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new CritterImporter($resolver)->import($path);

    expect(Critter::query()->where('critter_id', 'TestHatch')->sole()->getTranslation('description', 'en'))
        ->toBe('A test digger.');
});

it('imports the species description for a base plant variant', function (): void {
    $json = [[
        'plant_id' => 'TestMealwood',
        'variant_id' => 'TestMealwood',
        'is_base' => true,
        'min_temp' => 243.0,
        'max_temp' => 343.0,
        'min_pressure' => 0.0,
        'max_pressure' => 2.0,
        'atmosphere_element_id' => null,
        'light_required' => false,
        'growth_time' => 1200.0,
        'name_localization_id' => 'STRINGS.CREATURES.SPECIES.TESTMEALWOOD.NAME',
        'inputs' => [],
        'outputs' => [],
    ]];
    $path = sys_get_temp_dir().'/test_plants.json';
    file_put_contents($path, json_encode($json));

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new PlantImporter($resolver)->import($path);

    expect(Plant::query()->where('plant_id', 'TestMealwood')->sole()->getTranslation('description', 'en'))
        ->toBe('A test crop.');
});

it('resolves plant variant name and species description from a nested-type name key', function (): void {
    $json = [[
        'plant_id' => 'TestMealwood',
        'variant_id' => 'TestMealwood',
        'is_base' => true,
        'min_temp' => 243.0,
        'max_temp' => 343.0,
        'min_pressure' => 0.0,
        'max_pressure' => 2.0,
        'atmosphere_element_id' => null,
        'light_required' => false,
        'growth_time' => 1200.0,
        'name_localization_id' => 'STRINGS.CREATURES/SPECIES/TESTMEALWOOD::NAME',
        'inputs' => [],
        'outputs' => [],
    ]];
    $path = sys_get_temp_dir().'/test_plants_nested.json';
    file_put_contents($path, json_encode($json));

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new PlantImporter($resolver)->import($path);

    $plant = Plant::query()->where('plant_id', 'TestMealwood')->sole();
    expect($plant->variants->sole()->getTranslation('name', 'en'))->toBe('Test Mealwood')
        ->and($plant->getTranslation('description', 'en'))->toBe('A test crop.');
});
