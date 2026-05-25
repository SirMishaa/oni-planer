<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class ElementData extends Data
{
    public function __construct(
        public string $element_id,
        /** @var array<string, string> */
        public array $name,
        public string $state,
        public string $material_category,
    ) {}
}
