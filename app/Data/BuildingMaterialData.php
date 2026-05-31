<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class BuildingMaterialData extends Data
{
    public function __construct(
        public ?string $element_id,
        public ?string $material_category,
        public float $amount,
    ) {}
}
