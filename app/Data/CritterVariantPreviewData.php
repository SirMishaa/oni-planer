<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterVariantPreviewData extends Data
{
    public function __construct(
        public string $critter_id,
        public string $name,
        public string $image,
    ) {}
}
