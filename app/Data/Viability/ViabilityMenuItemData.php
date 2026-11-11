<?php

declare(strict_types=1);

namespace App\Data\Viability;

use Spatie\LaravelData\Data;

/** A stocked food, summed over every world. */
final class ViabilityMenuItemData extends Data
{
    public function __construct(
        public string $foodId,
        public string $name,
        public ?string $icon,
        public float $units,
        public float $kcal,
        /** @var list<string> names of the duplicants who may not eat it */
        public array $forbiddenFor,
    ) {}
}
