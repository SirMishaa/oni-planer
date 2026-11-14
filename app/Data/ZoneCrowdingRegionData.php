<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** A cavity or a pond as the game counts it for overcrowding, from the fast state. */
final class ZoneCrowdingRegionData extends Data
{
    /**
     * @param  array<string, int>  $counts  critters per species
     * @param  array<string, array{overcrowded: bool, cramped: bool, confined: bool, modifier: int}>  $states  per species
     */
    public function __construct(
        public string $id,
        /** One of cavity, pond. */
        public string $kind,
        public int $cellCount,
        public int $occupied,
        public int $eggOccupied,
        public array $counts,
        public array $states,
    ) {}
}
