<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Models\Colony;
use App\Models\Element;
use App\Models\Zone;
use App\Services\Zones\ZoneCritters;
use App\Services\Zones\ZoneCritterState;
use App\Services\Zones\ZoneGeometry;
use App\Services\Zones\ZonePlants;

/**
 * The text map of a zone for the assistant: two aligned grids over the zone's box (or a
 * rect of it), T for terrain (tiles, elements) and O for occupancy (buildings over their
 * footprint, critters and plants), rows labelled with their local y, a column ruler, and a
 * legend of the glyphs present with one line per building, plant and critter.
 */
final class ZoneMapRenderer
{
    public const int MAX_WIDTH = 40;

    public const int MAX_HEIGHT = 25;

    /** @var array<string, string> glyph per tile prefab / element / building prefab / entity prefab, for one render() call */
    private array $assigned = [];

    /**
     * @param  array{x: int, y: int, width: int, height: int}|null  $rect  world coordinates, bottom-left origin
     * @param  list<string>  $layers  subset of ['terrain', 'occupancy']
     */
    public function render(Zone $zone, Colony $colony, ?array $rect, array $layers): string
    {
        $this->assigned = [];
        $layout = $zone->layout;
        $gridWidth = (int) ($layout['gridWidth'] ?? 0);
        $box = ZoneGeometry::box($layout['box'] ?? null);
        $title = sprintf('Zone #%d "%s" v%d', $zone->id, $zone->name, $zone->version);

        if (! is_array($layout) || $gridWidth <= 0 || $box === null) {
            return $title.' · layout unavailable';
        }

        $this->seedGlyphs($zone, $colony);
        $area = $rect === null ? $box : ZoneGeometry::intersect($rect, $box);

        if ($area === null) {
            return sprintf('%s · the rect does not overlap the zone box %dx%d @ %s', $title, $box['width'], $box['height'], McpFormat::world($box['x'], $box['y']));
        }

        if ($area['width'] > self::MAX_WIDTH || $area['height'] > self::MAX_HEIGHT) {
            return sprintf(
                '%s · box %dx%d @ %s is too large to draw at once (max %dx%d): pass a rect {x, y, width, height} in world coordinates, e.g. {"x": %d, "y": %d, "width": %d, "height": %d}.',
                $title,
                $area['width'],
                $area['height'],
                McpFormat::world($area['x'], $area['y']),
                self::MAX_WIDTH,
                self::MAX_HEIGHT,
                $area['x'],
                $area['y'],
                min($area['width'], self::MAX_WIDTH),
                min($area['height'], self::MAX_HEIGHT),
            );
        }

        $lines = [
            sprintf('%s · origin %s · %dx%d · y up · local coords from the origin', $title, McpFormat::world($area['x'], $area['y']), $area['width'], $area['height']),
            ...$this->ruler($area['width']),
        ];
        $legend = [];

        if (in_array('terrain', $layers, true)) {
            [$rows, $legend['Terrain']] = $this->terrain($zone, $area, $gridWidth);
            array_push($lines, ...$rows);
        }

        if (in_array('occupancy', $layers, true)) {
            [$rows, $legend['Occupancy']] = $this->occupancy($zone, $colony, $area, $gridWidth);
            array_push($lines, ...$rows);
        }

        foreach ($legend as $name => $entries) {
            $lines[] = $name.':'.($name === 'Terrain' ? ' '.implode('  ', $entries) : '');
            if ($name === 'Occupancy') {
                array_push($lines, ...($entries === [] ? [' nothing'] : array_map(fn (string $entry): string => ' '.$entry, $entries)));
            }
        }

        return implode("\n", $lines);
    }

