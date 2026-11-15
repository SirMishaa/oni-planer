<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Data\ZoneBoxData;
use App\Data\ZoneBuildingData;
use App\Data\ZoneCaptureData;
use App\Data\ZoneCaptureRequestData;
use App\Data\ZoneCountsData;
use App\Data\ZoneData;
use App\Data\ZoneEggData;
use App\Data\ZoneEntityData;
use App\Data\ZoneMapData;
use App\Data\ZonePlantData;
use App\Data\ZoneShowData;
use App\Data\ZoneSpriteRectData;
use App\Data\ZoneTerrainData;
use App\Data\ZoneTerrainElementData;
use App\Data\ZoneTileData;
use App\Enums\BuildLocationRule;
use App\Models\Building;
use App\Models\Colony;
use App\Models\CritterMorph;
use App\Models\Element;
use App\Models\UplinkCommand;
use App\Models\Zone;
use App\Models\ZoneCapture;
use App\Services\GameText;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Support\Str;

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
     * The page props each `ZoneUpdated` kind changes: the page reloads only these on a live event
     * (shared with it as `liveProps`). A layout or list change reloads everything.
     *
     * @var array<string, list<string>>
     */
    public const array PROPS_BY_KIND = [
        'list' => self::ALL_LIVE_PROPS,
        'layout' => self::ALL_LIVE_PROPS,
        'slow' => ['zone', 'mapTerrain', 'eggs', 'counts'],
        'fast' => [
            'zone', 'mapPlants', 'entities', 'problems', 'borderEntities', 'counts', 'plantsKnown',
            'buildingStatesKnown', 'population', 'critters', 'crittersAt', 'feeding',
        ],
        'capture' => ['captures', 'captureRequest'],
    ];

    /**
     * Every page prop a live event can change; the link time has its own heartbeat, the Echo
     * subscription and `liveProps` never change.
     *
     * @var list<string>
     */
    private const array ALL_LIVE_PROPS = [
        'zone', 'map', 'mapTerrain', 'mapPlants', 'entities', 'problems', 'borderEntities', 'counts',
        'plantsKnown', 'buildingStatesKnown', 'captures', 'captureRequest', 'population', 'critters', 'crittersAt', 'eggs', 'feeding',
    ];

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

    /** @var array<string, string|null> sprite URL by "category/prefab", for one props() call */
    private array $sprites = [];

    /** @var array<string, string>|null element id by lower-cased name in any locale, for one props() call */
    private ?array $elementsByName = null;

    /** @var array<string, array{height: int, onCeiling: bool, solid: bool}> game definition of each layout prefab, for one layoutMap() call */
    private array $buildingDefinitions = [];

    /** @var array<string, array<string, array{x: float, y: float, w: float, h: float}>> Stairs mod cap variants: prefab => mask => placement, for one layoutMap() call */
    private array $stairCaps = [];

    /** @var array<int, array{stair: bool, facingLeft: bool, solid: bool}> what stands on each cell of the layout's building layer, for one layoutMap() call */
    private array $occupants = [];

    public function __construct(
        private readonly GameText $text,
        private readonly ZonePopulation $population,
        private readonly ZoneFeeding $feeding,
    ) {}

    /** @see self::props() */
    public function present(Zone $zone): ZoneShowData
    {
        return new ZoneShowData(...array_map(fn (Closure $prop): mixed => $prop(), $this->props($zone)));
    }

    /**
     * The zone page's props, keyed by the {@see ZoneShowData} fields, each computed only when
     * called: a partial reload of the fast props never reads the terrain or the layout. The
     * values the props share (fast plants, entities, critters…) are computed once per call.
     *
     * @return array<string, Closure(): mixed>
     */
    public function props(Zone $zone): array
    {
        $this->sprites = [];
        $this->elementsByName = null;
        $layout = $zone->layout;
        $gridWidth = self::int($layout['gridWidth'] ?? null, 0);
        $placed = $layout !== null && $gridWidth > 0;
        $position = fn (mixed $cell): ?array => $placed && is_numeric($cell)
            ? [(int) $cell % $gridWidth, intdiv((int) $cell, $gridWidth)]
            : null;

        $fastPlants = self::once(fn (): ?array => self::listOrNull($zone->fast_state['plants'] ?? null));
        /** @var Closure(): array{0: list<array<mixed>>|null, 1: list<array<mixed>>} $fastBuildings content buildings, then border passages */
        $fastBuildings = self::once(fn (): array => ZoneProblems::splitPassages(self::listOrNull($zone->fast_state['buildings'] ?? null)));
        $anchorsByInstance = self::once(function () use ($layout): array {
            $anchors = [];
            foreach (self::listOrNull($layout['buildings'] ?? null) ?? [] as $building) {
                $instanceId = self::stringOrNull($building['instanceId'] ?? null);
                if ($instanceId !== null) {
                    $anchors[$instanceId] = $building['anchorCell'] ?? null;
                }
            }

            return $anchors;
        });
        $entities = self::once(function () use ($fastPlants, $fastBuildings, $anchorsByInstance, $position): array {
            $entities = [
                ...array_map(fn (array $plant): ZoneEntityData => $this->plantEntity($plant, $position($plant['cell'] ?? null)), $fastPlants() ?? []),
                ...array_map(fn (array $building): ZoneEntityData => $this->buildingEntity($building, $anchorsByInstance(), $position), $fastBuildings()[0] ?? []),
            ];
            usort($entities, fn (ZoneEntityData $a, ZoneEntityData $b): int => [$a->problems === [], $a->secondsUntilHarvest ?? INF] <=> [$b->problems === [], $b->secondsUntilHarvest ?? INF]);

            return $entities;
        });
        $critters = self::once(fn (): ?array => ZoneCritterState::critters($zone));
        $population = self::once(fn (): ?array => $this->population->forZone($zone));
        $problems = self::once(fn (): array => [
            ...array_filter($entities(), fn (ZoneEntityData $entity): bool => $entity->problems !== []),
            ...CritterProblems::of($critters() ?? [], $population() ?? []),
        ]);
        $eggs = self::once(function () use ($zone): ?array {
            $eggs = ZoneCritterState::eggs($zone);
            $this->withIncubation($eggs ?? []);

            return $eggs;
        });

        return [
            'zone' => fn (): ZoneData => new ZoneData(
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
            'map' => fn (): ?ZoneMapData => $placed ? $this->layoutMap($layout, $gridWidth, $position) : null,
            'entities' => $entities,
            'problems' => $problems,
            'borderEntities' => fn (): array => array_map(fn (array $building): ZoneEntityData => $this->buildingEntity($building, $anchorsByInstance(), $position, passage: true), $fastBuildings()[1]),
            'counts' => fn (): ZoneCountsData => new ZoneCountsData(
                plants: count($fastPlants() ?? []),
                buildings: count($fastBuildings()[0] ?? []),
                problems: count($problems()),
                critters: count($critters() ?? []),
                eggs: count($eggs() ?? []),
            ),
            'plantsKnown' => fn (): bool => $fastPlants() !== null,
            'buildingStatesKnown' => fn (): bool => $fastBuildings()[0] !== null,
            'captures' => fn (): array => $this->captures($zone),
            'captureRequest' => fn (): ?ZoneCaptureRequestData => self::captureRequest($zone),
            'linkSeenAt' => fn (): ?string => Colony::query()->where('guid', $zone->colony_guid)->first(['last_seen_at'])?->last_seen_at?->toIso8601String(),
            'population' => $population,
            'critters' => $critters,
            'eggs' => $eggs,
            'mapTerrain' => fn (): ?ZoneTerrainData => $placed ? $this->terrain($zone->slow_state, $zone->previous_slow_temperature, $zone->slow_state_at) : null,
            'mapPlants' => fn (): ?array => $placed && $fastPlants() !== null ? array_map(
                fn (array $plant): ZonePlantData => $this->plant($plant, $gridWidth),
                array_values(array_filter($fastPlants(), fn (array $plant): bool => is_numeric($plant['cell'] ?? null))),
            ) : null,
            'crittersAt' => fn (): ?string => $critters() === null ? null : $zone->fast_state_at?->toIso8601String(),
            'feeding' => fn (): ?array => $critters() === null ? null : $this->feeding->of($zone, $critters(), $population()),
            'liveProps' => fn (): array => self::PROPS_BY_KIND,
            'echo' => fn (): array => ['channel' => 'uplink', 'event' => '.zone.updated'],
        ];
    }

    /**
     * @see ZoneProblems::summary()
     *
     * @return array{count: int|null, partial: bool}
     */
    public function problemSummary(Zone $zone): array
    {
        return ZoneProblems::summary($zone);
    }

    /**
     * A closure that computes its value on the first call and returns it on the next ones.
     *
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return Closure(): T
     */
    private static function once(Closure $compute): Closure
    {
        $computed = false;
        $value = null;

        return function () use ($compute, &$computed, &$value): mixed {
            if (! $computed) {
                $value = $compute();
                $computed = true;
            }

            return $value;
        };
    }

    private static function captureRequest(Zone $zone): ?ZoneCaptureRequestData
    {
        $command = UplinkCommand::query()
            ->where('colony_guid', $zone->colony_guid)
            ->where('type', 'zone.capture')
            ->where('payload->zoneId', $zone->zone_guid)
            ->latest('issued_at')
            ->first();

        return $command === null ? null : new ZoneCaptureRequestData(
            status: $command->status,
            reason: $command->reason,
            issuedAt: $command->issued_at->toIso8601String(),
        );
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

    /**
     * Height and ceiling rule of the layout prefabs the game data knows; modded prefabs are absent.
     *
     * @param  list<array<mixed>>  $layoutBuildings
     * @return array<string, array{height: int, onCeiling: bool}>
     */
    private static function buildingDefinitions(array $layoutBuildings): array
    {
        $prefabIds = array_values(array_unique(array_map(fn (array $building): string => self::string($building['prefabId'] ?? null), $layoutBuildings)));

        if ($prefabIds === []) {
            return [];
        }

        return Building::query()
            ->whereIn('building_id', $prefabIds)
            ->get(['building_id', 'height', 'build_location_rule', 'sim_cell_occupier'])
            ->mapWithKeys(fn (Building $building): array => [$building->building_id => [
                'height' => (int) $building->height,
                'onCeiling' => $building->build_location_rule === BuildLocationRule::OnCeiling->name,
                'solid' => (bool) $building->sim_cell_occupier,
            ]])
            ->all();
    }

    /**
     * The Stairs mod's cap variants published by game:import-assets (OniExtract stair_caps.json).
     *
     * @return array<string, array<string, array{x: float, y: float, w: float, h: float}>>
     */
    private static function stairCaps(): array
    {
        $path = config()->string('game.assets_path').'/stair_caps/rects.json';
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($decoded)) {
            return [];
        }

        $caps = [];
        foreach ($decoded as $prefabId => $masks) {
            foreach (is_array($masks) ? $masks : [] as $mask => $rect) {
                if (is_array($rect)) {
                    $caps[(string) $prefabId][(string) $mask] = [
                        'x' => self::floatOrNull($rect['x'] ?? null) ?? 0.0,
                        'y' => self::floatOrNull($rect['y'] ?? null) ?? 0.0,
                        'w' => self::floatOrNull($rect['w'] ?? null) ?? 1.0,
                        'h' => self::floatOrNull($rect['h'] ?? null) ?? 1.0,
                    ];
                }
            }
        }

        return $caps;
    }

    /**
     * The map layers that only change with the layout: the box, the zone cells, the tiles and buildings.
     *
     * @param  array<mixed>  $layout
     * @param  Closure(mixed): (array{0: int, 1: int}|null)  $position
     */
    private function layoutMap(array $layout, int $gridWidth, Closure $position): ZoneMapData
    {
        $layoutBuildings = self::listOrNull($layout['buildings'] ?? null);
        $layoutTiles = self::listOrNull($layout['tiles'] ?? null);
        $this->buildingDefinitions = self::buildingDefinitions(array_values(array_filter([...$layoutBuildings ?? [], ...$layoutTiles ?? []], is_array(...))));
        $this->stairCaps = self::stairCaps();
        $this->occupants = $this->occupants($layoutBuildings ?? [], $layoutTiles ?? [], $gridWidth);

        return new ZoneMapData(
            gridWidth: $gridWidth,
            box: self::box($layout['box'] ?? null),
            zoneCells: array_values(array_filter(array_map($position, (array) ($layout['cells'] ?? [])))),
            tiles: self::tiles($layoutTiles, $gridWidth),
            buildings: $layoutBuildings === null ? null : array_map(
                fn (array $building): ZoneBuildingData => $this->layoutBuilding($building, $gridWidth),
                array_values(array_filter($layoutBuildings, fn (array $building): bool => is_numeric($building['anchorCell'] ?? null))),
            ),
            temperatureBands: array_map(
                fn (string $band): string => $this->text->translate('STRINGS.UI.OVERLAYS.TEMPERATURE.'.$band) ?? $band,
                self::TEMPERATURE_BANDS,
            ),
        );
    }

    /** @return list<ZoneCaptureData> */
    private function captures(Zone $zone): array
    {
        $layoutSignature = self::stringOrNull($zone->layout['signature'] ?? null);

        return $zone->captures()->get()->map(fn (ZoneCapture $capture): ZoneCaptureData => new ZoneCaptureData(
            id: $capture->id,
            url: '/storage/'.$capture->path,
            takenAt: $capture->taken_at->toIso8601String(),
            cycle: $capture->cycle(),
            trigger: $capture->trigger,
            matchesLayout: $capture->layout_signature !== null && $capture->layout_signature === $layoutSignature,
            width: $capture->width,
            height: $capture->height,
        ))->values()->all();
    }

    /**
     * The building layer of the layout, cell by cell: which cells hold a stair (and which way it
     * faces) or a building that makes its cell solid. Read by {@see StairCaps}.
     *
     * @param  list<mixed>  $buildings
     * @param  list<mixed>  $tiles
     * @return array<int, array{stair: bool, facingLeft: bool, solid: bool}>
     */
    private function occupants(array $buildings, array $tiles, int $gridWidth): array
    {
        $occupants = [];

        foreach ($tiles as $tile) {
            if (is_array($tile) && is_numeric($tile['cell'] ?? null)) {
                $prefabId = self::string($tile['prefabId'] ?? null);
                $occupants[(int) $tile['cell']] = ['stair' => false, 'facingLeft' => false, 'solid' => $this->buildingDefinitions[$prefabId]['solid'] ?? false];
            }
        }

        foreach ($buildings as $building) {
            if (! is_array($building) || ! is_numeric($building['anchorCell'] ?? null)) {
                continue;
            }

            $prefabId = self::string($building['prefabId'] ?? null);
            $occupant = [
                'stair' => isset($this->stairCaps[$prefabId]),
                'facingLeft' => ($building['orientation'] ?? null) === 'FlipH',
                'solid' => $this->buildingDefinitions[$prefabId]['solid'] ?? false,
            ];
            $footprint = ZoneGeometry::footprint((int) $building['anchorCell'], self::int($building['width'] ?? null, 1), self::int($building['height'] ?? null, 1), $gridWidth);
            foreach ($footprint as $cell) {
                $occupants[$cell] = $occupant;
            }
        }

        return $occupants;
    }

    /**
     * Sets the total incubation of each egg from the morph it hatches into: the baby morph when it has one,
     * else its adult (baby morphs carry no incubation time). Null when the game data has neither.
     *
     * @param  list<ZoneEggData>  $eggs
     */
    private function withIncubation(array $eggs): void
    {
        if ($eggs === []) {
            return;
        }

        $species = [];
        foreach ($eggs as $egg) {
            $hatches = $egg->spawns ?? $egg->prefabId;
            $species[$egg->instanceId] = [$hatches, Str::beforeLast($hatches, 'Baby')];
        }

        $incubation = CritterMorph::query()
            ->whereIn('morph_id', array_unique(array_merge(...array_values($species))))
            ->where('incubation_time', '>', 0)
            ->pluck('incubation_time', 'morph_id');

        foreach ($eggs as $egg) {
            [$baby, $adult] = $species[$egg->instanceId];
            $cycles = $incubation[$baby] ?? $incubation[$adult] ?? null;
            $egg->incubationCycles = $cycles === null ? null : (float) $cycles;
        }
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
        $problems = ZoneProblems::forPlant($plant);

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
            growth: ZonePlants::growth($plant),
            secondsUntilHarvest: self::floatOrNull($plant['secondsUntilHarvest'] ?? null),
            autoHarvest: isset($plant['autoHarvest']) ? (bool) $plant['autoHarvest'] : null,
            neededItemSprite: $this->neededItemSprite($problems),
        );
    }

    /**
     * @param  array<mixed>  $building  a fast-state building
     * @param  array<string, mixed>  $anchorsByInstance  anchor cell of each layout building, by instance id
     * @param  callable(mixed): (array{0: int, 1: int}|null)  $position
     * @param  bool  $passage  a border passage: shown without problems
     */
    private function buildingEntity(array $building, array $anchorsByInstance, callable $position, bool $passage = false): ZoneEntityData
    {
        $prefabId = self::string($building['prefabId'] ?? null);
        $instanceId = self::stringOrNull($building['instanceId'] ?? null);
        $cell = $position($instanceId === null ? null : ($anchorsByInstance[$instanceId] ?? null));
        $problems = $passage ? [] : ZoneProblems::forBuilding($building);

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
        $height = self::int($building['height'] ?? null, 1);
        $orientation = self::stringOrNull($building['orientation'] ?? null);
        $definition = $this->buildingDefinitions[$prefabId] ?? null;
        $upright = in_array($orientation, [null, 'Neutral', 'FlipH'], true);

        [$sprite, $spriteRect] = $this->stairSprite($prefabId, $anchor, $orientation === 'FlipH', $gridWidth)
            ?? [$this->sprite('buildings', $prefabId), null];

        return new ZoneBuildingData(
            instanceId: self::stringOrNull($building['instanceId'] ?? null),
            x: $anchor % $gridWidth,
            y: intdiv($anchor, $gridWidth),
            width: self::int($building['width'] ?? null, 1),
            height: $height,
            orientation: $orientation,
            prefabId: $prefabId,
            name: $this->text->name($prefabId),
            sprite: $sprite,
            hangsFromCeiling: $definition['onCeiling'] ?? false,
            spriteHeight: $upright ? max($height, $definition['height'] ?? 0) : $height,
            flipped: $spriteRect !== null && $orientation === 'FlipH',
            spriteRect: $spriteRect,
        );
    }

    /**
     * The cap variant a stair shows from its neighbours, with its placement; null for any other
     * building, or when that variant was not imported.
     *
     * @return array{string, ZoneSpriteRectData}|null
     */
    private function stairSprite(string $prefabId, int $cell, bool $facingLeft, int $gridWidth): ?array
    {
        if (! isset($this->stairCaps[$prefabId])) {
            return null;
        }

        $mask = (string) StairCaps::mask($cell, $facingLeft, $gridWidth, fn (int $neighbour): ?array => $this->occupants[$neighbour] ?? null);
        $rect = $this->stairCaps[$prefabId][$mask] ?? null;
        $sprite = $this->sprite('stair_caps', $prefabId.'/'.$mask);

        return $rect === null || $sprite === null ? null : [$sprite, new ZoneSpriteRectData(...$rect)];
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
            growth: ZonePlants::growth($plant),
            secondsUntilHarvest: self::floatOrNull($plant['secondsUntilHarvest'] ?? null),
            wilting: (bool) ($plant['wilting'] ?? false),
            grown: (bool) ($plant['grown'] ?? false),
        );
    }
}
