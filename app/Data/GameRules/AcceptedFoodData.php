<?php

declare(strict_types=1);

namespace App\Data\GameRules;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** One food a consumer accepts (one diet line), already multiplied by the number of consumers. */
final class AcceptedFoodData extends Data
{
    /**
     * @param  'item'|'plant'|'prey'  $kind
     */
    public function __construct(
        public string $resource,
        /**
         * Amount per cycle when this food alone feeds every consumer. Kg for an `item`; for a `plant` it is plant
         * growth eaten and for `prey` the game's own amount, neither of which is kg.
         */
        public float $kgPerCycle,
        /** What eating it gives back (the dropping), when anything. */
        public ?string $produces,
        /** Kg per cycle of `produces` when this food alone is eaten: `kgPerCycle` × the diet conversion ratio. */
        public ?float $producedKgPerCycle,
        /** How it is eaten: an `item` picked up (stockable), a `plant` eaten in place, or `prey`. */
        #[LiteralTypeScriptType("'item' | 'plant' | 'prey'")]
        public string $kind = 'item',
    ) {}
}
