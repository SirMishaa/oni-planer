<?php

declare(strict_types=1);

namespace App\Data\Viability;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** A group of plants of the same species, crop and harvest kind. */
final class ViabilitySourceData extends Data
{
    public function __construct(
        public string $key,
        public string $plantId,
        public string $plantName,
        public ?string $plantIcon,
        public string $cropId,
        #[LiteralTypeScriptType("'planted' | 'wild_auto' | 'wild_manual'")]
        /** @var 'planted'|'wild_auto'|'wild_manual' */
        public string $kind,
        public int $count,
        /** Plants of the group that are not growing (wilting, stifled...). */
        public int $stopped,
        public float $kcalPerCycle,
        /** Every duplicant is forbidden its crop: its harvests feed nobody. */
        public bool $forbiddenToAll,
    ) {}
}
