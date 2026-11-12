<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Data\ZoneBoxData;
use App\Data\ZoneBuildingData;
use App\Data\ZoneCountsData;
use App\Data\ZoneData;
use App\Data\ZoneEntityData;
use App\Data\ZoneMapData;
use App\Data\ZonePlantData;
use App\Data\ZoneShowData;
use App\Data\ZoneTerrainCellData;
use App\Data\ZoneTileData;
use App\Models\Element;
use App\Models\Zone;
use App\Services\GameText;

/**
 * Turns the stored layout, slow state and fast state of a zone into what the
 * zone page shows: the map layers placed on cells, one entity per plant and
 * building with its state and problems, display names in the current locale
 * and sprite URLs. Cells are the game's indices: x = cell % gridWidth,
 * y = intdiv(cell, gridWidth), y up. A section the game could not read (JSON
 * null) stays null: unknown, never an empty list. Malformed entries are read
 * with defaults rather than failing the page.
 */
final readonly class ZonePresenter
{
    /**
     * Status item ids that make a building (or, later, a plant) a problem.
     * Taken from the game's Assembly-CSharp (build of 2026-07, ilspycmd):
     * - `MaterialsUnavailable`, `MaterialsUnavailableForRefill`: set by
     *   FetchListStatusItemUpdater on a ManualDeliveryKG that cannot be
     *   filled (the farm station short of fertilizer);
     * - `Broken`, `NeedPower`, `NotEnoughPower`, `Entombed`, `Flooded`,
     *   `MissingFoundation`: Database.BuildingStatusItems that stop a building;
     * - `Wilting`, `WiltingDomestic`, `WiltingNonGrowing`, `NeedsFertilizer`,
     *   `NeedsIrrigation`: Database.CreatureStatusItems of a plant that
     *   stopped growing (plants carry no status items in the fast state yet;
     *   their `wilting` flag is the rule today).
     * To be refined with real fast states (slice A, task S7).
     *
     * @var list<string>
     */
    public const array PROBLEM_STATUS_IDS = [
        'MaterialsUnavailable',
        'MaterialsUnavailableForRefill',
        'Broken',
        'NeedPower',
        'NotEnoughPower',
        'Entombed',
        'Flooded',
        'MissingFoundation',
        'Wilting',
        'WiltingDomestic',
        'WiltingNonGrowing',
        'NeedsFertilizer',
        'NeedsIrrigation',
    ];

    /** Game seconds in a cycle; a plant's maturity is counted in cycles. */
    private const float CYCLE_SECONDS = 600.0;

    /** Connection sprite mask bits (OniExtract ExportConnectionSprites). */
    private const int LEFT = 1;

    private const int RIGHT = 2;

    private const int UP = 4;

    private const int DOWN = 8;

    public function __construct(private GameText $text) {}

    public function present(Zone $zone): ZoneShowData
    {
        $layout = $zone->layout;
        $gridWidth = self::int($layout['gridWidth'] ?? null, 0);
        $placed = $layout !== null && $gridWidth > 0;
        $position = fn (mixed $cell): ?array => $placed && is_numeric($cell)
            ? [(int) $cell % $gridWidth, intdiv((int) $cell, $gridWidth)]
            : null;

        $fastPlants = self::listOrNull($zone->fast_state['plants'] ?? null);
        $fastBuildings = self::listOrNull($zone->fast_state['buildings'] ?? null);
        $layoutBuildings = self::listOrNull($layout['buildings'] ?? null);

        $entities = [
            ...array_map(fn (array $plant): ZoneEntityData => $this->plantEntity($plant, $position($plant['cell'] ?? null)), $fastPlants ?? []),
            ...array_map(fn (array $building): ZoneEntityData => $this->buildingEntity($building, $layoutBuildings ?? [], $position), $fastBuildings ?? []),
        ];
        usort($entities, fn (ZoneEntityData $a, ZoneEntityData $b): int => [$a->problems === [], $a->secondsUntilHarvest ?? INF] <=> [$b->problems === [], $b->secondsUntilHarvest ?? INF]);
        $problems = array_values(array_filter($entities, fn (ZoneEntityData $entity): bool => $entity->problems !== []));

        return new ZoneShowData(
            zone: new ZoneData(
                id: $zone->id,
                name: $zone->name,
                worldId: $zone->world_id,
                version: $zone->version,
                cellCount: $zone->cell_count,
                orphaned: $zone->orphaned,
                layoutAt: $zone->layout_at?->toIso8601String(),
                slowStateAt: $zone->slow_state_at?->toIso8601String(),
                fastStateAt: $zone->fast_state_at?->toIso8601String(),
            ),
            map: $placed ? new ZoneMapData(
                gridWidth: $gridWidth,
                box: self::box($layout['box'] ?? null),
                zoneCells: array_values(array_filter(array_map($position, (array) ($layout['cells'] ?? [])))),
                terrain: $this->terrain($zone->slow_state),
                tiles: self::tiles(self::listOrNull($layout['tiles'] ?? null), $gridWidth),
                buildings: $layoutBuildings === null ? null : array_map(fn (array $building): ZoneBuildingData => $this->layoutBuilding($building, $gridWidth), $layoutBuildings),
                plants: $fastPlants === null ? null : array_map(fn (array $plant): ZonePlantData => $this->plant($plant, $gridWidth), $fastPlants),
            ) : null,
            entities: $entities,
            problems: $problems,
            counts: new ZoneCountsData(
                plants: count($fastPlants ?? []),
                buildings: count($fastBuildings ?? []),
                problems: count($problems),
            ),
            plantsKnown: $fastPlants !== null,
            buildingStatesKnown: $fastBuildings !== null,
        );
    }

    /** Entities of the zone with at least one problem; null until a fast state arrived. */
    public function problemCount(Zone $zone): ?int
    {
        if ($zone->fast_state === null) {
            return null;
        }

        $plants = array_filter(self::listOrNull($zone->fast_state['plants'] ?? null) ?? [], fn (array $plant): bool => $this->plantProblems($plant) !== []);
        $buildings = array_filter(self::listOrNull($zone->fast_state['buildings'] ?? null) ?? [], fn (array $building): bool => $this->buildingProblems($building) !== []);

        return count($plants) + count($buildings);
    }

    /**
     * Progress to the next harvest, from the remaining time, the growth rate
     * (maturity per second, maturity counted in cycles) and the crop duration
     * at the normal rate: 1 - remaining maturity / full maturity.
     *
     * @param  array<mixed>  $plant
     */
    private static function growth(array $plant): ?float
    {
        if (($plant['grown'] ?? false) === true) {
            return 1.0;
        }

        $seconds = self::floatOrNull($plant['secondsUntilHarvest'] ?? null);
        $rate = self::floatOrNull($plant['growthPerSecond'] ?? null);
        $duration = self::floatOrNull($plant['cropDurationSeconds'] ?? null);

        if ($seconds === null || $rate === null || $duration === null || $duration <= 0) {
            return null;
        }

        return max(0.0, min(1.0, 1 - $seconds * $rate * self::CYCLE_SECONDS / $duration));
    }

    /**
     * Each tile with the mask of its neighbours holding the same prefab.
     *
     * @param  list<array<mixed>>|null  $tiles
     * @return list<ZoneTileData>|null
     */
    private static function tiles(?array $tiles, int $gridWidth): ?array
    {
        if ($tiles === null) {
            return null;
        }

        $prefabs = [];
        foreach ($tiles as $tile) {
            $prefabs[self::int($tile['cell'] ?? null, -1)] = self::string($tile['prefabId'] ?? null);
        }

        $result = [];
        foreach ($prefabs as $cell => $prefabId) {
            $x = $cell % $gridWidth;
            $same = fn (int $neighbour): bool => ($prefabs[$neighbour] ?? null) === $prefabId;
            $connections = ($x > 0 && $same($cell - 1) ? self::LEFT : 0)
                | ($x < $gridWidth - 1 && $same($cell + 1) ? self::RIGHT : 0)
                | ($same($cell + $gridWidth) ? self::UP : 0)
                | ($same($cell - $gridWidth) ? self::DOWN : 0);

            $result[] = new ZoneTileData(x: $x, y: intdiv($cell, $gridWidth), prefabId: $prefabId, connections: $connections);
        }

        return $result;
    }

    private static function box(mixed $box): ZoneBoxData
    {
        $box = (array) $box;

        return new ZoneBoxData(
            x: self::int($box['x'] ?? null, 0),
            y: self::int($box['y'] ?? null, 0),
            width: max(0, self::int($box['width'] ?? null, 0)),
            height: max(0, self::int($box['height'] ?? null, 0)),
        );
    }

    /** Root-relative URL of a game sprite, or null when it was not imported. */
    private static function sprite(string $category, string $id): ?string
    {
        $relative = sprintf('%s/%s.webp', $category, $id);

        return $id !== '' && file_exists(config()->string('game.assets_path').'/'.$relative) ? '/assets/game/'.$relative : null;
    }

    /**
     * The array entries of a JSON list, or null when the section is null (unread).
     *
     * @return list<array<mixed>>|null
     */
    private static function listOrNull(mixed $value): ?array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function string(mixed $value): string
    {
        return self::stringOrNull($value) ?? '';
    }

    /**
     * @param  array<mixed>  $plant
     * @param  array{0: int, 1: int}|null  $position
     */
    private function plantEntity(array $plant, ?array $position): ZoneEntityData
    {
        $prefabId = self::string($plant['id'] ?? null);
        $wilting = (bool) ($plant['wilting'] ?? false);
        $grown = (bool) ($plant['grown'] ?? false);

        return new ZoneEntityData(
            kind: 'plant',
            instanceId: self::stringOrNull($plant['instanceId'] ?? null),
            prefabId: $prefabId,
            name: $this->text->name($prefabId),
            sprite: self::sprite('plants', $prefabId),
            x: $position[0] ?? null,
            y: $position[1] ?? null,
            state: match (true) {
                $wilting => 'wilting',
                $grown => 'grown',
                default => 'growing',
            },
            problems: $this->plantProblems($plant),
            growth: self::growth($plant),
            secondsUntilHarvest: self::floatOrNull($plant['secondsUntilHarvest'] ?? null),
            autoHarvest: isset($plant['autoHarvest']) ? (bool) $plant['autoHarvest'] : null,
        );
    }

    /**
     * @param  array<mixed>  $building  a fast-state building
     * @param  list<array<mixed>>  $layoutBuildings
     * @param  callable(mixed): (array{0: int, 1: int}|null)  $position
     */
    private function buildingEntity(array $building, array $layoutBuildings, callable $position): ZoneEntityData
    {
        $prefabId = self::string($building['prefabId'] ?? null);
        $instanceId = self::stringOrNull($building['instanceId'] ?? null);
        $placed = array_find($layoutBuildings, fn (array $candidate): bool => $instanceId !== null && ($candidate['instanceId'] ?? null) === $instanceId);
        $cell = $position($placed['anchorCell'] ?? null);

        return new ZoneEntityData(
            kind: 'building',
            instanceId: $instanceId,
            prefabId: $prefabId,
            name: $this->text->name($prefabId),
            sprite: self::sprite('buildings', $prefabId),
            x: $cell[0] ?? null,
            y: $cell[1] ?? null,
            state: ($building['operational'] ?? null) === false ? 'not_operational' : 'operational',
            problems: $this->buildingProblems($building),
        );
    }

    /**
     * @param  array<mixed>  $plant
     * @return list<string>
     */
    private function plantProblems(array $plant): array
    {
        return ($plant['wilting'] ?? false) === true ? [__('zones.problems.wilting')] : [];
    }

    /**
     * Status items listed in {@see self::PROBLEM_STATUS_IDS}, by their game
     * text; a building the game reports as not operational without such a
     * status gets a generic "not operational".
     *
     * @param  array<mixed>  $building
     * @return list<string>
     */
    private function buildingProblems(array $building): array
    {
        $problems = [];
        foreach (self::listOrNull($building['statusItems'] ?? null) ?? [] as $status) {
            $id = self::string($status['id'] ?? null);
            if (in_array($id, self::PROBLEM_STATUS_IDS, true)) {
                $problems[] = self::stringOrNull($status['name'] ?? null) ?? $id;
            }
        }

        if ($problems === [] && ($building['operational'] ?? null) === false) {
            $problems[] = __('zones.problems.not_operational');
        }

        return $problems;
    }

    /**
     * The terrain of the slow state's box; its arrays are row-major from the
     * box's bottom-left cell. Null when no slow state arrived or none of its
     * layers could be read.
     *
     * @param  array<mixed>|null  $slow
     * @return list<ZoneTerrainCellData>|null
     */
    private function terrain(?array $slow): ?array
    {
        $palette = $slow['palette'] ?? null;
        $element = $slow['element'] ?? null;
        $mass = $slow['mass'] ?? null;
        $temperature = $slow['temperature'] ?? null;

        if ($element === null && $mass === null && $temperature === null) {
            return null;
        }

        $box = self::box($slow['box'] ?? null);
        $colours = $this->elementColours(array_filter((array) $palette, is_string(...)));
        $cells = [];

        for ($index = 0; $index < $box->width * $box->height; $index++) {
            $elementId = ((array) $palette)[self::int(((array) $element)[$index] ?? null, -1)] ?? null;
            $elementId = is_string($elementId) && $elementId !== '' ? $elementId : null;

            $cells[] = new ZoneTerrainCellData(
                x: $box->x + $index % $box->width,
                y: $box->y + intdiv($index, $box->width),
                elementId: $elementId,
                colour: $elementId === null ? null : ($colours[$elementId] ?? null),
                mass: self::floatOrNull(((array) $mass)[$index] ?? null),
                temperature: self::floatOrNull(((array) $temperature)[$index] ?? null),
            );
        }

        return $cells;
    }

    /**
     * @param  array<string>  $elementIds
     * @return array<string, string> element id => `#rrggbb`
     */
    private function elementColours(array $elementIds): array
    {
        return Element::query()
            ->whereIn('element_id', $elementIds)
            ->get(['element_id', 'ui_color'])
            ->mapWithKeys(fn (Element $element): array => [$element->element_id => $element->uiColorHex()])
            ->filter()
            ->all();
    }

    /** @param  array<mixed>  $building */
    private function layoutBuilding(array $building, int $gridWidth): ZoneBuildingData
    {
        $prefabId = self::string($building['prefabId'] ?? null);
        $anchor = self::int($building['anchorCell'] ?? null, 0);

        return new ZoneBuildingData(
            instanceId: self::stringOrNull($building['instanceId'] ?? null),
            x: $anchor % $gridWidth,
            y: intdiv($anchor, $gridWidth),
            width: self::int($building['width'] ?? null, 1),
            height: self::int($building['height'] ?? null, 1),
            orientation: self::stringOrNull($building['orientation'] ?? null),
            prefabId: $prefabId,
            name: $this->text->name($prefabId),
            sprite: self::sprite('buildings', $prefabId),
        );
    }

    /** @param  array<mixed>  $plant */
    private function plant(array $plant, int $gridWidth): ZonePlantData
    {
        $prefabId = self::string($plant['id'] ?? null);
        $cell = self::int($plant['cell'] ?? null, 0);

        return new ZonePlantData(
            x: $cell % $gridWidth,
            y: intdiv($cell, $gridWidth),
            instanceId: self::stringOrNull($plant['instanceId'] ?? null),
            prefabId: $prefabId,
            name: $this->text->name($prefabId),
            sprite: self::sprite('plants', $prefabId),
            growth: self::growth($plant),
            secondsUntilHarvest: self::floatOrNull($plant['secondsUntilHarvest'] ?? null),
            wilting: (bool) ($plant['wilting'] ?? false),
            grown: (bool) ($plant['grown'] ?? false),
        );
    }
}
