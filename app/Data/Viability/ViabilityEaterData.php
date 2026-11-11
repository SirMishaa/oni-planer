<?php

declare(strict_types=1);

namespace App\Data\Viability;

use Spatie\LaravelData\Data;

/** A duplicant that eats, with its calorie meter and its food permissions. */
final class ViabilityEaterData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?float $kcal,
        public ?float $maxKcal,
        public float $needKcal,
        /** @var list<string> stocked food ids it may eat */
        public array $allowed,
        /** @var list<string> stocked food ids it may not eat */
        public array $forbidden,
    ) {}
}
