<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class ElementShowData extends Data
{
    public function __construct(
        public string $element_id,
        public string $name,
        public ?string $description,
        public string $state,
        public string $material_category,
        public float $molar_mass,
        public int $toxicity,
        /** @var list<string> */
        public array $tags,
        public ?string $low_temp_transition_target,
        public ?string $high_temp_transition_target,
        public ?ThermalPropertyData $thermal,
        public ?ModData $mod = null,
    ) {}
}
