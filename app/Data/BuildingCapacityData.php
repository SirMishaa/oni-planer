<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** The user-set capacity of a building: a mass limit for a storage, a critter count for a drop-off. */
final class BuildingCapacityData extends Data
{
    public function __construct(
        /** The limit the player set; null when never set. */
        public ?float $limit,
        /** The most the building can hold. */
        public ?float $max,
        /** What it holds now. */
        public ?float $stored,
        /** One of kg, critters. */
        #[LiteralTypeScriptType("'kg' | 'critters'")]
        public string $unit,
    ) {}
}
