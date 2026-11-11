<?php

declare(strict_types=1);

namespace App\Data\Viability;

use Spatie\LaravelData\Data;

/** A duplicant that does not eat (bionic...). */
final class ViabilityNonEaterData extends Data
{
    public function __construct(
        public string $name,
        public ?string $model,
    ) {}
}
