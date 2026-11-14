<?php

declare(strict_types=1);

namespace App\Data\GameRules;

use Spatie\LaravelData\Data;

/** How many critters of one species to remove to fix the crowding. */
final class CritterOverOccupancyData extends Data
{
    public function __construct(
        public string $prefabId,
        public int $count,
        public int $personalSpace,
        public int $toRemove,
        /** Whether removing this species alone is enough. */
        public bool $canFix,
        /** True when computed from the future overflow (eggs), which the game does not compute. */
        public bool $derived,
    ) {}
}
