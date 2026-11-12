<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** The element filling one cell of the zone's box, from the slow state. */
final class ZoneTerrainCellData extends Data
{
    public function __construct(
        public int $x,
        public int $y,
        /** Null when the game could not read the elements. */
        public ?string $elementId,
        /** `#rrggbb` ui colour of the element; null when unknown to the game data. */
        public ?string $colour,
        /** Kilograms; null when unread or not finite. */
        public ?float $mass,
        /** Kelvin; null when unread or not finite. */
        public ?float $temperature,
    ) {}
}
