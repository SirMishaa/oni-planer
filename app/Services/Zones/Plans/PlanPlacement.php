<?php

declare(strict_types=1);

namespace App\Services\Zones\Plans;

use App\Enums\ZonePlanChangeState;
use App\Models\Building;
use App\Models\Element;
use App\Models\Zone;
use App\Models\ZonePlan;
use App\Models\ZonePlanChange;
use App\Services\Mcp\McpFormat;
use App\Services\Mcp\ZoneTerrainSummary;
use App\Services\Zones\ZoneGeometry;

/**
 * Checks where a planned add can stand: inside the zone box, in a rotation the building permits,
 * clear of other buildings and tiles, off solid cells, and on a floor or under a ceiling when its
 * build rule asks for one. The context is the real layout with the plan's other changes applied.
 * Unchecked changes (layers the mod does not send), removes and applied adds are never checked.
 */
final class PlanPlacement
{
    /**
     * Orientations allowed by each `permitted_rotations` value of the game data, as the game's
     * `PermittedRotations` reads: `Unrotatable` keeps Neutral only, `R90` toggles between Neutral and
     * R90, `R360` turns through the four quarter turns, `FlipH` and `FlipV` mirror on one axis. A value
     * missing from this map (or a prefab without game data) is not checked.
     *
     * @var array<string, list<string>>
     */
    private const array PERMITTED_ORIENTATIONS = [
        'Unrotatable' => ['Neutral'],
        'R90' => ['Neutral', 'R90'],
        'R360' => ['Neutral', 'R90', 'R180', 'R270'],
        'FlipH' => ['Neutral', 'FlipH'],
        'FlipV' => ['Neutral', 'FlipV'],
    ];

    public function __construct(private readonly ZonePlanLayout $planLayout) {}

    /**
     * Why a change cannot stand where it is planned, or null when it can. Reads the plan's changes
     * afresh, so it works right after they are saved; `$change` itself is taken as given (its
     * unsaved position included) and left out of the context.
     */
    public function problem(Zone $zone, ZonePlan $plan, ZonePlanChange $change): ?string
    {
        return $this->problemAmong($zone, $plan->changes()->get(), $change);
    }

    /**
     * The adds that stand now but would not once `$without` is gone from the plan.
     *
     * @return list<ZonePlanChange>
     */
    public function dependents(Zone $zone, ZonePlan $plan, ZonePlanChange $without): array
    {
        $changes = $plan->changes()->get();
        $remaining = $changes->reject(fn (ZonePlanChange $change): bool => $change->id === $without->id)->values();

        $dependents = [];
        foreach ($remaining as $change) {
            if ($this->problemAmong($zone, $remaining, $change) !== null && $this->problemAmong($zone, $changes, $change) === null) {
                $dependents[] = $change;
            }
        }

        return $dependents;
    }

    /**
     * Why a change cannot stand among the given plan changes, or null when it can. Unchecked
     * changes (wires, pipes, logic…) live on other layers and never block a checked one.
     *
     * @param  iterable<ZonePlanChange>  $changes
     */
    public function problemAmong(Zone $zone, iterable $changes, ZonePlanChange $change): ?string
    {
        $layout = $zone->layout;
        if (! $change->checked || ! $change->adds() || $change->state === ZonePlanChangeState::Applied
            || $change->prefab_id === null || $change->anchor_cell === null || ! is_array($layout)) {
            return null;
        }

        $gridWidth = (int) ($layout['gridWidth'] ?? 0);
        if ($gridWidth <= 0) {
            return null;
        }

        $changes = collect($changes)->filter(fn (ZonePlanChange $other): bool => $other->checked)->values();
        $definitions = $this->planLayout->definitions($changes->concat([$change]));
        $context = $this->withoutTarget($this->planLayout->apply($layout, $changes, $definitions, exceptChangeId: $change->id), $change);

        $definition = $definitions[$change->prefab_id] ?? ['width' => 1, 'height' => 1, 'isTile' => false];
        [$width, $height] = $definition['isTile'] ? [1, 1] : PlanFootprint::size($definition['width'], $definition['height'], $change->orientation);
        $cells = PlanFootprint::cells($change->anchor_cell, $width, $height, $gridWidth);
        [$anchorX, $anchorY] = ZoneGeometry::xy($change->anchor_cell, $gridWidth);

        $reason = $this->reason($zone, $layout, $context, $change, $cells, $anchorX, $anchorY, $width, $height, $gridWidth);

        return $reason === null ? null : sprintf('%s at %s: %s.', $change->prefab_id, McpFormat::world($anchorX, $anchorY), $reason);
    }

