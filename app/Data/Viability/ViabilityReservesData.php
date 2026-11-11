<?php

declare(strict_types=1);

namespace App\Data\Viability;

use Spatie\LaravelData\Data;

/** Food that is not counted in the scenarios but could be fetched. */
final class ViabilityReservesData extends Data
{
    public function __construct(
        /** @var list<ViabilitySourceData> */
        public array $wildManual,
        /** @var list<ViabilityCritterReserveData> */
        public array $critters,
        public ViabilityPrintingPodData $printingPod,
    ) {}
}
