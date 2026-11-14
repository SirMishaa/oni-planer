<?php

declare(strict_types=1);

namespace App\Data\GameRules;

use Spatie\LaravelData\Data;

/** A critter or an egg in a region. For an egg, `personalSpace` is the space of the critter it will hatch into. */
final class OccupantData extends Data
{
    public function __construct(
        public string $prefabId,
        public int $personalSpace,
        public bool $aquatic,
        public bool $confinementImmune,
    ) {}
}
