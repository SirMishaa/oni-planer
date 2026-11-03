<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

use App\Enums\BuildLocationRule;
use App\Enums\PermittedRotations;

/**
 * Turns `building.json` (and the `planner_extras.json` building section) into
 * building records for {@see \App\Services\GameImport\BuildingImporter::importRecords()}.
 *
 * Only buildings a player can place are kept: shown in the build menu or in
 * the rocket module menu, not deprecated, not debug-only, and not owned by an
 * unsupported mod.
 *
 * @phpstan-type Port array{type: string, x: int, y: int, secondary: bool}
 * @phpstan-type BuildingRecord array{
 *     building_id: string,
 *     category: string,
 *     subcategory: string|null,
 *     name_localization_id: string,
 *     power_consumption: float|null,
 *     power_generation: float|null,
 *     heat_generation: float,
 *     width: int,
 *     height: int,
 *     construction_time: float,
 *     tags: list<string>,
 *     dlc_id: string|null,
 *     materials: list<array{material_category: string, amount: float}>,
 *     ports: list<Port>,
 *     build_location_rule: string,
 *     permitted_rotations: string,
 *     object_layer: string|null,
 *     scene_layer: int,
 *     overlay: string|null,
 *     is_foundation: bool,
 *     is_tile: bool,
 *     is_utility: bool,
 *     drag_build: bool,
 *     replaceable: bool,
 *     placement_rect: array{x: float, y: float, w: float, h: float}|null,
 *     mod_id: int|null,
 * }
 */
