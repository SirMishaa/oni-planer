<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class BuildingData extends Data
{
    public function __construct(
        public string $building_id,
        /** @var array<string, string> */
        public array $name,
        public string $category,
        public int $width,
        public int $height,
        public ?float $power_consumption,
        public ?float $power_generation,
        public ?string $dlc_id,
    ) {}
}
