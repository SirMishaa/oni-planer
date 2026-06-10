<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterDietData extends Data
{
    public function __construct(
        public ElementRefData $consumed,
        public ?ElementRefData $produced,
        public float $amount_per_cycle,
        public ?float $conversion_ratio,
    ) {}
}
