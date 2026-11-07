<?php

declare(strict_types=1);

use App\Models\Element;
use App\Models\RocketEngine;
use App\Models\RocketModule;
use App\Services\GameImport\OniExport\ModCatalog;
use App\Services\GameImport\OniExport\OniExport;
use App\Services\GameImport\OniExport\RocketReader;
use App\Services\GameImport\RocketImporter;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fixtureRocketReader(): RocketReader
{
    $root = base_path('tests/Fixtures/OniExport');
    $export = new OniExport($root);

    return new RocketReader($export, ModCatalog::fromExport($export, $root.'/mods', ['3557584850']));
}

it('reads the co2 engine', function (): void {
    $engine = collect(fixtureRocketReader()->records()['engines'])->keyBy('engine_id')['CO2Engine'];

    expect($engine['fuel_element_id'])->toBe('CarbonDioxide')
        ->and($engine['oxidizer_element_id'])->toBeNull()
        ->and($engine['exhaust_temperature'])->toBe(245.0)
        ->and($engine['fuel_consumption_rate'])->toBe(0.02777778)
        ->and($engine['max_range'])->toBe(0.0)
        ->and($engine['oxidizer_consumption_rate'])->toBeNull()
        ->and($engine['name_localization_id'])->toBe('STRINGS.BUILDINGS.PREFABS.CO2ENGINE.NAME')
        ->and($engine['dlc_id'])->toBe('EXPANSION1_ID');
});

it('drops the fuel owned by an unsupported mod and counts it', function (): void {
    $reader = fixtureRocketReader();
    $engine = collect($reader->records()['engines'])->keyBy('engine_id')['NoteEngine'];

    expect($engine['fuel_element_id'])->toBeNull()
        ->and($reader->skippedReferences())->toBe(1);
});

it('reads module type, mass and capacity from the building def', function (): void {
    $modules = collect(fixtureRocketReader()->records()['modules'])->keyBy('module_id');

    expect($modules->keys()->all())->toBe(['CargoBayCluster', 'OxidizerTankLiquidCluster'])
        ->and($modules['CargoBayCluster']['module_type'])->toBe('cargo_solid')
        ->and($modules['CargoBayCluster']['mass'])->toBe(1000.0)
        ->and($modules['CargoBayCluster']['capacity'])->toBe(27000.0)
        ->and($modules['CargoBayCluster']['power_consumption'])->toBeNull()
        ->and($modules['OxidizerTankLiquidCluster']['module_type'])->toBe('oxidizer')
        ->and($modules['OxidizerTankLiquidCluster']['capacity'])->toBe(450.0);
});

it('imports rocket records and nulls fuel that is not an imported element', function (): void {
    Element::query()->create([
        'element_id' => 'CarbonDioxide', 'state' => 'gas', 'molar_mass' => 1.0,
        'toxicity' => 0, 'material_category' => 'Gas', 'tags' => [],
        'name' => ['en' => 'CarbonDioxide'], 'is_disabled' => false,
    ]);

    $importer = new RocketImporter(new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot')));
    $importer->importRecords(fixtureRocketReader()->records());

    expect(RocketEngine::query()->count())->toBe(2)
        ->and(RocketEngine::query()->where('engine_id', 'CO2Engine')->value('fuel_element_id'))->toBe('CarbonDioxide')
        ->and(RocketModule::query()->where('module_id', 'CargoBayCluster')->value('capacity'))->toBe(27000.0)
        ->and($importer->skippedReferences())->toBe(0);
});
