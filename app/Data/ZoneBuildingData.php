<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** A completed building of the zone's box, placed by its occupied footprint. */
final class ZoneBuildingData extends Data
{
    public function __construct(
        public ?string $instanceId,
        /** Bottom-left cell of the footprint, after rotation. */
        public int $x,
        public int $y,
        /** Footprint after rotation. */
        public int $width,
        public int $height,
        /** The game's orientation name (Neutral, R90, R180, R270, FlipH, FlipV). */
        public ?string $orientation,
        public string $prefabId,
        /** Display name in the current locale; the prefab id when the game data does not know it. */
        public string $name,
        /** Root-relative URL of the building render, null when not imported. */
        public ?string $sprite,
    ) {}
}
