<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class ElementShowData extends Data
{
    public function __construct(
        public string $element_id,
        /** @var array<string, string> */
        public array $name,
        /** @var array<string, string>|null */
        public ?array $description,
        public string $state,
        public string $material_category,
        public float $molar_mass,
        public int $toxicity,
        /** @var list<string> */
        public array $tags,
        public ?string $low_temp_transition_target,
        public ?string $high_temp_transition_target,
        public ?ThermalPropertyData $thermal,
    ) {}
}
