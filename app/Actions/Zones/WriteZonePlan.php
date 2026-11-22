<?php

declare(strict_types=1);

namespace App\Actions\Zones;

use App\Enums\ZonePlanChangeKind;
use App\Enums\ZonePlanChangeState;
use App\Events\ZoneUpdated;
use App\Models\Building;
use App\Models\Zone;
use App\Models\ZonePlan;
use App\Services\Zones\Plans\PlanPlacement;
use App\Services\Zones\ZoneGeometry;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Replaces the plan of a zone with the changes Claude wrote, all or nothing: every change is
 * resolved (prefab, world cell, target in the real layout), saved, then checked in order against
 * the real layout plus the plan's other changes. Any problem rolls the whole write back and the
 * problems come back one line each, prefixed with the change index. An empty list clears the plan.
 */
final readonly class WriteZonePlan
{
    /** Buildings of this object layer are what the mod sends, so only they are checked and applied. */
    private const string CHECKED_LAYER = 'Building';

    public function __construct(
        private PlanPlacement $placement,
        private ReconcileZonePlan $reconcile,
    ) {}

    /**
     * @param  list<array{kind: string, prefab?: string|null, x?: int|null, y?: int|null, orientation?: string|null, target?: string|null, note: string}>  $changes  world coordinates, bottom-left anchor
     * @return ZonePlan|list<string>|null the written plan, the problems that refused it, or null once cleared
     */
    public function handle(Zone $zone, array $changes): ZonePlan|array|null
    {
        if ($zone->transient) {
            return [sprintf('Zone #%d is a temporary zone (made for one question in the game): plans are kept for the zones of the colony only.', $zone->id)];
        }

        if ($changes === []) {
            $zone->plan()->delete();
            $this->announce($zone);

            return null;
        }

        $layout = $zone->layout;
        $gridWidth = is_array($layout) ? (int) ($layout['gridWidth'] ?? 0) : 0;
        if (! is_array($layout) || $gridWidth <= 0) {
            return [sprintf('The layout of zone #%d has not arrived from the game yet: nothing to plan against, try again once the game has sent it.', $zone->id)];
        }

        [$rows, $errors] = $this->resolve($layout, $gridWidth, $changes);
        if ($rows === []) {
            return $this->flatten($errors);
        }

        DB::beginTransaction();

        try {
            $zone->plan()->delete();
            $plan = ZonePlan::query()->create(['zone_id' => $zone->id]);
            $plan->changes()->createMany($rows);

            $this->reconcile->handle($zone);

            $changes = $plan->changes()->get();
            $positions = $changes->pluck('position', 'id')->all();
            foreach ($changes as $change) {
                $problem = $this->placement->problem($zone, $plan, $change);
                if ($problem !== null) {
                    $errors[$change->position][] = $this->byPosition($problem, $positions);
                }
            }

            if ($errors !== []) {
                DB::rollBack();

                return $this->flatten($errors);
            }

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $this->announce($zone);

        return $plan;
    }

    /**
     * The rows to store for the changes that resolve, and the problems of the others by position
     * (1-based). A change that does not resolve is left out, so the rest can still be checked.
     *
     * @param  array<string, mixed>  $layout
     * @param  list<array{kind: string, prefab?: string|null, x?: int|null, y?: int|null, orientation?: string|null, target?: string|null, note: string}>  $changes
     * @return array{0: list<array<string, mixed>>, 1: array<int, list<string>>}
     */
    private function resolve(array $layout, int $gridWidth, array $changes): array
    {
        $prefabIds = array_values(array_filter(array_map(fn (array $change): ?string => $change['prefab'] ?? null, $changes)));
        $layers = Building::query()->whereIn('building_id', $prefabIds)->pluck('object_layer', 'building_id')->all();

        $rows = [];
        $errors = [];
        foreach ($changes as $index => $change) {
            $kind = ZonePlanChangeKind::from($change['kind']);
            $row = [
                'position' => $index + 1,
                'kind' => $kind,
                'state' => ZonePlanChangeState::Pending,
                'prefab_id' => null,
                'anchor_cell' => null,
                'orientation' => $change['orientation'] ?? 'Neutral',
                'object_layer' => null,
                'checked' => true,
                'target_instance_id' => null,
                'target_cell' => null,
                'note' => $change['note'],
            ];
            $problems = [];

            if ($kind !== ZonePlanChangeKind::Remove) {
                $prefabId = $change['prefab'] ?? null;
                if ($prefabId === null || $prefabId === '') {
                    $problems[] = sprintf('this %s needs a prefab', $kind->value);
                } elseif (! array_key_exists($prefabId, $layers)) {
                    $problems[] = sprintf('unknown prefab %s (building ids are exact, find them with lookup)', $prefabId);
                } else {
                    $row['prefab_id'] = $prefabId;
                    $row['object_layer'] = $layers[$prefabId];
                    $row['checked'] = $layers[$prefabId] === self::CHECKED_LAYER;
                }

                if (! isset($change['x'], $change['y'])) {
                    $problems[] = sprintf('this %s needs x and y (world cell of the bottom-left corner)', $kind->value);
                } else {
                    $row['anchor_cell'] = ZoneGeometry::cell((int) $change['x'], (int) $change['y'], $gridWidth);
                }
            }

            if ($kind !== ZonePlanChangeKind::Add) {
                $target = $this->target($layout, $gridWidth, (string) ($change['target'] ?? ''));
                if (is_string($target)) {
                    $problems[] = $target;
                } else {
                    $row = [...$row, ...$target];
                }
            }

            if ($problems === []) {
                $rows[] = $row;
            } else {
                $errors[$index + 1] = $problems;
            }
        }

        return [$rows, $errors];
    }

    /**
     * The stored target of a remove or replace: `#instanceId` names a building, `w(x,y)` a tile
     * cell, either of the real layout. A string is the problem with it.
     *
     * @param  array<string, mixed>  $layout
     * @return array{target_instance_id: string}|array{target_cell: int}|string
     */
    private function target(array $layout, int $gridWidth, string $target): array|string
    {
        if (preg_match('/^#(\S+)$/', $target, $match) === 1) {
            foreach ((array) ($layout['buildings'] ?? []) as $building) {
                if ((string) ($building['instanceId'] ?? '') === $match[1]) {
                    return ['target_instance_id' => $match[1]];
                }
            }

            return sprintf('target %s is not a building of the zone layout', $target);
        }

        if (preg_match('/^w\((-?\d+),\s*(-?\d+)\)$/', $target, $match) === 1) {
            $cell = ZoneGeometry::cell((int) $match[1], (int) $match[2], $gridWidth);
            foreach ((array) ($layout['tiles'] ?? []) as $tile) {
                if (($tile['cell'] ?? null) === $cell) {
                    return ['target_cell' => $cell];
                }
            }

            return sprintf('target %s is not a tile of the zone layout', $target);
        }

        return sprintf('target "%s" must be "#instanceId" (a building) or "w(x,y)" (a tile cell)', $target);
    }

    /**
     * A placement problem with the planned buildings it cites (`#plan:{id}`, ids the rollback
     * discards) named by their change position instead.
     *
     * @param  array<int, int>  $positions  change positions by id
     */
    private function byPosition(string $problem, array $positions): string
    {
        return (string) preg_replace_callback('/#plan:(\d+)/', fn (array $match): string => isset($positions[(int) $match[1]]) ? 'change '.$positions[(int) $match[1]] : $match[0], $problem);
    }

    /**
     * One line per problem, in change order: `change 2: ...`.
     *
     * @param  array<int, list<string>>  $errors  problems by change position
     * @return list<string>
     */
    private function flatten(array $errors): array
    {
        ksort($errors);
        $lines = [];
        foreach ($errors as $position => $problems) {
            foreach ($problems as $problem) {
                $lines[] = sprintf('change %d: %s', $position, $problem);
            }
        }

        return $lines;
    }

    /** Tells open pages the plan changed. */
    private function announce(Zone $zone): void
    {
        ZoneUpdated::dispatch($zone->zone_guid, $zone->colony_guid, 'plan');
    }
}
