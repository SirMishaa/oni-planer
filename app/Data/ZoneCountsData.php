<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** Entities of a zone by kind, and how many have a problem. */
final class ZoneCountsData extends Data
{
    public function __construct(
        public int $plants,
        public int $buildings,
        public int $problems,
    ) {}
}
