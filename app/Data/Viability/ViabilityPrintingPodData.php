<?php

declare(strict_types=1);

namespace App\Data\Viability;

use Spatie\LaravelData\Data;

final class ViabilityPrintingPodData extends Data
{
    public function __construct(
        public ?float $cyclesUntilNextPrint,
    ) {}
}
