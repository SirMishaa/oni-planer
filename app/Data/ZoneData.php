<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** The headline of a zone page: what the zone is and when each part was last received. */
final class ZoneData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $worldId,
        public int $version,
        public int $cellCount,
        /** The zone's world (a rocket interior, a removed asteroid) no longer exists in the game. */
        public bool $orphaned,
        /** ISO 8601 times the layout, slow state and fast state were last stored; null when never received. */
        public ?string $layoutAt,
        public ?string $slowStateAt,
        public ?string $fastStateAt,
    ) {}
}
