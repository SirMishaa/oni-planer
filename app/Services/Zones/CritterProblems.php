<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Data\ZoneCritterData;
use App\Data\ZoneEntityData;
use App\Data\ZonePopulationData;
use App\Services\Critters\CritterGroupKey;
use App\Services\Critters\CritterStatusText;

/**
 * The critter alerts of a zone, as problems: one per crowded region (it carries the Cramped,
 * Overcrowded and Confined statuses and the removal), one per bad status shared by a whole flock
 * band (38 cramped Pacus are one problem, not 38) and one per critter with an alert of its own.
 */
final class CritterProblems
{
    /**
     * Status ids the region problem carries: dropped from the group and critter problems.
     * The game names them under MODIFIERS.OVERCROWDED, see {@see CritterStatusText}.
     *
     * @var list<string>
     */
    public const array CROWDING_STATUS_IDS = ['futureovercrowded', 'cramped', 'overcrowded', 'confined'];

    /**
     * @param  list<ZoneCritterData>  $critters
     * @param  list<ZonePopulationData>  $population
     * @return list<ZoneEntityData>
     */
    public static function of(array $critters, array $population): array
    {
        $groups = [];
        foreach ($critters as $critter) {
            $groups[CritterGroupKey::of($critter)][] = $critter;
        }
        uksort($groups, fn (string $a, string $b): int => count($groups[$b]) <=> count($groups[$a]) ?: strcmp($a, $b));

        $groupProblems = [];
        $critterProblems = [];
        foreach ($groups as $key => $members) {
            $shared = self::sharedStatuses($members);
            foreach ($shared as $status) {
                $groupProblems[] = self::groupProblem($key, $members, $status);
            }
            foreach ($members as $critter) {
                $own = array_values(array_diff(self::badStatuses($critter), $shared));
                if ($own !== []) {
                    $critterProblems[] = self::critterProblem($key, $critter, $own);
                }
            }
        }

        return [
            ...self::regionProblems($critters, $population),
            ...$groupProblems,
            ...$critterProblems,
        ];
    }

    /**
     * @param  list<ZoneCritterData>  $members
     * @return list<string>
     */
    private static function sharedStatuses(array $members): array
    {
        $shared = self::badStatuses($members[0]);
        foreach ($members as $critter) {
            $shared = array_values(array_intersect($shared, self::badStatuses($critter)));
        }

        return $shared;
    }

    /** @return list<string> display names of the bad statuses the region problem does not already carry */
    private static function badStatuses(ZoneCritterData $critter): array
    {
        $names = [];
        foreach ($critter->statuses as $status) {
            if ($status['bad'] && ! in_array(mb_strtolower($status['id']), self::CROWDING_STATUS_IDS, true)) {
                $names[] = $status['name'];
            }
        }

        return array_values(array_unique($names));
    }

    /** @param  list<ZoneCritterData>  $members */
    private static function groupProblem(string $key, array $members, string $status): ZoneEntityData
    {
        $first = $members[0];

        return new ZoneEntityData(
            kind: 'critter_group',
            instanceId: null,
            prefabId: $first->prefabId,
            name: count($members).' '.$first->name.' · '.$status,
            sprite: $first->sprite,
            x: null,
            y: null,
            state: 'not_operational',
            problems: [$status],
            groupKey: $key,
            count: count($members),
        );
    }

    /** @param  list<string>  $statuses */
    private static function critterProblem(string $key, ZoneCritterData $critter, array $statuses): ZoneEntityData
    {
        return new ZoneEntityData(
            kind: 'critter',
            instanceId: $critter->instanceId,
            prefabId: $critter->prefabId,
            name: $critter->name,
            sprite: $critter->sprite,
            x: (int) floor($critter->x),
            y: (int) floor($critter->y),
            state: 'not_operational',
            problems: $statuses,
            groupKey: $key,
        );
    }

    /**
     * @param  list<ZoneCritterData>  $critters
     * @param  list<ZonePopulationData>  $population
     * @return list<ZoneEntityData>
     */
    private static function regionProblems(array $critters, array $population): array
    {
        $problems = [];
        foreach ($population as $region) {
            $statuses = array_values(array_filter([
                $region->overcrowded ? CritterStatusText::name('Overcrowded') : null,
                $region->cramped ? CritterStatusText::name('Cramped') : null,
                $region->confined ? CritterStatusText::name('Confined') : null,
            ]));
            if ($statuses === []) {
                continue;
            }

            $names = array_column($region->types, 'name', 'prefabId');
            $removals = [];
            $derived = false;
            foreach ($region->perType as $type) {
                if ($type->toRemove > 0) {
                    $removals[] = __('zones.population.remove', ['count' => $type->toRemove]).' '.($names[$type->prefabId] ?? $type->prefabId);
                    $derived = $derived || $type->derived;
                }
            }

            $text = implode(', ', $statuses);
            if ($removals !== []) {
                $text .= ': '.implode(', ', $removals).($derived ? ' ('.__('zones.population.derived').')' : '');
            }

            $lead = $region->perType[0]->prefabId ?? array_key_first($names);
            $sprite = null;
            foreach ($critters as $critter) {
                if ($critter->prefabId === $lead) {
                    $sprite = $critter->sprite;
                    break;
                }
            }

            $problems[] = new ZoneEntityData(
                kind: 'critter_group',
                instanceId: $region->regionId,
                prefabId: (string) $lead,
                name: __("zones.problems.region.{$region->kind}"),
                sprite: $sprite,
                x: null,
                y: null,
                state: 'not_operational',
                problems: [$text],
                count: array_sum(array_column($region->types, 'count')),
            );
        }

        return $problems;
    }
}
