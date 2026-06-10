<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterVariantData extends Data
{
    public function __construct(
        public string $critter_id,
        /** @var array<string, string> */
        public array $name,
        public ?string $image,
        public CritterMorphData $adult,
        public ?CritterMorphData $baby,
    ) {}
}