    /** @return list<string> a tens line (beyond 10 columns) and a units line, aligned with the grids */
    private function ruler(int $width): array
    {
        $tens = '';
        $units = '';
        for ($x = 0; $x < $width; $x++) {
            $tens .= $x >= 10 ? (string) (intdiv($x, 10) % 10) : ' ';
            $units .= (string) ($x % 10);
        }

        return $width > 10 ? ['      '.$tens, '      '.$units] : ['      '.$units];
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}  $area
     * @return array{0: list<string>, 1: list<string>} rows top to bottom, legend entries
     */
    private function terrain(Zone $zone, array $area, int $gridWidth): array
    {
        $tiles = [];
        foreach ((array) ($zone->layout['tiles'] ?? []) as $tile) {
            if (is_array($tile) && is_int($tile['cell'] ?? null) && is_string($tile['prefabId'] ?? null)) {
                $tiles[$tile['cell']] = $tile['prefabId'];
            }
        }
        $slow = is_array($zone->slow_state) ? $zone->slow_state : null;
        $states = $slow === null ? [] : Element::query()->whereIn('element_id', array_filter((array) ($slow['palette'] ?? []), is_string(...)))->pluck('state', 'element_id')->all();
        $inZone = array_flip(ZoneCritters::cells($zone));
        $legend = [];
        $rows = [];
        $border = false;

        for ($y = $area['height'] - 1; $y >= 0; $y--) {
            $row = '';
            for ($x = 0; $x < $area['width']; $x++) {
                $worldX = $area['x'] + $x;
                $worldY = $area['y'] + $y;
                $cell = ZoneGeometry::cell($worldX, $worldY, $gridWidth);

                if (isset($tiles[$cell])) {
                    $glyph = $this->glyph('tile:'.$tiles[$cell], MapGlyphs::TILES[$tiles[$cell]] ?? null, MapGlyphs::TILE_POOL);
                    $legend[$glyph] = $glyph.' '.$tiles[$cell];
                    $row .= $glyph;

                    continue;
                }

                $reading = $slow === null ? null : ZoneTerrainSummary::cellAt($slow, $worldX, $worldY);
                if ($reading === null) {
                    $row .= MapGlyphs::UNKNOWN;
                    $legend[MapGlyphs::UNKNOWN] = MapGlyphs::UNKNOWN.' unavailable';

                    continue;
                }

                $elementId = $reading['elementId'];
                $state = $states[$elementId] ?? null;
                $fluid = isset(MapGlyphs::FLUIDS[$elementId]) || in_array($state, ['gas', 'liquid'], true);
                if ($fluid && ! isset($inZone[$cell])) {
                    $row .= MapGlyphs::BORDER;
                    $border = true;

                    continue;
                }

                $glyph = match (true) {
                    isset(MapGlyphs::FLUIDS[$elementId]) => MapGlyphs::FLUIDS[$elementId],
                    $state === 'gas' => MapGlyphs::OTHER_GAS,
                    $state === 'liquid' => MapGlyphs::OTHER_LIQUID,
                    isset(MapGlyphs::SOLIDS[$elementId]) => MapGlyphs::SOLIDS[$elementId],
                    $state === 'solid' => $this->glyph('solid:'.$elementId, null, MapGlyphs::SOLID_POOL),
                    default => MapGlyphs::UNKNOWN,
                };
                $legend[$glyph.$elementId] = $glyph.' '.$elementId;
                $row .= $glyph;
            }
            $rows[] = sprintf('T %2d |%s|', $y, $row);
        }

        ksort($legend);
        if ($border) {
            $legend[] = MapGlyphs::BORDER.' border (gas or liquid outside the zone)';
        }

        return [$rows, array_values($legend)];
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}  $area
     * @return array{0: list<string>, 1: list<string>} rows top to bottom, legend entries
     */
    private function occupancy(Zone $zone, Colony $colony, array $area, int $gridWidth): array
    {
        $states = [];
        foreach ((array) ($zone->fast_state['buildings'] ?? []) as $building) {
            if (is_array($building) && is_scalar($building['instanceId'] ?? null)) {
                $states[(string) $building['instanceId']] = $building;
            }
        }

        $grid = [];
        $legend = [];
        /** @var array<string, list<array{size: string, text: string}>> $groups building instances by "glyph prefab" */
        $groups = [];
        $visible = fn (int $cell): bool => ($xy = ZoneGeometry::xy($cell, $gridWidth))[0] >= $area['x'] && $xy[0] < $area['x'] + $area['width'] && $xy[1] >= $area['y'] && $xy[1] < $area['y'] + $area['height'];

        foreach ((array) ($zone->layout['buildings'] ?? []) as $building) {
            if (! is_array($building) || ! is_int($building['anchorCell'] ?? null) || ! is_string($building['prefabId'] ?? null)) {
                continue;
            }
            $width = (int) ($building['width'] ?? 1);
            $height = (int) ($building['height'] ?? 1);
            $cells = array_filter(ZoneGeometry::footprint($building['anchorCell'], $width, $height, $gridWidth), $visible);
            if ($cells === []) {
                continue;
            }

            $glyph = $this->glyph('building:'.$building['prefabId'], MapGlyphs::BUILDINGS[$building['prefabId']] ?? null, MapGlyphs::BUILDING_POOL, array_values(MapGlyphs::BUILDINGS));
            foreach ($cells as $cell) {
                $grid[$cell] = $glyph;
            }
            [$x, $y] = ZoneGeometry::xy($building['anchorCell'], $gridWidth);
            $instanceId = (string) ($building['instanceId'] ?? '?');
            $groups[$glyph.' '.$building['prefabId']][] = [
                'size' => sprintf('%dx%d', $width, $height),
                'text' => sprintf('#%s %s%s%s', $instanceId, McpFormat::world($x, $y), ($building['orientation'] ?? 'Neutral') !== 'Neutral' ? ' '.$building['orientation'] : '', $this->buildingState($states[$instanceId] ?? null)),
            ];
        }

        foreach ($groups as $name => $instances) {
            $sizes = array_unique(array_column($instances, 'size'));
            $legend[] = count($sizes) === 1
                ? sprintf('%s %s%s: %s', $name, $sizes[0], count($instances) > 1 ? ' ×'.count($instances) : '', implode(', ', array_column($instances, 'text')))
                : sprintf('%s ×%d: %s', $name, count($instances), implode(', ', array_map(fn (array $instance): string => $instance['text'].' '.$instance['size'], $instances)));
        }

        foreach ((array) ($zone->fast_state['plants'] ?? []) as $plant) {
            if (! is_array($plant) || ! is_int($plant['cell'] ?? null) || ! $visible($plant['cell'])) {
                continue;
            }
            $prefabId = (string) ($plant['id'] ?? '?');
            $glyph = $this->entityGlyph($prefabId);
            $grid[$plant['cell']] = $glyph;
            [$x, $y] = ZoneGeometry::xy($plant['cell'], $gridWidth);
            $growth = ZonePlants::growth($plant);
            $legend[] = sprintf('%s plant %s#%s %s growth %s%s', $glyph, $prefabId, $plant['instanceId'] ?? '?', McpFormat::world($x, $y), $growth === null ? 'unavailable' : round($growth * 100).' %', ($plant['wilting'] ?? false) === true ? ' wilting' : '');
        }

        $derived = ZoneCritterState::critters($zone) === null ? ' (derived)' : '';
        foreach (ZoneCritters::in($zone, $colony) as $critter) {
            if (! $visible((int) $critter['cell'])) {
                continue;
            }
            $prefabId = (string) ($critter['id'] ?? '?');
            $glyph = $this->entityGlyph($prefabId);
            $grid[(int) $critter['cell']] = $glyph;
            [$x, $y] = ZoneGeometry::xy((int) $critter['cell'], $gridWidth);
            $legend[] = sprintf('%s critter %s @ %s %s%s', $glyph, $prefabId, McpFormat::world($x, $y), match ($critter['tame'] ?? null) {
                true => 'tame',
                false => 'wild',
                default => '',
            }, $derived);
        }

        foreach (ZoneCritterState::eggs($zone) ?? [] as $egg) {
            if (! $visible($egg->cell)) {
                continue;
            }
            $grid[$egg->cell] = $this->eggGlyph();
            [$x, $y] = ZoneGeometry::xy($egg->cell, $gridWidth);
            $legend[] = sprintf('%s egg %s @ %s', $this->eggGlyph(), $egg->prefabId, McpFormat::world($x, $y));
        }

        $rows = [];
        for ($y = $area['height'] - 1; $y >= 0; $y--) {
            $row = '';
            for ($x = 0; $x < $area['width']; $x++) {
                $row .= $grid[ZoneGeometry::cell($area['x'] + $x, $area['y'] + $y, $gridWidth)] ?? MapGlyphs::EMPTY;
            }
            $rows[] = sprintf('O %2d |%s|', $y, $row);
        }

        return [$rows, $legend];
    }

