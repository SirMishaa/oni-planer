<?php

declare(strict_types=1);

namespace App\Data\Viability;

use Spatie\LaravelData\Data;

/** Critters of one species and tameness, with the calories of their drops. */
final class ViabilityCritterReserveData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $icon,
        public bool $tame,
        public int $count,
        /** Calories of their drops, null when a drop list is unknown. */
        public ?float $kcal,
    ) {}
}
