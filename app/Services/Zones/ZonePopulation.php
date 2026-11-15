<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Data\GameRules\OccupancyInputData;
use App\Data\GameRules\OccupantData;
use App\Data\ZoneCrowdingRegionData;
use App\Data\ZonePopulationData;
use App\Models\Zone;
use App\Services\Critters\CritterStatusText;
use App\Services\Critters\OccupancyInputBuilder;
use App\Services\GameRules\Critters\Overcrowding;
use App\Services\GameText;

/**
 * The population of each cavity and pond of a zone: the states the game reported (a state is on when any
 * species of the region has it) and, from the planner's port of the rule, how many critters to remove.
 *
 * Eggs lag behind the fast state, so the egg space is the game's `eggOccupied` and never the egg list.
 */
final class ZonePopulation
{
    public function __construct(
        private readonly OccupancyInputBuilder $builder,
        private readonly Overcrowding $overcrowding,
        private readonly GameText $text,
    ) {}

    /**
     * The crowding states to show, in order: Confined alone when set (the game lets it suppress the two
     * others), else Overcrowded then Cramped.
     *
     * @return list<'confined'|'overcrowded'|'cramped'>
     */
    public static function shownStates(bool $overcrowded, bool $cramped, bool $confined): array
    {
        if ($confined) {
            return ['confined'];
        }

        return array_values(array_filter([
            $overcrowded ? 'overcrowded' : null,
            $cramped ? 'cramped' : null,
        ]));
    }

    /**
     * Null when the game sent no critters or no crowding regions (unknown), an empty list when it sent no region.
     *
     * @return list<ZonePopulationData>|null
     */
    public function forZone(Zone $zone): ?array
    {
        $regions = ZoneCritterState::regions($zone);
        if (! is_array($zone->fast_state['critters'] ?? null) || $regions === null) {
            return null;
        }

        return array_map(function (ZoneCrowdingRegionData $region): ZonePopulationData {
            $states = array_values($region->states);
            $input = $this->builder->forRegion($region, []);
            $perType = [];
            if ($input !== null) {
                $hatching = $region->eggOccupied > 0 ? [new OccupantData('', $region->eggOccupied, $input->isPond, false)] : [];
                $perType = $this->overcrowding->analyze(new OccupancyInputData($input->cellCount, $input->isPond, $input->critters, $hatching), 1)->perType;
            }

            $types = [];
            foreach ($region->counts as $prefabId => $count) {
                $types[] = [
                    'prefabId' => $prefabId,
                    'name' => $this->text->name($prefabId),
                    'count' => $count,
                    'personalSpace' => $this->builder->personalSpace($prefabId),
                ];
            }

            $overcrowded = in_array(true, array_column($states, 'overcrowded'), true);
            $cramped = in_array(true, array_column($states, 'cramped'), true);
            $confined = in_array(true, array_column($states, 'confined'), true);

            return new ZonePopulationData(
                regionId: $region->id,
                kind: $region->kind,
                cellCount: $region->cellCount,
                occupied: $region->occupied,
                eggOccupied: $region->eggOccupied,
                overcrowded: $overcrowded,
                cramped: $cramped,
                confined: $confined,
                states: array_map(
                    static fn (string $id): array => ['id' => $id, 'name' => CritterStatusText::name($id)],
                    self::shownStates($overcrowded, $cramped, $confined),
                ),
                perType: $perType,
                types: $types,
            );
        }, $regions);
    }
}
