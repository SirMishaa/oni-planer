<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class ElementData extends Data
{
    public function __construct(
        public string $element_id,
        public string $name,
        public string $state,
        public string $material_category,
    ) {}
}
