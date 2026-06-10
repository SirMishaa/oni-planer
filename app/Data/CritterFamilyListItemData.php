<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterFamilyListItemData extends Data
{
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $image,
        public int $variant_count,
        public ?string $eats_summary,
        public ?string $makes_summary,
    ) {}
}
