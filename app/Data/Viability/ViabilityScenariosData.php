<?php

declare(strict_types=1);

namespace App\Data\Viability;

use Spatie\LaravelData\Data;

/** Projections without harvests, with automatic harvests only, and with every harvest. */
final class ViabilityScenariosData extends Data
{
    public function __construct(
        public ViabilityScenarioData $none,
        public ViabilityScenarioData $automatic,
        public ViabilityScenarioData $all,
    ) {}
}
