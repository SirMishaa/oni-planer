<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** A plant on the zone's cells, from the fast state. */
final class ZonePlantData extends Data
{
    public function __construct(
        public int $x,
        public int $y,
        public ?string $instanceId,
        public string $prefabId,
        public string $name,
        public ?string $sprite,
        /** Progress to the next harvest, 0 to 1; null when it cannot be told (wilting, no growth rate). */
        public ?float $growth,
        public ?float $secondsUntilHarvest,
        public bool $wilting,
        public bool $grown,
    ) {}
}
