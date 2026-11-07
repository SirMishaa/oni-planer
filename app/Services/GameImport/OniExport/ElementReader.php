<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

/**
 * Turns the `elements` map of `planner_extras.json` into element records for
 * {@see \App\Services\GameImport\ElementImporter::importRecords()}.
 *
 * The export holds the runtime values, so elements a mod re-enables are kept
 * and disabled ones are skipped. Elements owned by an unsupported mod are
 * skipped too. The export state `Vacuum` is stored as `special`, the state of
 * the YAML `special.yaml` elements (Vacuum, Void, COMPOSITION). Transition
 * temperatures without a transition target are stored as null.
 *
 * @phpstan-type ElementRecord array{
 *     element_id: string,
 *     state: 'gas'|'liquid'|'solid'|'special',
 *     mod_id: int|null,
 *     localization_key: string|null,
 *     molar_mass: float,
 *     toxicity: float,
 *     material_category: string,
 *     tags: list<string>,
 *     low_temp_transition_target: string|null,
 *     high_temp_transition_target: string|null,
 *     dlc_id: string|null,
 *     specific_heat_capacity: float,
 *     thermal_conductivity: float,
 *     low_temp: float|null,
 *     high_temp: float|null,
 *     default_temperature: float,
 *     light_absorption_factor: float,
 *     radiation_absorption_factor: float,
 *     radiation_per_1000_mass: float,
 *     flow: float,
 *     default_pressure: float|null,
 *     gas_surface_area_multiplier: float,
 *     liquid_surface_area_multiplier: float,
 *     solid_surface_area_multiplier: float,
 *     hardness: int|null,
 *     is_breathable: bool,
 *     is_ore: bool,
 *     is_metal: bool,
 *     is_refined_metal: bool,
 * }
 */
final readonly class ElementReader
{
    public function __construct(private OniExport $export, private ModCatalog $catalog) {}

    /** @return list<ElementRecord> */
    public function records(): array
    {
        $records = [];

        foreach ($this->export->json('planner_extras.json')['elements'] ?? [] as $elementId => $element) {
            $elementId = (string) $elementId;

            if (($element['disabled'] ?? false) || $this->catalog->isSkipped($elementId)) {
                continue;
            }

            $tags = array_map(strval(...), $element['tags'] ?? []);

            $records[] = [
                'element_id' => $elementId,
                'state' => $this->state((string) $element['state']),
                'mod_id' => $this->catalog->modIdFor($elementId),
                'localization_key' => is_string($element['localizationKey'] ?? null) ? $element['localizationKey'] : null,
                'molar_mass' => (float) $element['molarMass'],
                'toxicity' => (float) ($element['toxicity'] ?? 0),
                'material_category' => (string) ($element['materialCategory'] ?? ''),
                'tags' => $tags,
                'low_temp_transition_target' => $element['lowTempTransitionTarget'] ?? null,
                'high_temp_transition_target' => $element['highTempTransitionTarget'] ?? null,
                'dlc_id' => ($element['dlcId'] ?? '') ?: null,
                'specific_heat_capacity' => (float) $element['specificHeatCapacity'],
                'thermal_conductivity' => (float) $element['thermalConductivity'],
                'low_temp' => $this->transitionTemperature($element, 'lowTemp', 'lowTempTransitionTarget'),
                'high_temp' => $this->transitionTemperature($element, 'highTemp', 'highTempTransitionTarget'),
                'default_temperature' => (float) ($element['defaultTemperature'] ?? 300),
                'light_absorption_factor' => (float) ($element['lightAbsorptionFactor'] ?? 0),
                'radiation_absorption_factor' => (float) ($element['radiationAbsorptionFactor'] ?? 0),
                'radiation_per_1000_mass' => (float) ($element['radiationPer1000Mass'] ?? 0),
                'flow' => (float) ($element['flow'] ?? 0),
                'default_pressure' => isset($element['defaultPressure']) ? (float) $element['defaultPressure'] : null,
                'gas_surface_area_multiplier' => (float) ($element['gasSurfaceAreaMultiplier'] ?? 1),
                'liquid_surface_area_multiplier' => (float) ($element['liquidSurfaceAreaMultiplier'] ?? 1),
                'solid_surface_area_multiplier' => (float) ($element['solidSurfaceAreaMultiplier'] ?? 1),
                'hardness' => isset($element['hardness']) ? (int) $element['hardness'] : null,
                'is_breathable' => in_array('Breathable', $tags, true),
                'is_ore' => in_array('Ore', $tags, true),
                'is_metal' => in_array('Metal', $tags, true),
                'is_refined_metal' => in_array('RefinedMetal', $tags, true),
            ];
        }

        return $records;
    }

    /**
     * The export holds the runtime defaults (0 K and 10000 K) for elements
     * without a transition, so a temperature only counts when it has a target.
     *
     * @param  array<string, mixed>  $element
     */
    private function transitionTemperature(array $element, string $temperatureKey, string $targetKey): ?float
    {
        if (! isset($element[$targetKey], $element[$temperatureKey])) {
            return null;
        }

        return (float) $element[$temperatureKey];
    }

    /** @return 'gas'|'liquid'|'solid'|'special' */
    private function state(string $exportState): string
    {
        return match ($exportState) {
            'Gas' => 'gas',
            'Liquid' => 'liquid',
            'Solid' => 'solid',
            default => 'special',
        };
    }
}
