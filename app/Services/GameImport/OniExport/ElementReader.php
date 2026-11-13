<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

use App\Enums\SubstanceTexture;

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
 *     ui_color: int|null,
 *     render_color: int|null,
 *     substance_texture: SubstanceTexture|null,
 *     uses_caustics: bool,
 *     glows: bool,
 *     is_metallic: bool,
 *     is_opaque_liquid: bool,
 *     liquid_scroll_speed: float|null,
 *     max_mass: float|null,
 *     terrain_texture: string|null,
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
final class ElementReader
{
    /** @var list<string> */
    private array $unknownSubstanceTextures = [];

    /** @var list<string> */
    private array $elementsWithoutRender = [];

    public function __construct(private readonly OniExport $export, private readonly ModCatalog $catalog) {}

    /**
     * The substance texture families met by {@see self::records()} that the
     * app does not know; their records store a null texture.
     *
     * @return list<string>
     */
    public function unknownSubstanceTextures(): array
    {
        return $this->unknownSubstanceTextures;
    }

    /**
     * The gas, liquid and solid elements read by {@see self::records()}
     * without a `render` block; the map draws them with a neutral fallback.
     *
     * @return list<string>
     */
    public function elementsWithoutRender(): array
    {
        return $this->elementsWithoutRender;
    }

    /** @return list<ElementRecord> */
    public function records(): array
    {
        $records = [];
        $this->unknownSubstanceTextures = [];
        $this->elementsWithoutRender = [];
        $uiColors = $this->uiColors();

        foreach ($this->export->json('planner_extras.json')['elements'] ?? [] as $elementId => $element) {
            $elementId = (string) $elementId;

            if (($element['disabled'] ?? false) || $this->catalog->isSkipped($elementId)) {
                continue;
            }

            $tags = array_map(strval(...), $element['tags'] ?? []);
            $render = is_array($element['render'] ?? null) ? $element['render'] : null;
            $state = $this->state((string) $element['state']);

            if ($render === null && $state !== 'special') {
                $this->elementsWithoutRender[] = $elementId;
            }

            $records[] = [
                'element_id' => $elementId,
                'state' => $state,
                'mod_id' => $this->catalog->modIdFor($elementId),
                'localization_key' => is_string($element['localizationKey'] ?? null) ? $element['localizationKey'] : null,
                'molar_mass' => (float) $element['molarMass'],
                'toxicity' => (float) ($element['toxicity'] ?? 0),
                'material_category' => (string) ($element['materialCategory'] ?? ''),
                'tags' => $tags,
                'low_temp_transition_target' => $element['lowTempTransitionTarget'] ?? null,
                'high_temp_transition_target' => $element['highTempTransitionTarget'] ?? null,
                'dlc_id' => ($element['dlcId'] ?? '') ?: null,
                'ui_color' => $uiColors[$elementId] ?? null,
                'render_color' => $this->renderColor($render),
                'substance_texture' => $this->substanceTexture($render),
                'uses_caustics' => (bool) ($render['usesCaustics'] ?? false),
                'glows' => (bool) ($render['glows'] ?? false),
                'is_metallic' => (bool) ($render['metallic'] ?? false),
                'is_opaque_liquid' => (bool) ($render['opaqueLiquid'] ?? false),
                'liquid_scroll_speed' => isset($render['scrollSpeed']) ? (float) $render['scrollSpeed'] : null,
                'max_mass' => isset($render['maxMass']) ? (float) $render['maxMass'] : null,
                'terrain_texture' => is_string($render['terrainTexture'] ?? null) && $render['terrainTexture'] !== '' ? $render['terrainTexture'] : null,
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
     * @param  array<string, mixed>|null  $render
     */
    private function renderColor(?array $render): ?int
    {
        $color = $render['color'] ?? null;

        if (! is_string($color) || preg_match('/^#[0-9a-f]{8}$/i', $color) !== 1) {
            return null;
        }

        return (int) hexdec(mb_substr($color, 1));
    }

    /**
     * @param  array<string, mixed>|null  $render
     */
    private function substanceTexture(?array $render): ?SubstanceTexture
    {
        $name = $render['substanceTexture'] ?? null;

        if (! is_string($name)) {
            return null;
        }

        $texture = SubstanceTexture::tryFrom($name);

        if ($texture === null && ! in_array($name, $this->unknownSubstanceTextures, true)) {
            $this->unknownSubstanceTextures[] = $name;
        }

        return $texture;
    }

    /**
     * UI colours (24-bit 0xRRGGBB) by element id from `elements.json`; empty
     * when the export has no such file.
     *
     * @return array<string, int>
     */
    private function uiColors(): array
    {
        try {
            $table = $this->export->json('elements.json')['elementTable'] ?? [];
        } catch (OniExportMissing) {
            return [];
        }

        $colors = [];

        foreach ($table as $entry) {
            if (isset($entry['id'], $entry['uiColor'])) {
                $colors[(string) $entry['id']] = (int) $entry['uiColor'];
            }
        }

        return $colors;
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
