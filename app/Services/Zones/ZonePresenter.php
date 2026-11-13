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
use App\Data\ZoneTerrainData;
use App\Data\ZoneTerrainElementData;
use App\Data\ZoneTileData;
use App\Models\Element;
use App\Models\Zone;
use App\Services\GameText;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Closure;

/**
 * Turns the stored layout, slow state and fast state of a zone into what the
 * zone page shows: the map layers placed on cells, one entity per plant and
 * building with its state and problems, display names in the current locale
 * and sprite URLs. Cells are the game's indices: x = cell % gridWidth,
 * y = intdiv(cell, gridWidth), y up. A section the game could not read (JSON
 * null) stays null: unknown, never an empty list. Malformed entries are read
 * with defaults rather than failing the page.
 */
final class ZonePresenter
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

    /**
     * Keys of the game's temperature overlay bands, coldest first.
     *
     * @var list<string>
     */
    private const array TEMPERATURE_BANDS = ['EXTREMECOLD', 'VERYCOLD', 'COLD', 'TEMPERATE', 'HOT', 'VERYHOT', 'EXTREMEHOT', 'MAXHOT'];

    /** Connection sprite mask bits (OniExtract ExportConnectionSprites). */
    private const int LEFT = 1;

    private const int RIGHT = 2;

    private const int UP = 4;

    private const int DOWN = 8;

    /** @var array<string, string|null> sprite URL by "category/prefab", for one present() call */
    private array $sprites = [];

    /** @var array<string, string>|null element id by lower-cased name in any locale, for one present() call */
    private ?array $elementsByName = null;

    public function __construct(private readonly GameText $text) {}

    public function present(Zone $zone): ZoneShowData
    {
        $this->sprites = [];
        $this->elementsByName = null;
        $layout = $zone->layout;
        $gridWidth = self::int($layout['gridWidth'] ?? null, 0);
        $placed = $layout !== null && $gridWidth > 0;
        $position = fn (mixed $cell): ?array => $placed && is_numeric($cell)
            ? [(int) $cell % $gridWidth, intdiv((int) $cell, $gridWidth)]
            : null;

        $fastPlants = self::listOrNull($zone->fast_state['plants'] ?? null);
        $fastBuildings = self::listOrNull($zone->fast_state['buildings'] ?? null);
        $layoutBuildings = self::listOrNull($layout['buildings'] ?? null);
        $anchorsByInstance = [];
        foreach ($layoutBuildings ?? [] as $building) {
            $instanceId = self::stringOrNull($building['instanceId'] ?? null);
            if ($instanceId !== null) {
                $anchorsByInstance[$instanceId] = $building['anchorCell'] ?? null;
            }
        }

        $entities = [
            ...array_map(fn (array $plant): ZoneEntityData => $this->plantEntity($plant, $position($plant['cell'] ?? null)), $fastPlants ?? []),
            ...array_map(fn (array $building): ZoneEntityData => $this->buildingEntity($building, $anchorsByInstance, $position), $fastBuildings ?? []),
        ];
        usort($entities, fn (ZoneEntityData $a, ZoneEntityData $b): int => [$a->problems === [], $a->secondsUntilHarvest ?? INF] <=> [$b->problems === [], $b->secondsUntilHarvest ?? INF]);
        $problems = array_values(array_filter($entities, fn (ZoneEntityData $entity): bool => $entity->problems !== []));

        return new ZoneShowData(
            zone: new ZoneData(
                id: $zone->id,
                guid: $zone->zone_guid,
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
                terrain: $this->terrain($zone->slow_state, $zone->previous_slow_temperature, $zone->slow_state_at),
                tiles: self::tiles(self::listOrNull($layout['tiles'] ?? null), $gridWidth),
                buildings: $layoutBuildings === null ? null : array_map(
                    fn (array $building): ZoneBuildingData => $this->layoutBuilding($building, $gridWidth),
                    array_values(array_filter($layoutBuildings, fn (array $building): bool => is_numeric($building['anchorCell'] ?? null))),
                ),
                plants: $fastPlants === null ? null : array_map(
                    fn (array $plant): ZonePlantData => $this->plant($plant, $gridWidth),
                    array_values(array_filter($fastPlants, fn (array $plant): bool => is_numeric($plant['cell'] ?? null))),
                ),
                temperatureBands: array_map(
                    fn (string $band): string => $this->text->translate('STRINGS.UI.OVERLAYS.TEMPERATURE.'.$band) ?? $band,
                    self::TEMPERATURE_BANDS,
                ),
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

    /**
     * Entities of the zone with at least one problem. The count is null
     * while unknown (no fast state, or neither its plants nor its buildings
     * were read) and partial when only one of the two was read.
     *
     * @return array{count: int|null, partial: bool}
     */
    public function problemSummary(Zone $zone): array
    {
        $plants = self::listOrNull($zone->fast_state['plants'] ?? null);
        $buildings = self::listOrNull($zone->fast_state['buildings'] ?? null);

        if ($plants === null && $buildings === null) {
            return ['count' => null, 'partial' => false];
        }

        $withProblems = count(array_filter($plants ?? [], fn (array $plant): bool => $this->plantProblems($plant) !== []))
            + count(array_filter($buildings ?? [], fn (array $building): bool => $this->buildingProblems($building) !== []));

        return ['count' => $withProblems, 'partial' => $plants === null || $buildings === null];
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
            if (is_numeric($tile['cell'] ?? null)) {
                $prefabs[(int) $tile['cell']] = self::string($tile['prefabId'] ?? null);
            }
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

    private static function dateOrNull(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
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

    /** Root-relative URL of a game sprite, or null when it was not imported; checked once per prefab. */
    private function sprite(string $category, string $id): ?string
    {
        $relative = sprintf('%s/%s.webp', $category, $id);

        if (! array_key_exists($relative, $this->sprites)) {
            $this->sprites[$relative] = $id !== '' && file_exists(config()->string('game.assets_path').'/'.$relative) ? '/assets/game/'.$relative : null;
        }

        return $this->sprites[$relative];
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
        $problems = $this->plantProblems($plant);

        return new ZoneEntityData(
            kind: 'plant',
            instanceId: self::stringOrNull($plant['instanceId'] ?? null),
            prefabId: $prefabId,
            name: $this->text->name($prefabId),
            sprite: $this->sprite('plants', $prefabId),
            x: $position[0] ?? null,
            y: $position[1] ?? null,
            state: match (true) {
                $wilting => 'wilting',
                $grown => 'grown',
                default => 'growing',
            },
            problems: $problems,
            growth: self::growth($plant),
            secondsUntilHarvest: self::floatOrNull($plant['secondsUntilHarvest'] ?? null),
            autoHarvest: isset($plant['autoHarvest']) ? (bool) $plant['autoHarvest'] : null,
            neededItemSprite: $this->neededItemSprite($problems),
        );
    }

    /**
     * @param  array<mixed>  $building  a fast-state building
     * @param  array<string, mixed>  $anchorsByInstance  anchor cell of each layout building, by instance id
     * @param  callable(mixed): (array{0: int, 1: int}|null)  $position
     */
    private function buildingEntity(array $building, array $anchorsByInstance, callable $position): ZoneEntityData
    {
        $prefabId = self::string($building['prefabId'] ?? null);
        $instanceId = self::stringOrNull($building['instanceId'] ?? null);
        $cell = $position($instanceId === null ? null : ($anchorsByInstance[$instanceId] ?? null));
        $problems = $this->buildingProblems($building);

        return new ZoneEntityData(
            kind: 'building',
            instanceId: $instanceId,
            prefabId: $prefabId,
            name: $this->text->name($prefabId),
            sprite: $this->sprite('buildings', $prefabId),
            x: $cell[0] ?? null,
            y: $cell[1] ?? null,
            state: ($building['operational'] ?? null) === false ? 'not_operational' : 'operational',
            problems: $problems,
            neededItemSprite: $this->neededItemSprite($problems),
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
     * The terrain of the slow state, kept columnar: one value per cell of its
     * box, row-major from the bottom-left cell. Null when no slow state
     * arrived or none of its layers could be read.
     *
     * @param  array<mixed>|null  $slow
     * @param  array<mixed>|null  $previous  the slow reading before the current one: box, temperature and ISO 8601 time
     */
    private function terrain(?array $slow, ?array $previous, ?CarbonInterface $slowAt): ?ZoneTerrainData
    {
        $element = $slow['element'] ?? null;
        $mass = $slow['mass'] ?? null;
        $temperature = $slow['temperature'] ?? null;

        if (! is_array($element) && ! is_array($mass) && ! is_array($temperature)) {
            return null;
        }

        $box = self::box($slow['box'] ?? null);
        $cellCount = $box->width * $box->height;
        $palette = array_values(array_map(
            fn (mixed $elementId): ?string => is_string($elementId) && $elementId !== '' ? $elementId : null,
            (array) ($slow['palette'] ?? []),
        ));
        $elements = $this->paletteElements(array_filter($palette, is_string(...)));
        /** @var Closure(array<mixed>): list<mixed> $fit one value per cell of the box, missing ones null */
        $fit = fn (array $values): array => array_pad(array_slice(array_values($values), 0, $cellCount), $cellCount, null);

        $previousTemperature = null;
        $previousSeconds = null;
        $previousList = $previous['temperature'] ?? null;
        if (is_array($previousList) && count($previousList) === $cellCount && self::box($previous['box'] ?? null)->toArray() === $box->toArray()) {
            $previousAt = is_string($previous['at'] ?? null) ? self::dateOrNull($previous['at']) : null;
            if ($previousAt !== null && $slowAt !== null) {
                $previousTemperature = array_map(self::floatOrNull(...), array_values($previousList));
                $previousSeconds = (float) $previousAt->diffInSeconds($slowAt, true);
            }
        }

        return new ZoneTerrainData(
            box: $box,
            palette: array_map(function (?string $elementId) use ($elements): ZoneTerrainElementData {
                $element = $elementId === null ? null : $elements[$elementId];

                return new ZoneTerrainElementData(
                    elementId: $elementId,
                    colour: $element?->uiColorHex(),
                    name: $element?->name,
                    state: $element?->state,
                    renderColour: $element?->renderColorHex(),
                    substanceTexture: $element?->substance_texture,
                    usesCaustics: (bool) $element?->uses_caustics,
                    glows: (bool) $element?->glows,
                    metallic: (bool) $element?->is_metallic,
                    opaqueLiquid: (bool) $element?->is_opaque_liquid,
                    scrollSpeed: $element?->liquid_scroll_speed,
                    maxMass: $element?->max_mass,
                    terrainTexture: $this->terrainTexture($element?->terrain_texture),
                    lowTemp: $element?->thermalProperties?->low_temp,
                    highTemp: $element?->thermalProperties?->high_temp,
                );
            }, $palette),
            element: is_array($element) ? array_map(fn (mixed $index): int => is_int($index) && array_key_exists($index, $palette) ? $index : -1, $fit($element)) : null,
            mass: is_array($mass) ? array_map(self::floatOrNull(...), $fit($mass)) : null,
            temperature: is_array($temperature) ? array_map(self::floatOrNull(...), $fit($temperature)) : null,
            previousTemperature: $previousTemperature,
            previousSeconds: $previousSeconds,
        );
    }

    /** Root-relative URL of a terrain texture, or null when the element has none. */
    private function terrainTexture(?string $name): ?string
    {
        return $name === null || $name === '' ? null : '/assets/game/terrain/'.rawurlencode($name).'.webp';
    }

    /**
     * @param  array<string>  $elementIds
     * @return array<string, Element|null> the game data of each palette element, null when unknown
     */
    private function paletteElements(array $elementIds): array
    {
        $elements = Element::query()
            ->whereIn('element_id', $elementIds)
            ->with('thermalProperties:element_id,low_temp,high_temp')
            ->get([
                'element_id', 'ui_color', 'name', 'state', 'render_color', 'substance_texture', 'uses_caustics',
                'glows', 'is_metallic', 'is_opaque_liquid', 'liquid_scroll_speed', 'max_mass', 'terrain_texture',
            ])
            ->keyBy('element_id');

        return array_combine($elementIds, array_map(fn (string $elementId): ?Element => $elements->get($elementId), $elementIds));
    }

    /**
     * Sprite of the first element a problem names after its colon, as the
     * game words a missing delivery (`Insufficient Resources: Fertilizer`,
     * in the game's language), so the page can show what to bring.
     *
     * @param  list<string>  $problems
     */
    private function neededItemSprite(array $problems): ?string
    {
        foreach ($problems as $problem) {
            $colon = mb_strrpos($problem, ':');
            if ($colon === false) {
                continue;
            }

            foreach (explode(',', strip_tags(mb_substr($problem, $colon + 1))) as $name) {
                $elementId = $this->elementsByName()[mb_strtolower(mb_trim($name))] ?? null;
                $sprite = $elementId === null ? null : $this->sprite('elements', $elementId);
                if ($sprite !== null) {
                    return $sprite;
                }
            }
        }

        return null;
    }

    /** @return array<string, string> element id by lower-cased name, in every stored locale */
    private function elementsByName(): array
    {
        if ($this->elementsByName === null) {
            $this->elementsByName = [];
            foreach (Element::query()->get(['element_id', 'name']) as $element) {
                foreach ($element->getTranslations('name') as $name) {
                    if (is_string($name) && $name !== '') {
                        $this->elementsByName[mb_strtolower(strip_tags($name))] ??= $element->element_id;
                    }
                }
            }
        }

        return $this->elementsByName;
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
            sprite: $this->sprite('buildings', $prefabId),
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
            sprite: $this->sprite('plants', $prefabId),
            growth: self::growth($plant),
            secondsUntilHarvest: self::floatOrNull($plant['secondsUntilHarvest'] ?? null),
            wilting: (bool) ($plant['wilting'] ?? false),
            grown: (bool) ($plant['grown'] ?? false),
        );
    }
}
