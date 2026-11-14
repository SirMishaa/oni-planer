<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** Where a render sits, in cells from the footprint's bottom-left corner (y grows upwards, as in the game). */
final class ZoneSpriteRectData extends Data
{
    public function __construct(
        public float $x,
        public float $y,
        public float $w,
        public float $h,
    ) {}
}
