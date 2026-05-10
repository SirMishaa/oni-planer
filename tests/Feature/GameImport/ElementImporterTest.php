<?php

declare(strict_types=1);

use App\Models\Element;
use App\Models\ElementGasProperty;
use App\Models\ElementThermalProperty;
use App\Services\GameImport\ElementImporter;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports gas elements from yaml with thermal and gas properties', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    $importer = new ElementImporter($resolver);

    $importer->importYaml(base_path('tests/Fixtures/GameImport/elements_gas.yaml'), 'gas');

    expect(Element::count())->toBe(2)
        ->and(ElementThermalProperty::count())->toBe(2)
        ->and(ElementGasProperty::count())->toBe(2);

    $co2 = Element::where('element_id', 'CarbonDioxide')->first();
    expect($co2)->not->toBeNull()
        ->and($co2->state)->toBe('gas')
        ->and($co2->getTranslation('name', 'en'))->toBe('Carbon Dioxide')
        ->and($co2->thermalProperties->specific_heat_capacity)->toBe(0.846)
        ->and($co2->thermalProperties->thermal_conductivity)->toBe(0.0146)
        ->and($co2->gasProperties->flow)->toBe(0.1)
        ->and($co2->gasProperties->default_pressure)->toBe(139.0)
        ->and($co2->gasProperties->is_breathable)->toBeFalse()
        ->and($co2->low_temp_transition_target)->toBe('LiquidCarbonDioxide');
});

it('sets is_breathable true when element has Breathable tag', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    $importer = new ElementImporter($resolver);

    $importer->importYaml(base_path('tests/Fixtures/GameImport/elements_gas.yaml'), 'gas');

    $oxygen = Element::where('element_id', 'Oxygen')->first();
    expect($oxygen->gasProperties->is_breathable)->toBeTrue();
});
