<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Models\Zone;

/**
 * The problem rules of a zone's entities, shared by the zone page and the MCP server: which
 * fast-state buildings are passages (the zone's border, never a problem), and which plants
 * and buildings have a problem.
 */
final class ZoneProblems
{
    /**
     * Status item ids that make a building (or, later, a plant) a problem.
     * Taken from the game's Assembly-CSharp (build of 2026-07, ilspycmd):
     * - `MaterialsUnavailable`, `MaterialsUnavailableForRefill`: set by
     *   FetchListStatusItemUpdater on a ManualDeliveryKG that cannot be
     *   filled (the farm station short of fertilizer);
     * - `Broken`, `NeedPower`, `NotEnoughPower`, `Entombed`, `Flooded`,
     *   `MissingFoundation`: Database.BuildingStatusItems that stop a building;
     * - `Wilting`, `WiltingDomestic`, `WiltingNonGrowing`, `NeedsFertilizer`,
     *   `NeedsIrrigation`: Database.CreatureStatusItems of a plant that
     *   stopped growing (plants carry no status items in the fast state yet;
     *   their `wilting` flag is the rule today).
     * To be refined with real fast states (slice A, task S7).
     *
     * @var list<string>
     */
    /**
     * Passages of the game and of the player's mods (doors, ladders, fire
     * poles, stairs). On the zone or its border ring they are shown apart, as
     * the zone's border: never content, never a problem. Any other building
     * on the ring (feeder, fishing station, modded or unknown) is content.
     *
     * @var list<string>
     */
    public const array PASSAGE_PREFABS = [
        'Door',
        'ManualPressureDoor',
        'PressureDoor',
        'BunkerDoor',
        'InsulatedDoor',
        'GlassDoorSimple',
        'GlassDoorComplex',
        'WoodenDoor',
        'AIO_FacilityDoor',
        'AIO_WoodenDoor',
        'TinyDoor',
        'Ladder',
        'LadderFast',
        'AIO_MetalLadder',
        'FirePole',
        'Stairs',
    ];

    public const array PROBLEM_STATUS_IDS = [
        'MaterialsUnavailable',
        'MaterialsUnavailableForRefill',
        'Broken',
        'NeedPower',
        'NotEnoughPower',
        'Entombed',
        'Flooded',
        'MissingFoundation',
        'Wilting',
        'WiltingDomestic',
        'WiltingNonGrowing',
        'NeedsFertilizer',
        'NeedsIrrigation',
    ];

    /**
     * Entities of the zone with at least one problem, plus its critter
     * alerts (see {@see CritterProblems}). The count is null while unknown
     * (no fast state, or none of its plants, buildings and critters were
     * read) and partial when plants or buildings were not both read.
     *
     * @return array{count: int|null, partial: bool}
     */
    public static function summary(Zone $zone): array
    {
        $plants = self::listOrNull($zone->fast_state['plants'] ?? null);
        [$buildings] = self::splitPassages(self::listOrNull($zone->fast_state['buildings'] ?? null));

        $critters = ZoneCritterState::critters($zone);

        if ($plants === null && $buildings === null && $critters === null) {
            return ['count' => null, 'partial' => false];
        }

        $critterProblems = $critters === null ? 0 : count(CritterProblems::of($critters, app(ZonePopulation::class)->forZone($zone) ?? []));

        $withProblems = count(array_filter($plants ?? [], fn (array $plant): bool => self::forPlant($plant) !== []))
            + count(array_filter($buildings ?? [], fn (array $building): bool => self::forBuilding($building) !== []));

        return ['count' => $withProblems + $critterProblems, 'partial' => $plants === null || $buildings === null];
    }

    /**
     * Splits fast-state buildings into content and border passages, in the
     * order received; null (not read) stays null on both sides.
     *
     * @param  list<array<mixed>>|null  $buildings
     * @return array{0: list<array<mixed>>|null, 1: list<array<mixed>>}
     */
    public static function splitPassages(?array $buildings): array
    {
        if ($buildings === null) {
            return [null, []];
        }

        $isPassage = fn (array $building): bool => in_array($building['prefabId'] ?? null, self::PASSAGE_PREFABS, true);

        return [
            array_values(array_filter($buildings, fn (array $building): bool => ! $isPassage($building))),
            array_values(array_filter($buildings, $isPassage)),
        ];
    }

    /**
     * @param  array<mixed>  $plant
     * @return list<string>
     */
    public static function forPlant(array $plant): array
    {
        return ($plant['wilting'] ?? false) === true ? [__('zones.problems.wilting')] : [];
    }

    /**
     * Status items listed in {@see self::PROBLEM_STATUS_IDS}, by their game
     * text; a building the game reports as not operational without such a
     * status gets a generic "not operational".
     *
     * @param  array<mixed>  $building
     * @return list<string>
     */
    public static function forBuilding(array $building): array
    {
        $problems = [];
        foreach (self::listOrNull($building['statusItems'] ?? null) ?? [] as $status) {
            $id = is_scalar($status['id'] ?? null) ? (string) $status['id'] : '';
            if (in_array($id, self::PROBLEM_STATUS_IDS, true)) {
                $name = $status['name'] ?? null;
                $problems[] = is_scalar($name) && (string) $name !== '' ? (string) $name : $id;
            }
        }

        if ($problems === [] && ($building['operational'] ?? null) === false) {
            $problems[] = __('zones.problems.not_operational');
        }

        return $problems;
    }

    /**
     * @return list<array<mixed>>|null
     */
    private static function listOrNull(mixed $value): ?array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : null;
    }
}
