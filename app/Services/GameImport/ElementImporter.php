<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Element;
use App\Models\ElementGasProperty;
use App\Models\ElementLiquidProperty;
use App\Models\ElementSolidProperty;
use App\Models\ElementSpecialProperty;
use App\Models\ElementThermalProperty;
use App\Services\GameImport\OniExport\ElementReader;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpstan-import-type ElementRecord from ElementReader
 */
final readonly class ElementImporter
{
    public function __construct(private StringResolver $stringResolver) {}

    public function importYaml(string $yamlPath, string $state): void
    {
        /** @var array{elements: list<array<string, mixed>>} $data */
        $data = Yaml::parseFile($yamlPath);

        $records = [];

        foreach ($data['elements'] as $raw) {
            if ($raw['isDisabled'] ?? false) {
                continue;
            }

            $tags = (array) ($raw['tags'] ?? []);

            $records[] = [
                'element_id' => $raw['elementId'],
                'state' => $state,
                'mod_id' => null,
                'localization_key' => is_string($raw['localizationID'] ?? null) ? $raw['localizationID'] : null,
                'molar_mass' => $raw['molarMass'],
                'toxicity' => $raw['toxicity'] ?? 0,
                'material_category' => $raw['materialCategory'] ?? '',
                'tags' => $tags,
                'low_temp_transition_target' => $raw['lowTempTransitionTarget'] ?? null,
                'high_temp_transition_target' => $raw['highTempTransitionTarget'] ?? null,
                'dlc_id' => ($raw['dlcId'] ?? '') ?: null,
                'ui_color' => null,
                'render_color' => null,
                'substance_texture' => null,
                'uses_caustics' => false,
                'glows' => false,
                'is_metallic' => false,
                'is_opaque_liquid' => false,
                'liquid_scroll_speed' => null,
                'max_mass' => null,
                'terrain_texture' => null,
                'specific_heat_capacity' => $raw['specificHeatCapacity'],
                'thermal_conductivity' => $raw['thermalConductivity'],
                'low_temp' => $raw['lowTemp'] ?? null,
                'high_temp' => $raw['highTemp'] ?? null,
                'default_temperature' => $raw['defaultTemperature'] ?? 300,
                'light_absorption_factor' => $raw['lightAbsorptionFactor'] ?? 0,
                'radiation_absorption_factor' => $raw['radiationAbsorptionFactor'] ?? 0,
                'radiation_per_1000_mass' => $raw['radiationPer1000Mass'] ?? 0,
                'flow' => $raw['flow'] ?? 0,
                'default_pressure' => $raw['defaultPressure'] ?? 0,
                'gas_surface_area_multiplier' => $raw['gasSurfaceAreaMultiplier'] ?? 1,
                'liquid_surface_area_multiplier' => $raw['liquidSurfaceAreaMultiplier'] ?? 1,
                'solid_surface_area_multiplier' => $raw['solidSurfaceAreaMultiplier'] ?? 1,
                'hardness' => $raw['hardness'] ?? null,
                'is_breathable' => in_array('Breathable', $tags, true),
                'is_ore' => in_array('Ore', $tags, true),
                'is_metal' => in_array('Metal', $tags, true),
                'is_refined_metal' => in_array('RefinedMetal', $tags, true),
            ];
        }

        $this->importRecords($records);
    }

    /**
     * @param  list<ElementRecord>  $records
     */
    public function importRecords(array $records): void
    {
        foreach ($records as $record) {
            $localizationKey = $record['localization_key'];

            $element = Element::query()->create([
                'element_id' => $record['element_id'],
                'state' => $record['state'],
                'mod_id' => $record['mod_id'],
                'molar_mass' => $record['molar_mass'],
                'toxicity' => $record['toxicity'],
                'material_category' => $record['material_category'],
                'tags' => $record['tags'],
                'low_temp_transition_target' => $record['low_temp_transition_target'],
                'high_temp_transition_target' => $record['high_temp_transition_target'],
                'name' => ($localizationKey !== null ? $this->stringResolver->resolveToJson($localizationKey) : null) ?? ['en' => $record['element_id']],
                'description' => $this->resolveSibling($localizationKey, 'DESC'),
                'dlc_id' => $record['dlc_id'],
                'ui_color' => $record['ui_color'],
                'render_color' => $record['render_color'],
                'substance_texture' => $record['substance_texture'],
                'uses_caustics' => $record['uses_caustics'],
                'glows' => $record['glows'],
                'is_metallic' => $record['is_metallic'],
                'is_opaque_liquid' => $record['is_opaque_liquid'],
                'liquid_scroll_speed' => $record['liquid_scroll_speed'],
                'max_mass' => $record['max_mass'],
                'terrain_texture' => $record['terrain_texture'],
                'is_disabled' => false,
            ]);

            ElementThermalProperty::query()->create([
                'element_id' => $element->element_id,
                'specific_heat_capacity' => $record['specific_heat_capacity'],
                'thermal_conductivity' => $record['thermal_conductivity'],
                'low_temp' => $record['low_temp'],
                'high_temp' => $record['high_temp'],
                'default_temperature' => $record['default_temperature'],
                'light_absorption_factor' => $record['light_absorption_factor'],
                'radiation_absorption_factor' => $record['radiation_absorption_factor'],
                'radiation_per_1000_mass' => $record['radiation_per_1000_mass'],
            ]);

            $this->createStateProperties($element->element_id, $record);
        }
    }

    /** @return array<string, string>|null */
    private function resolveSibling(?string $nameKey, string $suffix): ?array
    {
        if ($nameKey === null || ! str_ends_with($nameKey, '.NAME')) {
            return null;
        }

        $key = mb_substr($nameKey, 0, -mb_strlen('.NAME')).'.'.$suffix;

        return $this->stringResolver->resolveToJson($key);
    }

    /** @param ElementRecord $record */
    private function createStateProperties(string $elementId, array $record): void
    {
        match ($record['state']) {
            'gas' => ElementGasProperty::query()->create([
                'element_id' => $elementId,
                'flow' => $record['flow'],
                'default_pressure' => $record['default_pressure'] ?? 0,
                'gas_surface_area_multiplier' => $record['gas_surface_area_multiplier'],
                'is_breathable' => $record['is_breathable'],
                'is_toxic' => $record['toxicity'] > 0,
            ]),
            'liquid' => ElementLiquidProperty::query()->create([
                'element_id' => $elementId,
                'flow' => $record['flow'],
                'liquid_surface_area_multiplier' => $record['liquid_surface_area_multiplier'],
            ]),
            'solid' => ElementSolidProperty::query()->create([
                'element_id' => $elementId,
                'solid_surface_area_multiplier' => $record['solid_surface_area_multiplier'],
                'hardness' => $record['hardness'],
                'is_ore' => $record['is_ore'],
                'is_metal' => $record['is_metal'],
                'is_refined_metal' => $record['is_refined_metal'],
            ]),
            'special' => ElementSpecialProperty::query()->create(['element_id' => $elementId]),
        };
    }
}
