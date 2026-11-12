<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** A built foundation tile of the zone's box. */
final class ZoneTileData extends Data
{
    public function __construct(
        public int $x,
        public int $y,
        public string $prefabId,
        /**
         * Neighbours holding the same tile prefab, as the 4-bit mask of the
         * connection sprites (`/assets/game/connections/<prefabId>/<mask>.webp`):
         * left = 1, right = 2, up = 4, down = 8.
         */
        public int $connections,
    ) {}
}
