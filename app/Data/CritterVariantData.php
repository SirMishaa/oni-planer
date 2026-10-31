<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterVariantData extends Data
{
    public function __construct(
        public string $critter_id,
        public string $name,
        public ?string $image,
        public ?string $image_idle,
        public CritterMorphData $adult,
        public ?CritterMorphData $baby,
    ) {}
}
