<?php

declare(strict_types=1);

namespace App\Data\GameRules;

use Spatie\LaravelData\Data;

/** The overcrowding states of a region, from the point of view of one critter species. */
final class OccupancyVerdictData extends Data
{
    /**
     * @param  list<CritterOverOccupancyData>  $perType
     */
    public function __construct(
        public int $cellCount,
        public int $occupied,
        public int $eggOccupied,
        public int $free,
        public bool $overcrowded,
        public bool $cramped,
        public bool $confined,
        public int $happinessModifier,
        public array $perType,
    ) {}
}
