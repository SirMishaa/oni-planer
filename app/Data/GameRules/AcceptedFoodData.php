<?php

declare(strict_types=1);

namespace App\Data\GameRules;

use Spatie\LaravelData\Data;

/** One food a consumer accepts (one diet line), already multiplied by the number of consumers. */
final class AcceptedFoodData extends Data
{
    public function __construct(
        public string $resource,
        /** Kg per cycle when this food alone feeds every consumer. */
        public float $kgPerCycle,
        /** What eating it gives back (the dropping), when anything. */
        public ?string $produces,
        /** Kg per cycle of `produces` when this food alone is eaten: `kgPerCycle` × the diet conversion ratio. */
        public ?float $producedKgPerCycle,
    ) {}
}
