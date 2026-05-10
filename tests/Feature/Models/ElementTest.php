<?php

declare(strict_types=1);

use App\Models\Element;
use App\Models\ElementGasProperty;
use App\Models\ElementThermalProperty;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a gas element with thermal and gas properties', function (): void {
    $element = Element::create([
        'element_id' => 'CarbonDioxide',
        'state' => 'gas',
        'molar_mass' => 44.01,
        'toxicity' => 0.0001,
        'material_category' => 'Unbreathable',
        'tags' => [],
        'name' => ['en' => 'Carbon Dioxide'],
        'is_disabled' => false,
    ]);

    ElementThermalProperty::create([
        'element_id' => 'CarbonDioxide',
        'specific_heat_capacity' => 0.846,
        'thermal_conductivity' => 0.0146,
        'default_temperature' => 300.0,
        'light_absorption_factor' => 0.1,
        'radiation_absorption_factor' => 0.08,
        'radiation_per_1000_mass' => 0.0,
    ]);

    ElementGasProperty::create([
        'element_id' => 'CarbonDioxide',
        'flow' => 0.1,
        'default_pressure' => 139.0,
        'gas_surface_area_multiplier' => 1.0,
        'is_breathable' => false,
        'is_toxic' => false,
    ]);

    expect($element->thermalProperties)->not->toBeNull()
        ->and($element->thermalProperties->specific_heat_capacity)->toBe(0.846)
        ->and($element->gasProperties->is_breathable)->toBeFalse()
        ->and($element->getTranslation('name', 'en'))->toBe('Carbon Dioxide');
});

it('resolves temperature transitions between elements', function (): void {
    Element::create([
        'element_id' => 'Ice',
        'state' => 'solid',
        'molar_mass' => 18.0,
        'toxicity' => 0.0,
        'material_category' => 'Water',
        'tags' => [],
        'name' => ['en' => 'Ice'],
        'high_temp_transition_target' => 'Water',
        'is_disabled' => false,
    ]);

    Element::create([
        'element_id' => 'Water',
        'state' => 'liquid',
        'molar_mass' => 18.0,
        'toxicity' => 0.0,
        'material_category' => 'Water',
        'tags' => [],
        'name' => ['en' => 'Water'],
        'is_disabled' => false,
    ]);

    $ice = Element::where('element_id', 'Ice')->first();
    expect($ice->high_temp_transition_target)->toBe('Water');
});