final readonly class BuildingReader
{
    /**
     * Export `viewMode` (game overlay name) → planner overlay, as in
     * blueprintnotincluded's `convert-export-2024.ts`.
     */
    private const array OVERLAYS = [
        'Power' => 'Power',
        'LiquidConduit' => 'Liquid',
        'GasConduit' => 'Gas',
        'Logic' => 'Automation',
        'Oxygen' => 'Oxygen',
        'SolidConveyor' => 'Conveyor',
        'Decor' => 'Decor',
        'Light' => 'Light',
        'Temperature' => 'Temperature',
        'Rooms' => 'Room',
        'Radiation' => 'Unknown',
        'Disease' => 'Unknown',
        'Crop' => 'Unknown',
    ];

    /** Export build menu category name → stored category label (the Buildings page keys). */
    private const array CATEGORY_LABELS = ['hvac' => 'HVAC', 'hep' => 'HEP'];

    /**
     * Placement of rocket modules, which the export lists in the flat root
     * `rocketModuleMenu` (no subcategories) instead of the build menu pairs.
     * `module` resolves to `STRINGS.UI.NEWBUILDCATEGORIES.MODULE.BUILDMENUTITLE`.
     */
    private const array ROCKET_MODULE_PLACEMENT = ['category' => 'rocketry', 'subcategory' => 'module'];

    public function __construct(private OniExport $export, private ModCatalog $mods) {}

    /** @return list<BuildingRecord> */
    public function read(): array
    {
        $file = $this->export->json('building.json');
        $extras = $this->export->json('planner_extras.json')['buildings'] ?? [];
        $menuPlacement = $this->menuPlacement($file['buildingAndSubcategoryDataPairs'] ?? [], $file['rocketModuleMenu'] ?? []);
        /** @var list<string> $objectLayerNames */
        $objectLayerNames = $file['objectLayerNames'] ?? [];

        $records = [];

        foreach ($file['bBuildingDefList'] as $def) {
            $buildingId = (string) $def['name'];

            if (! $this->isBuildable($def, isset($menuPlacement[$buildingId])) || $this->mods->isSkipped($buildingId)) {
                continue;
            }

            $records[] = $this->record($def, $extras[$buildingId] ?? null, $menuPlacement[$buildingId] ?? null, $objectLayerNames);
        }

        return $records;
    }

    /**
     * Rocket modules are buildable although the export flags them `showInBuildMenu = false`.
     *
     * @param  array<string, mixed>  $def
     */
    private function isBuildable(array $def, bool $isInAMenu): bool
    {
        return (($def['showInBuildMenu'] ?? false) || $isInAMenu) && ! ($def['deprecated'] ?? false) && ! ($def['debugOnly'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, float>|null  $extras
     * @param  array{category: string, subcategory: string}|null  $placement
     * @param  list<string>  $objectLayerNames
     * @return BuildingRecord
     */
    private function record(array $def, ?array $extras, ?array $placement, array $objectLayerNames): array
    {
        $buildingId = (string) $def['name'];
        $wattage = $def['energyConsumer']['baseWattageRating'] ?? null;

        return [
            'building_id' => $buildingId,
            'category' => $placement !== null ? $this->categoryLabel($placement['category']) : 'Other',
            'subcategory' => $placement['subcategory'] ?? null,
            'name_localization_id' => 'STRINGS.BUILDINGS.PREFABS.'.mb_strtoupper($buildingId).'.NAME',
            'power_consumption' => $wattage !== null && $wattage > 0 ? (float) $wattage : null,
            'power_generation' => $this->powerGeneration($def, $extras),
            'heat_generation' => (float) (($extras['selfHeatKilowattsWhenActive'] ?? 0) + ($extras['exhaustKilowattsWhenActive'] ?? 0)),
            'width' => (int) $def['widthInCells'],
            'height' => (int) $def['heightInCells'],
            'construction_time' => (float) ($extras['constructionTime'] ?? 0),
            'tags' => array_values(array_map(fn (array $tag): string => (string) $tag['Name'], $def['tags'] ?? [])),
            'dlc_id' => $this->dlcId($def),
            'materials' => $this->materials($def),
            'ports' => $this->ports($def),
            'build_location_rule' => BuildLocationRule::from((int) $def['buildLocationRule'])->name,
            'permitted_rotations' => PermittedRotations::from((int) $def['permittedRotations'])->name,
            'object_layer' => $objectLayerNames[(int) $def['objectLayer']] ?? null,
            'scene_layer' => (int) $def['sceneLayer'],
            'overlay' => self::OVERLAYS[$def['viewMode'] ?? ''] ?? null,
            'is_foundation' => (bool) $def['isFoundation'],
            'is_tile' => (bool) $def['isKAnimTile'],
            'is_utility' => (bool) $def['isUtility'],
            'drag_build' => (bool) $def['dragBuild'],
            'replaceable' => (bool) $def['replaceable'],
            'placement_rect' => $this->placementRect($def['uiImageRect'] ?? null),
            'mod_id' => $this->mods->modIdFor($buildingId),
        ];
    }

    /**
     * @param  array<string, list<array{Key: string, Value: string}>>  $pairs
     * @param  list<string>  $rocketModules
     * @return array<string, array{category: string, subcategory: string}>
     */
    private function menuPlacement(array $pairs, array $rocketModules): array
    {
        $placement = array_fill_keys($rocketModules, self::ROCKET_MODULE_PLACEMENT);

        foreach ($pairs as $category => $entries) {
            foreach ($entries as $entry) {
                $placement[$entry['Key']] = ['category' => (string) $category, 'subcategory' => $entry['Value']];
            }
        }

        return $placement;
    }

    private function categoryLabel(string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? ucfirst($category);
    }

    /**
     * Generator wattage from planner extras; null for buildings that neither
     * generate power nor have a generator component.
     *
     * @param  array<string, mixed>  $def
     * @param  array<string, float>|null  $extras
     */
    private function powerGeneration(array $def, ?array $extras): ?float
    {
        $wattage = $extras['generatorWattageRating'] ?? null;

        if ($wattage === null || ($wattage <= 0 && ($def['energyGenerator'] ?? null) === null)) {
            return null;
        }

        return (float) $wattage;
    }

    /** @param array<string, mixed> $def */
    private function dlcId(array $def): ?string
    {
        foreach ($def['kPrefabID']['requiredDlcIds'] ?? [] as $dlcId) {
            if ($dlcId !== '') {
                return (string) $dlcId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $def
     * @return list<array{material_category: string, amount: float}>
     */
    private function materials(array $def): array
    {
        $materials = [];

        foreach ($def['materialCategory'] ?? [] as $index => $category) {
            $materials[] = ['material_category' => (string) $category, 'amount' => (float) ($def['materialMass'][$index] ?? 0)];
        }

        return $materials;
    }

    /**
     * Every connection point of the building, power included: the export's
     * `utilities` already lists `PowerInput` / `PowerOutput` at
     * `powerInputOffset` / `powerOutputOffset`.
     *
     * @param  array<string, mixed>  $def
     * @return list<Port>
     */
    private function ports(array $def): array
    {
        return array_values(array_map(fn (array $utility): array => [
            'type' => (string) $utility['type'],
            'x' => (int) round($utility['offset']['x']),
            'y' => (int) round($utility['offset']['y']),
            'secondary' => (bool) $utility['isSecondary'],
        ], $def['utilities'] ?? []));
    }

    /**
     * @param  array{x: float, y: float, w: float, h: float}|null  $rect
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private function placementRect(?array $rect): ?array
    {
        if ($rect === null) {
            return null;
        }

        return ['x' => (float) $rect['x'], 'y' => (float) $rect['y'], 'w' => (float) $rect['w'], 'h' => (float) $rect['h']];
    }
}
