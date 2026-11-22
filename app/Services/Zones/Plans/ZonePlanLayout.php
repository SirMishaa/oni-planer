<?php

declare(strict_types=1);

namespace App\Services\Zones\Plans;

use App\Enums\ZonePlanChangeState;
use App\Models\Building;
use App\Models\ZonePlanChange;
use App\Services\Zones\ZoneGeometry;

/**
 * Applies a zone plan's changes to a raw zone layout, giving the layout the zone would have
 * once the plan is built. Planned buildings get the instance id `plan:{changeId}`.
 *
 * The mod sends the game's FoundationTile layer as `tiles` (one entry per cell) and its Building
 * layer as `buildings`: a Door arrives as a building, a PressureDoor or WideFarmTile as one tile
 * per cell, a FishFeeder in both lists. A planned prefab is a tile only when it is a 1x1 of the
 * `Tile` build rule; every other one is a building over its real footprint.
 */
final class ZonePlanLayout
{
    /**
     * The layout without what a remove or replace takes out: its target building along with the
     * tile entries of the same prefab inside that building's footprint (the game sends some
     * buildings in both lists), or the tile on its target cell.
     *
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    public static function withoutTarget(array $layout, ZonePlanChange $change): array
    {
        $instanceId = $change->target_instance_id;
        if ($instanceId !== null) {
            $gridWidth = (int) ($layout['gridWidth'] ?? 0);
            $freed = [];
            foreach ((array) ($layout['buildings'] ?? []) as $building) {
                if ((string) ($building['instanceId'] ?? '') === $instanceId && $gridWidth > 0) {
                    $prefabId = $building['prefabId'] ?? null;
                    foreach (ZoneGeometry::footprint((int) ($building['anchorCell'] ?? 0), max(1, (int) ($building['width'] ?? 1)), max(1, (int) ($building['height'] ?? 1)), $gridWidth) as $cell) {
                        $freed[$cell] = $prefabId;
                    }
                }
            }

            $layout['buildings'] = array_values(array_filter((array) ($layout['buildings'] ?? []), fn (array $building): bool => (string) ($building['instanceId'] ?? '') !== $instanceId));
            $layout['tiles'] = array_values(array_filter((array) ($layout['tiles'] ?? []), fn (array $tile): bool => ! array_key_exists((int) ($tile['cell'] ?? -1), $freed)
                || $freed[(int) $tile['cell']] !== ($tile['prefabId'] ?? null)));
        }

        $cell = $change->target_cell;
        if ($cell !== null) {
            $layout['tiles'] = array_values(array_filter((array) ($layout['tiles'] ?? []), fn (array $tile): bool => ($tile['cell'] ?? null) !== $cell));
        }

        return $layout;
    }

    /** Whether a prefab of this build rule and size sits in the layout's tiles rather than its buildings. */
    public static function isTile(?string $buildLocationRule, int $width, int $height): bool
    {
        return $buildLocationRule === 'Tile' && $width === 1 && $height === 1;
    }

    /**
     * @param  array<string, mixed>  $layout
     * @param  iterable<ZonePlanChange>  $changes
     * @param  array<string, array{width: int, height: int, isTile: bool}>  $definitions
     * @return array<string, mixed>
     */
    public function apply(array $layout, iterable $changes, array $definitions, ?int $exceptChangeId = null): array
    {
        $layout['buildings'] = array_values($layout['buildings'] ?? []);
        $layout['tiles'] = array_values($layout['tiles'] ?? []);

        foreach ($changes as $change) {
            if ($change->id === $exceptChangeId || $change->state === ZonePlanChangeState::Applied) {
                continue;
            }

            if ($change->removes()) {
                $layout = self::withoutTarget($layout, $change);
            }

            if (! $change->adds() || $change->prefab_id === null || $change->anchor_cell === null) {
                continue;
            }

            $definition = $definitions[$change->prefab_id] ?? ['width' => 1, 'height' => 1, 'isTile' => false];

            if ($definition['isTile']) {
                $layout['tiles'][] = ['cell' => $change->anchor_cell, 'prefabId' => $change->prefab_id, 'connections' => 0];

                continue;
            }

            [$width, $height] = PlanFootprint::size($definition['width'], $definition['height'], $change->orientation);
            $layout['buildings'][] = [
                'instanceId' => 'plan:'.$change->id,
                'anchorCell' => $change->anchor_cell,
                'prefabId' => $change->prefab_id,
                'width' => $width,
                'height' => $height,
                'orientation' => $change->orientation,
            ];
        }

        return $layout;
    }

    /**
     * Footprint and tile-ness of every prefab the changes add.
     *
     * @param  iterable<ZonePlanChange>  $changes
     * @return array<string, array{width: int, height: int, isTile: bool}>
     */
    public function definitions(iterable $changes): array
    {
        $prefabIds = [];
        foreach ($changes as $change) {
            if ($change->prefab_id !== null) {
                $prefabIds[$change->prefab_id] = true;
            }
        }

        $definitions = [];
        foreach (Building::query()->whereIn('building_id', array_keys($prefabIds))->get(['building_id', 'width', 'height', 'build_location_rule']) as $building) {
            $definitions[$building->building_id] = [
                'width' => (int) $building->width,
                'height' => (int) $building->height,
                'isTile' => self::isTile($building->build_location_rule, (int) $building->width, (int) $building->height),
            ];
        }

        return $definitions;
    }
}
