<?php

declare(strict_types=1);

namespace App\Services\Zones\Plans;

use App\Services\Zones\ZoneGeometry;

/**
 * Footprint of a planned building once its orientation is applied.
 */
final class PlanFootprint
{
    /**
     * Width and height of a building; a quarter turn swaps them.
     *
     * @return array{0: int, 1: int}
     */
    public static function size(int $width, int $height, string $orientation): array
    {
        return in_array($orientation, ['R90', 'R270'], true) ? [$height, $width] : [$width, $height];
    }

    /**
     * The cells a footprint covers.
     *
     * @return list<int>
     */
    public static function cells(int $anchorCell, int $width, int $height, int $gridWidth): array
    {
        return ZoneGeometry::footprint($anchorCell, $width, $height, $gridWidth);
    }
}
