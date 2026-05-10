<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Element;
use App\Models\ElementGasProperty;
use App\Models\ElementLiquidProperty;
use App\Models\ElementSolidProperty;
use App\Models\ElementSpecialProperty;
use App\Models\ElementThermalProperty;
use Symfony\Component\Yaml\Yaml;

final readonly class ElementImporter
{
    public function __construct(private StringResolver $stringResolver) {}

    public function importYaml(string $yamlPath, string $state): void
    {
        /** @var array{elements: list<array<string, mixed>>} $data */
        $data = Yaml::parseFile($yamlPath);

        foreach ($data['elements'] as $raw) {
            if ($raw['isDisabled'] ?? false) {
                continue;
            }

            $nameJson = is_string($raw['localizationID'] ?? null)
                ? ($this->stringResolver->resolveToJson($raw['localizationID']) ?? ['en' => $raw['elementId']])
                : ['en' => $raw['elementId']];

            $element = Element::query()->create([
                'element_id' => $raw['elementId'],
                'state' => $state,
                'molar_mass' => $raw['molarMass'],
                'toxicity' => $raw['toxicity'] ?? 0,
                'material_category' => $raw['materialCategory'] ?? '',
                'tags' => $raw['tags'] ?? [],
                'low_temp_transition_target' => $raw['lowTempTransitionTarget'] ?? null,
                'high_temp_transition_target' => $raw['highTempTransitionTarget'] ?? null,
                'name' => $nameJson,
                'dlc_id' => ($raw['dlcId'] ?? '') ?: null,
                'is_disabled' => false,
            ]);

            ElementThermalProperty::query()->create([
                'element_id' => $element->element_id,
                'specific_heat_capacity' => $raw['specificHeatCapacity'],
                'thermal_conductivity' => $raw['thermalConductivity'],
                'low_temp' => $raw['lowTemp'] ?? null,
                'high_temp' => $raw['highTemp'] ?? null,
                'default_temperature' => $raw['defaultTemperature'] ?? 300,
                'light_absorption_factor' => $raw['lightAbsorptionFactor'] ?? 0,
                'radiation_absorption_factor' => $raw['radiationAbsorptionFactor'] ?? 0,
                'radiation_per_1000_mass' => $raw['radiationPer1000Mass'] ?? 0,
            ]);

            $this->createStateProperties($element->element_id, $state, $raw);
        }
    }

    /** @param array<string, mixed> $raw */
    private function createStateProperties(string $elementId, string $state, array $raw): void
    {
        $tags = (array) ($raw['tags'] ?? []);

        match ($state) {
            'gas' => ElementGasProperty::query()->create([
                'element_id' => $elementId,
                'flow' => $raw['flow'] ?? 0,
                'default_pressure' => $raw['defaultPressure'] ?? 0,
                'gas_surface_area_multiplier' => $raw['gasSurfaceAreaMultiplier'] ?? 1,
                'is_breathable' => in_array('Breathable', $tags, true),
                'is_toxic' => ($raw['toxicity'] ?? 0) > 0,
            ]),
            'liquid' => ElementLiquidProperty::query()->create([
                'element_id' => $elementId,
                'flow' => $raw['flow'] ?? 0,
                'liquid_surface_area_multiplier' => $raw['liquidSurfaceAreaMultiplier'] ?? 1,
            ]),
            'solid' => ElementSolidProperty::query()->create([
                'element_id' => $elementId,
                'solid_surface_area_multiplier' => $raw['solidSurfaceAreaMultiplier'] ?? 1,
                'hardness' => $raw['hardness'] ?? null,
                'is_ore' => in_array('Ore', $tags, true),
                'is_metal' => in_array('Metal', $tags, true),
                'is_refined_metal' => in_array('RefinedMetal', $tags, true),
            ]),
            'special' => ElementSpecialProperty::query()->create(['element_id' => $elementId]),
            default => null,
        };
    }
}