    /** Only what departs from the normal state: " NOT operational", " active"; "" when idle and fine or unknown. */
    private function buildingState(?array $state): string
    {
        return (($state['operational'] ?? null) === false ? ' NOT operational' : '').(($state['active'] ?? null) === true ? ' active' : '');
    }

    /**
     * Assigns pool glyphs over the whole zone, in sorted order, before any rect is drawn, so
     * a prefab keeps its glyph in every rect of the zone (not across zones).
     */
    private function seedGlyphs(Zone $zone, Colony $colony): void
    {
        $tiles = array_unique(array_filter(array_map(fn (mixed $tile): ?string => is_array($tile) && is_string($tile['prefabId'] ?? null) ? $tile['prefabId'] : null, (array) ($zone->layout['tiles'] ?? []))));
        sort($tiles);
        foreach ($tiles as $prefabId) {
            $this->glyph('tile:'.$prefabId, MapGlyphs::TILES[$prefabId] ?? null, MapGlyphs::TILE_POOL);
        }

        $palette = array_values(array_filter((array) ($zone->slow_state['palette'] ?? []), is_string(...)));
        $solids = $palette === [] ? [] : Element::query()->whereIn('element_id', $palette)->where('state', 'solid')->orderBy('element_id')->pluck('element_id')->all();
        foreach ($solids as $elementId) {
            if (! isset(MapGlyphs::SOLIDS[$elementId]) && ! isset(MapGlyphs::FLUIDS[$elementId])) {
                $this->glyph('solid:'.$elementId, null, MapGlyphs::SOLID_POOL);
            }
        }

        $buildings = array_unique(array_filter(array_map(fn (mixed $building): ?string => is_array($building) && is_string($building['prefabId'] ?? null) ? $building['prefabId'] : null, (array) ($zone->layout['buildings'] ?? []))));
        usort($buildings, fn (string $a, string $b): int => [! isset(MapGlyphs::BUILDINGS[$a]), $a] <=> [! isset(MapGlyphs::BUILDINGS[$b]), $b]);
        foreach ($buildings as $prefabId) {
            $this->glyph('building:'.$prefabId, MapGlyphs::BUILDINGS[$prefabId] ?? null, MapGlyphs::BUILDING_POOL, array_values(MapGlyphs::BUILDINGS));
        }

        $this->eggGlyph();
        $entities = [
            ...array_map(fn (mixed $plant): ?string => is_array($plant) && is_string($plant['id'] ?? null) ? $plant['id'] : null, (array) ($zone->fast_state['plants'] ?? [])),
            ...array_map(fn (array $critter): ?string => is_string($critter['id'] ?? null) ? $critter['id'] : null, ZoneCritters::in($zone, $colony)),
        ];
        $entities = array_unique(array_filter($entities));
        sort($entities);
        foreach ($entities as $prefabId) {
            $this->entityGlyph($prefabId);
        }
    }