    /**
     * The first blocker of a footprint, in the order box, rotation, overlap, solid cell, support.
     *
     * @param  array<string, mixed>  $layout  the real layout
     * @param  array<string, mixed>  $context  the real layout with the plan's other changes
     * @param  list<int>  $cells
     */
    private function reason(Zone $zone, array $layout, array $context, ZonePlanChange $change, array $cells, int $anchorX, int $anchorY, int $width, int $height, int $gridWidth): ?string
    {
        $box = is_array($layout['box'] ?? null) ? $layout['box'] : null;
        foreach ($cells as $cell) {
            [$x, $y] = ZoneGeometry::xy($cell, $gridWidth);
            if ($box !== null && ($x < (int) ($box['x'] ?? 0) || $y < (int) ($box['y'] ?? 0)
                || $x >= (int) ($box['x'] ?? 0) + (int) ($box['width'] ?? 0) || $y >= (int) ($box['y'] ?? 0) + (int) ($box['height'] ?? 0))) {
                return McpFormat::world($x, $y).' is outside the zone box';
            }
        }

        /** @var Building|null $building */
        $building = Building::query()->where('building_id', $change->prefab_id)->first(['building_id', 'build_location_rule', 'permitted_rotations']);
        $permitted = self::PERMITTED_ORIENTATIONS[(string) $building?->permitted_rotations] ?? null;
        if ($permitted !== null && ! in_array($change->orientation, $permitted, true)) {
            return sprintf('rotation %s not permitted for %s', $change->orientation, $change->prefab_id);
        }

        $occupants = $this->occupants($context, $gridWidth);
        foreach ($cells as $cell) {
            if (isset($occupants[$cell])) {
                [$x, $y] = ZoneGeometry::xy($cell, $gridWidth);

                return sprintf('%s overlaps %s', McpFormat::world($x, $y), $occupants[$cell]);
            }
        }

        $solid = $this->solidCells($zone, $layout, $gridWidth);
        foreach ($cells as $cell) {
            if (isset($solid[$cell])) {
                [$x, $y] = ZoneGeometry::xy($cell, $gridWidth);

                return McpFormat::world($x, $y).' is a solid cell';
            }
        }

        $supportY = match ($building?->build_location_rule) {
            'OnFloor' => $anchorY - 1,
            'OnCeiling' => $anchorY + $height,
            default => null,
        };
        if ($supportY === null) {
            return null;
        }

        $tiles = [];
        foreach ((array) ($context['tiles'] ?? []) as $tile) {
            $tiles[(int) ($tile['cell'] ?? -1)] = true;
        }
        for ($x = $anchorX; $x < $anchorX + $width; $x++) {
            $cell = ZoneGeometry::cell($x, $supportY, $gridWidth);
            if (! isset($tiles[$cell]) && ! isset($solid[$cell])) {
                return sprintf($building->build_location_rule === 'OnFloor' ? 'needs a floor under %s' : 'needs a ceiling above %s', McpFormat::world($x, $supportY));
            }
        }

        return null;
    }

    /**
     * A replace leaves its own target out of its context, as a remove would.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withoutTarget(array $context, ZonePlanChange $change): array
    {
        return $change->removes() ? ZonePlanLayout::withoutTarget($context, $change) : $context;
    }

    /**
     * What stands on each cell: "#7001 FarmStation" for a building, "tile FarmTile" for a tile.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function occupants(array $context, int $gridWidth): array
    {
        $occupants = [];
        foreach ((array) ($context['tiles'] ?? []) as $tile) {
            $occupants[(int) ($tile['cell'] ?? -1)] = 'tile '.($tile['prefabId'] ?? '?');
        }
        foreach ((array) ($context['buildings'] ?? []) as $building) {
            $label = sprintf('#%s %s', $building['instanceId'] ?? '?', $building['prefabId'] ?? '?');
            [$width, $height] = [(int) ($building['width'] ?? 1), (int) ($building['height'] ?? 1)];
            foreach (ZoneGeometry::footprint((int) ($building['anchorCell'] ?? 0), max(1, $width), max(1, $height), $gridWidth) as $cell) {
                $occupants[$cell] = $label;
            }
        }

        return $occupants;
    }

    /**
     * Cells of the box whose element is solid, the real tiles left out (a built tile holds its
     * material as a solid element).
     *
     * @param  array<string, mixed>  $layout
     * @return array<int, true>
     */
    private function solidCells(Zone $zone, array $layout, int $gridWidth): array
    {
        $slow = is_array($zone->slow_state) ? $zone->slow_state : null;
        $box = is_array($slow['box'] ?? null) ? $slow['box'] : null;
        if ($slow === null || $box === null) {
            return [];
        }

        $palette = array_values(array_filter((array) ($slow['palette'] ?? []), is_string(...)));
        $solidElements = array_flip(Element::query()->whereIn('element_id', $palette)->where('state', 'solid')->pluck('element_id')->all());

        $realTiles = [];
        foreach ((array) ($layout['tiles'] ?? []) as $tile) {
            $realTiles[(int) ($tile['cell'] ?? -1)] = true;
        }

        $solid = [];
        for ($y = (int) ($box['y'] ?? 0); $y < (int) ($box['y'] ?? 0) + (int) ($box['height'] ?? 0); $y++) {
            for ($x = (int) ($box['x'] ?? 0); $x < (int) ($box['x'] ?? 0) + (int) ($box['width'] ?? 0); $x++) {
                $cell = ZoneGeometry::cell($x, $y, $gridWidth);
                $reading = ZoneTerrainSummary::cellAt($slow, $x, $y);
                if ($reading !== null && isset($solidElements[$reading['elementId']]) && ! isset($realTiles[$cell])) {
                    $solid[$cell] = true;
                }
            }
        }

        return $solid;
    }
}
