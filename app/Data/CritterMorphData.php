<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterMorphData extends Data
{
    public function __construct(
        public string $morph_id,
        public bool $is_base,
        public string $name,
        public float $min_temp,
        public float $max_temp,
        public float $calories_per_cycle,
        public float $incubation_time,
        public float $lifespan,
        public int $overcrowding_threshold,
        /** @var list<CritterDietData> */
        public array $diets,
        /** @var list<CritterOutputData> */
        public array $outputs,
    ) {}
}
