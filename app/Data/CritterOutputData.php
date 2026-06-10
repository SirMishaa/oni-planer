<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterOutputData extends Data
{
    public function __construct(
        public string $kind,
        public string $element_id,
        public string $label,
        public ?string $icon,
        public ?string $url,
        public float $amount_per_cycle,
    ) {}
}
