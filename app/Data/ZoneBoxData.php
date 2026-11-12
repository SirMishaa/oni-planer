<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** A rectangle of cells, from its bottom-left cell (game coordinates, y up). */
final class ZoneBoxData extends Data
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {}
}
