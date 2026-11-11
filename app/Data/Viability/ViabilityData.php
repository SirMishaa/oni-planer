<?php

declare(strict_types=1);

namespace App\Data\Viability;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * How long the colony can feed its duplicants, with display names and icons.
 * Kcal are floats; cycles are absolute decimal cycles.
 */
final class ViabilityData extends Data
{
    public function __construct(
        public float $nowCycle,
        #[LiteralTypeScriptType("'critical' | 'tight' | 'holds' | 'unknown'")]
        /** @var 'critical'|'tight'|'holds'|'unknown' unknown when the duplicants or the food could not be read */
        public string $verdict,
        public float $consumptionKcalPerCycle,
        public float $stockKcal,
        public ViabilityScenariosData $scenarios,
        /** @var list<ViabilitySourceData> */
        public array $sources,
        /** @var list<ViabilityHarvestData> */
        public array $harvests,
        public float $lastComputedCycle,
        /** @var list<ViabilityMenuItemData> */
        public array $menu,
        /** @var list<ViabilityEaterData> */
        public array $eaters,
        /** @var list<ViabilityNonEaterData> */
        public array $nonEaters,
        public ViabilityReservesData $reserves,
        /** @var list<string> sections the game reported as unreadable */
        public array $unavailable,
        /** @var list<string> crop ids with no known calories */
        public array $unknownCrops,
        public bool $autoHarvestKnown,
    ) {}
}
