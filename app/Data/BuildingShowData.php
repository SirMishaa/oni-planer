<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class BuildingShowData extends Data
{
    public function __construct(
        public string $building_id,
        public string $name,
        public string $category,
        public ?string $sub_group,
        public int $width,
        public int $height,
        public ?float $power_consumption,
        public ?float $power_generation,
        public ?float $heat_generation,
        public ?float $construction_time,
        public ?string $description,
        public ?string $effect,
        public ?string $dlc_id,
        /** @var list<BuildingMaterialData> */
        public array $materials,
    ) {}
}
