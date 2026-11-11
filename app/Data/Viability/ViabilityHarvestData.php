<?php

declare(strict_types=1);

namespace App\Data\Viability;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** One upcoming harvest of a source, at an absolute decimal cycle. */
final class ViabilityHarvestData extends Data
{
    public function __construct(
        public string $sourceKey,
        #[LiteralTypeScriptType("'planted' | 'wild_auto' | 'wild_manual'")]
        /** @var 'planted'|'wild_auto'|'wild_manual' */
        public string $kind,
        public float $cycle,
        public float $kcal,
    ) {}
}
