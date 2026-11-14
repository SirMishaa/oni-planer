<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** An egg of the zone, from the slow state. Values the game did not expose are null. */
final class ZoneEggData extends Data
{
    public function __construct(
        public string $instanceId,
        public string $prefabId,
        public string $name,
        public ?string $sprite,
        public int $cell,
        /** Incubation progress, 0 to 100. */
        public ?float $incubation,
        /** Viability, 0 to 100. */
        public ?float $viability,
        public bool $tame,
        public ?string $spawns,
        /** One of floor, incubator, storage. */
        public ?string $location,
        public ?string $holderId,
        public ?string $regionId,
        /** Total incubation of the hatching species in cycles (`critter_morphs.incubation_time`), null when the game data has no such species. */
        public ?float $incubationCycles = null,
    ) {}
}
