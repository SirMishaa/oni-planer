<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** One entry of the terrain palette. */
final class ZoneTerrainElementData extends Data
{
    public function __construct(
        /** Null when the game sent no element id for these cells. */
        public ?string $elementId,
        /** `#rrggbb` ui colour of the element; null when unknown to the game data. */
        public ?string $colour,
    ) {}
}
