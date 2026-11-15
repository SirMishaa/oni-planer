<?php

declare(strict_types=1);

namespace App\Listeners\Uplink;

use App\Events\Uplink\ZoneStateStored;
use App\Models\Zone;
use App\Services\Critters\OccupancyInputBuilder;
use App\Services\GameRules\Critters\Overcrowding;
use App\Services\Zones\ZoneCritterState;
use Illuminate\Support\Facades\Log;

/**
 * Compares the planner's overcrowding rule with the regions the game reported in the fast state, and keeps
 * the latest disagreements on the zone for the Uplink inspector.
 *
 * Eggs come from the slow state and lag behind the fast one, so the planner takes `eggOccupied` from the game:
 * only `occupied` and, per species, `overcrowded`, `confined` and `modifier` are compared. `cramped` and
 * `eggOccupied` depend on the egg list and are never compared. `confined` is compared one way only: a mismatch
 * is the game saying confined while the planner does not, because the planner cannot know the confinement
 * immunity of a burrowed or digger species. A mismatch is logged and stored only when the set of mismatches
 * (ignoring `at`) changes, so a persisting one keeps its first `at`.
 */
final class CheckCrowdingConsistency
{
    public function __construct(
        private readonly OccupancyInputBuilder $builder,
        private readonly Overcrowding $overcrowding,
    ) {}

    public function handle(ZoneStateStored $event): void
    {
        if ($event->kind !== 'fast') {
            return;
        }

        $zone = Zone::query()
            ->where('colony_guid', $event->colonyGuid)
            ->where('zone_guid', $event->zoneGuid)
            ->first();

        $regions = $zone instanceof Zone ? ZoneCritterState::regions($zone) : null;
        if ($regions === null) {
            return;
        }

        $eggs = ZoneCritterState::eggs($zone) ?? [];
        $at = now()->toIso8601String();

        $mismatches = [];
        foreach ($regions as $region) {
            $input = $this->builder->forRegion($region, $eggs);
            if ($input === null) {
                Log::warning('crowding check skipped, unknown prefab', [
                    'zone' => $zone->zone_guid,
                    'regionId' => $region->id,
                    'prefabs' => $this->builder->unknownPrefabs(),
                ]);

                continue;
            }

            $verdict = $this->overcrowding->analyze($input, 1);
            if ($verdict->occupied !== $region->occupied) {
                $mismatches[] = $this->mismatch($region->id, '', 'occupied', $region->occupied, $verdict->occupied, $at);
            }

            foreach ($region->states as $prefabId => $game) {
                $planner = $this->overcrowding->analyze($input, (int) $this->builder->personalSpace((string) $prefabId));

                foreach ([
                    'overcrowded' => [$game['overcrowded'], $planner->overcrowded],
                    'confined' => [$game['confined'], $planner->confined],
                    'modifier' => [$game['modifier'], $planner->happinessModifier],
                ] as $field => [$gameValue, $plannerValue]) {
                    $oneWay = $field === 'confined';
                    if ($gameValue !== $plannerValue && ! ($oneWay && ! $gameValue)) {
                        $mismatches[] = $this->mismatch($region->id, (string) $prefabId, $field, $gameValue, $plannerValue, $at);
                    }
                }
            }
        }

        $previous = $zone->crowding_mismatches ?? [];
        $withoutTime = static fn (array $rows): array => array_map(static fn (array $row): array => array_diff_key($row, ['at' => true]), $rows);
        if ($withoutTime($mismatches) === $withoutTime($previous)) {
            return;
        }

        if ($mismatches !== []) {
            Log::warning('crowding mismatch', ['zone' => $zone->zone_guid, 'mismatches' => $mismatches]);
        }

        $zone->forceFill(['crowding_mismatches' => $mismatches === [] ? null : $mismatches])->saveQuietly();
    }

    /**
     * @return array{regionId: string, prefabId: string, field: string, game: int|bool, planner: int|bool, at: string}
     */
    private function mismatch(string $regionId, string $prefabId, string $field, int|bool $game, int|bool $planner, string $at): array
    {
        return compact('regionId', 'prefabId', 'field', 'game', 'planner', 'at');
    }
}
