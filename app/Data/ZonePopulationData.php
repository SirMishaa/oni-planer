<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\GameRules\CritterOverOccupancyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** The crowding of one cavity or pond of a zone: the game's verdict and how many critters to remove. */
final class ZonePopulationData extends Data
{
    /**
     * @param  list<array{id: 'confined'|'overcrowded'|'cramped', name: string}>  $states  the states to show, named from the game strings: Confined alone when set (the game lets it suppress the two others), else Overcrowded then Cramped
     * @param  list<CritterOverOccupancyData>  $perType  species to remove from, largest occupied space first; empty when no removal is needed or a species is unknown to the game data
     * @param  list<array{prefabId: string, name: string, count: int, personalSpace: int|null}>  $types  the critters of the region per species
     */
    public function __construct(
        public string $regionId,
        /** One of cavity, pond. */
        public string $kind,
        public int $cellCount,
        /** Cells taken by the critters. */
        public int $occupied,
        /** Cells the eggs will take once hatched, as the game counts them. */
        public int $eggOccupied,
        /** The game's raw states: true when any species of the region is in that state. */
        public bool $overcrowded,
        public bool $cramped,
        public bool $confined,
        #[LiteralTypeScriptType("{ id: 'confined' | 'overcrowded' | 'cramped'; name: string }[]")]
        public array $states,
        public array $perType,
        public array $types,
    ) {}
}
