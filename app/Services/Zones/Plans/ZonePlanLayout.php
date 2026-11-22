<?php

declare(strict_types=1);

namespace App\Services\Zones\Plans;

use App\Enums\ZonePlanChangeState;
use App\Models\Building;
use App\Models\ZonePlanChange;

/**
 * Applies a zone plan's changes to a raw zone layout, giving the layout the zone would have
 * once the plan is built. Planned buildings get the instance id `plan:{changeId}`.
 */
final class ZonePlanLayout
{
    /**
     * @param  array<string, mixed>  $layout
     * @param  iterable<ZonePlanChange>  $changes
     * @param  array<string, array{width: int, height: int, isTile: bool}>  $definitions
     * @return array<string, mixed>
     */
    public function apply(array $layout, iterable $changes, array $definitions, ?int $exceptChangeId = null): array
    {
        /** @var list<array<string, mixed>> $buildings */
        $buildings = array_values($layout['buildings'] ?? []);
        /** @var list<array<string, mixed>> $tiles */
        $tiles = array_values($layout['tiles'] ?? []);

        foreach ($changes as $change) {
            if ($change->id === $exceptChangeId || $change->state === ZonePlanChangeState::Applied) {
                continue;
            }

            if ($change->removes()) {
                $instanceId = $change->target_instance_id;
                $cell = $change->target_cell;
                if ($instanceId !== null) {
                    $buildings = array_values(array_filter($buildings, fn (array $building): bool => ($building['instanceId'] ?? null) !== $instanceId));
                }
                if ($cell !== null) {
                    $tiles = array_values(array_filter($tiles, fn (array $tile): bool => ($tile['cell'] ?? null) !== $cell));
                }
            }

            if (! $change->adds() || $change->prefab_id === null || $change->anchor_cell === null) {
                continue;
            }

            $definition = $definitions[$change->prefab_id] ?? ['width' => 1, 'height' => 1, 'isTile' => false];

            if ($definition['isTile']) {
                $tiles[] = ['cell' => $change->anchor_cell, 'prefabId' => $change->prefab_id, 'connections' => 0];

                continue;
            }

            [$width, $height] = PlanFootprint::size($definition['width'], $definition['height'], $change->orientation);
            $buildings[] = [
                'instanceId' => 'plan:'.$change->id,
                'anchorCell' => $change->anchor_cell,
                'prefabId' => $change->prefab_id,
                'width' => $width,
                'height' => $height,
                'orientation' => $change->orientation,
            ];
        }

        $layout['buildings'] = $buildings;
        $layout['tiles'] = $tiles;

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
                'isTile' => $building->build_location_rule === 'Tile',
            ];
        }

        return $definitions;
    }
}
