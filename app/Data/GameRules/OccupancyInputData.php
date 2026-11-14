<?php

declare(strict_types=1);

namespace App\Data\GameRules;

use Spatie\LaravelData\Data;

/** A cavity or a pond with what lives in it, as the overcrowding rule reads it. */
final class OccupancyInputData extends Data
{
    /**
     * @param  list<OccupantData>  $critters
     * @param  list<OccupantData>  $eggs
     */
    public function __construct(
        public int $cellCount,
        public bool $isPond,
        public array $critters,
        public array $eggs,
    ) {}
}