    /** Glyph of the eggs: `o`, seeded before the critters so none of them takes it. */
    private function eggGlyph(): string
    {
        return $this->glyph('entity:@egg', 'o', MapGlyphs::ENTITY_POOL);
    }

    /** Lowercase initial of the prefab, else the next free lowercase letter. */
    private function entityGlyph(string $prefabId): string
    {
        $initial = mb_strtolower(mb_substr($prefabId, 0, 1));

        return $this->glyph('entity:'.$prefabId, str_contains(MapGlyphs::ENTITY_POOL, $initial) ? $initial : null, MapGlyphs::ENTITY_POOL);
    }

    /**
     * The glyph of a key, assigned once per render: the preferred glyph when free, else the
     * first free glyph of the pool (skipping reserved ones), else the overflow glyph.
     *
     * @param  list<string>  $reserved
     */
    private function glyph(string $key, ?string $preferred, string $pool, array $reserved = []): string
    {
        if (isset($this->assigned[$key])) {
            return $this->assigned[$key];
        }

        $namespace = mb_strstr($key, ':', true);
        $taken = [];
        foreach ($this->assigned as $assignedKey => $glyph) {
            if (str_starts_with($assignedKey, $namespace.':') || ($namespace === 'entity' && str_starts_with($assignedKey, 'building:'))) {
                $taken[$glyph] = true;
            }
        }

        $glyph = $preferred !== null && ! isset($taken[$preferred]) ? $preferred : null;
        foreach (mb_str_split($pool) as $candidate) {
            if ($glyph !== null) {
                break;
            }
            if (! isset($taken[$candidate]) && ! in_array($candidate, $reserved, true)) {
                $glyph = $candidate;
            }
        }

        return $this->assigned[$key] = $glyph ?? MapGlyphs::OVERFLOW;
    }
}
