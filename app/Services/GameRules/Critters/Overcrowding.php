<?php

declare(strict_types=1);

namespace App\Services\GameRules\Critters;

use App\Data\GameRules\CritterOverOccupancyData;
use App\Data\GameRules\OccupancyInputData;
use App\Data\GameRules\OccupancyVerdictData;
use App\Data\GameRules\OccupantData;

/**
 * Overcrowding of a cavity or a pond, ported from the game's `OvercrowdingMonitor`.
 *
 * Pure and stateless. The verdict is from the point of view of one critter species, because the game evaluates
 * each critter against its own personal space (`Def.spaceRequiredPerCreature`).
 *
 * The three states are the game's `RegionAnalysis` properties: `IsOvercrowded`, `IsFutureOvercrowded` (cramped)
 * and `IsConfined`. The game also lets Confined suppress the two others on the critter; this class reports each
 * state on its own so the caller decides what to show.
 */
final class Overcrowding
{
    /** `OvercrowdingMonitor.Instance`: fertility modifier of the Cramped and Confined effects (multiplier). */
    public const FERTILITY_MULTIPLIER = -1.0;

    /** `OvercrowdingMonitor.Instance`: happiness modifier of the Confined effect. */
    public const CONFINED_HAPPINESS = -10;

    /**
     * @param  int  $personalSpace  `Def.spaceRequiredPerCreature` of the critter seen from
     * @param  bool  $confinementImmune  the critter has one of `CONFINEMENT_IMMUNITY_TAGS` (burrowed, digger)
     */
    public function analyze(OccupancyInputData $input, int $personalSpace, bool $confinementImmune = false): OccupancyVerdictData
    {
        $critters = $this->inRegion($input->critters, $input->isPond);
        $eggs = $this->inRegion($input->eggs, $input->isPond);

        $occupied = $this->totalSpace($critters);
        $eggOccupied = $this->totalSpace($eggs);
        $free = $input->cellCount - $occupied;

        if ($personalSpace === 0) {
            return new OccupancyVerdictData($input->cellCount, $occupied, $eggOccupied, $free, false, false, false, 0, []);
        }

        $overcrowded = $free < 0;
        $cramped = $free - $eggOccupied < 0 && $eggOccupied > 0;
        $confined = ! $confinementImmune && $input->cellCount < $personalSpace;

        $happinessModifier = $overcrowded
            ? -$this->ceilDivide($occupied - $input->cellCount, $personalSpace)
            : 0;

        $perType = match (true) {
            $overcrowded => $this->perType($critters, $occupied - $input->cellCount, false),
            $cramped => $this->perType($critters, $occupied + $eggOccupied - $input->cellCount, true),
            default => [],
        };

        return new OccupancyVerdictData(
            $input->cellCount,
            $occupied,
            $eggOccupied,
            $free,
            $overcrowded,
            $cramped,
            $confined,
            $happinessModifier,
            $perType,
        );
    }

    /**
     * `Occupancy.Analyze(..., excludeFish)`: a land cavity ignores fish, a pond counts only fish.
     *
     * @param  list<OccupantData>  $occupants
     * @return list<OccupantData>
     */
    private function inRegion(array $occupants, bool $isPond): array
    {
        return array_values(array_filter(
            $occupants,
            static fn (OccupantData $occupant): bool => $occupant->aquatic === $isPond,
        ));
    }

    /**
     * @param  list<OccupantData>  $occupants
     */
    private function totalSpace(array $occupants): int
    {
        return array_sum(array_map(static fn (OccupantData $occupant): int => $occupant->personalSpace, $occupants));
    }

    /**
     * `RegionAnalysis.BuildCritterOccupancies`: per species, how many to remove and whether that alone fixes it,
     * sorted by occupied space, largest first.
     *
     * @param  list<OccupantData>  $critters
     * @return list<CritterOverOccupancyData>
     */
    private function perType(array $critters, int $overOccupiedCells, bool $derived): array
    {
        $species = [];
        foreach ($critters as $critter) {
            $species[$critter->prefabId] ??= ['count' => 0, 'space' => $critter->personalSpace];
            $species[$critter->prefabId]['count']++;
        }

        $types = [];
        foreach ($species as $prefabId => ['count' => $count, 'space' => $space]) {
            $needed = $space === 0 ? 0 : $this->ceilDivide($overOccupiedCells, $space);
            $types[] = new CritterOverOccupancyData((string) $prefabId, $count, $space, min($count, $needed), $needed <= $count, $derived);
        }

        usort(
            $types,
            static fn (CritterOverOccupancyData $a, CritterOverOccupancyData $b): int => $b->count * $b->personalSpace <=> $a->count * $a->personalSpace,
        );

        return $types;
    }

    /**
     * `RegionAnalysis.ComputeOverOccupiedCritterCount`: `Math.DivRem` plus one when there is a remainder.
     */
    private function ceilDivide(int $dividend, int $divisor): int
    {
        return intdiv($dividend, $divisor) + ($dividend % $divisor !== 0 ? 1 : 0);
    }
}
