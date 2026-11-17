<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Data\ZoneEntityData;
use App\Data\ZoneFeedingData;
use App\Data\ZonePopulationData;
use App\Models\CritterMorph;
use App\Models\Zone;

/**
 * The ranch problems the planner derives from the feeding of the flocks and the settings of the
 * drop-off buildings: a flock about to run out of food, a flock no feeder accepts, a drop-off that
 * brings more critters than its room holds. Every text carries the derived marker.
 */
final class RanchProblems
{
    /** Cycles of stock under which a flock with a deficit is a problem. */
    public const float LOW_AUTONOMY_CYCLES = 3.0;

    /**
     * The drop-off buildings and the crowding region kind they release critters into.
     * `CritterPickUp` is left out: it removes critters.
     *
     * @var array<string, 'cavity'|'pond'>
     */
    public const array DROP_OFF_REGION_KIND = ['CritterDropOff' => 'cavity', 'FishDeliveryPoint' => 'pond'];

    /**
     * @param  list<ZoneFeedingData>  $feeding
     * @param  list<ZonePopulationData>  $population
     * @return list<ZoneEntityData>
     */
    public static function of(Zone $zone, array $feeding, array $population): array
    {
        $problems = [];
        $critters = null;

        foreach ($feeding as $flock) {
            $text = self::flockText($flock);
            if ($text === null) {
                continue;
            }

            $critters ??= ZoneCritterState::critters($zone) ?? [];
            $prefabId = explode(':', $flock->groupKey)[0];
            $first = array_find($critters, fn ($critter): bool => $critter->prefabId === $prefabId);

            $problems[] = new ZoneEntityData(
                kind: 'critter_group',
                instanceId: null,
                prefabId: $prefabId,
                name: $first->name ?? $prefabId,
                sprite: $first->sprite ?? null,
                x: null,
                y: null,
                state: 'not_operational',
                problems: [self::derived($text)],
                groupKey: $flock->groupKey,
                count: $flock->count,
            );
        }

        return [...$problems, ...self::dropOffProblems($zone, $population)];
    }

    private static function flockText(ZoneFeedingData $flock): ?string
    {
        if ($flock->blockedByFilter === true) {
            return __('zones.problems.blocked_by_filter');
        }

        if (($flock->deficitKgPerCycle ?? 0.0) > 0.0 && $flock->autonomyCycles !== null && $flock->autonomyCycles < self::LOW_AUTONOMY_CYCLES) {
            return __('zones.problems.low_stock', [
                'food' => $flock->foods[0]->name ?? '',
                'cycles' => number_format($flock->autonomyCycles, 1, '.', ''),
            ]);
        }

        return null;
    }

    /**
     * @param  list<ZonePopulationData>  $population
     * @return list<ZoneEntityData>
     */
    private static function dropOffProblems(Zone $zone, array $population): array
    {
        $buildings = $zone->fast_state['buildings'] ?? null;
        $problems = [];

        foreach (is_array($buildings) ? $buildings : [] as $building) {
            $prefabId = is_array($building) ? ($building['prefabId'] ?? null) : null;
            if (! is_string($prefabId) || ! isset(self::DROP_OFF_REGION_KIND[$prefabId])) {
                continue;
            }

            $settings = ZoneBuildingSettings::of($building);
            $limit = $settings?->capacity?->limit;
            $filter = $settings?->filter;
            if ($limit === null || $filter === null || $filter === []) {
                continue;
            }

            $regions = array_values(array_filter($population, fn (ZonePopulationData $region): bool => $region->kind === self::DROP_OFF_REGION_KIND[$prefabId]));
            if (count($regions) !== 1) {
                continue;
            }

            $personalSpace = self::smallestPersonalSpace($filter);
            if ($personalSpace === null) {
                continue;
            }

            $region = $regions[0];
            $present = 0;
            foreach ($region->types as $type) {
                if (in_array($type['prefabId'], $filter, true)) {
                    $present += $type['count'];
                }
            }
            $supported = $present + max(0, intdiv($region->cellCount - $region->occupied, $personalSpace));

            if ($limit <= $supported) {
                continue;
            }

            $problems[] = new ZoneEntityData(
                kind: 'building',
                instanceId: is_scalar($building['instanceId'] ?? null) ? (string) $building['instanceId'] : null,
                prefabId: $prefabId,
                name: is_string($building['name'] ?? null) ? $building['name'] : $prefabId,
                sprite: null,
                x: null,
                y: null,
                state: 'not_operational',
                problems: [self::derived(__('zones.problems.drop_off_over_room', ['limit' => (int) floor($limit), 'supported' => $supported]))],
            );
        }

        return $problems;
    }

    /** @param  list<string>  $prefabIds */
    private static function smallestPersonalSpace(array $prefabIds): ?int
    {
        $spaces = CritterMorph::query()
            ->whereIn('morph_id', $prefabIds)
            ->where('overcrowding_threshold', '>', 0)
            ->pluck('overcrowding_threshold');

        return $spaces->isEmpty() ? null : (int) $spaces->min();
    }

    private static function derived(string $text): string
    {
        return $text.' ('.__('zones.problems.derived').')';
    }
}
