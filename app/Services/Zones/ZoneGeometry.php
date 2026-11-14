<?php

declare(strict_types=1);

namespace App\Services\Zones;

/**
 * Cell arithmetic of the game grid: cell = y * gridWidth + x, y grows upward. A building's
 * layout anchor is its bottom-left cell; its footprint fills width × height upward from it.
 */
final class ZoneGeometry
{
    /**
     * The cells a building covers, row by row from the bottom.
     *
     * @return list<int>
     */
    public static function footprint(int $anchorCell, int $width, int $height, int $gridWidth): array
    {
        $cells = [];
        for ($dy = 0; $dy < $height; $dy++) {
            for ($dx = 0; $dx < $width; $dx++) {
                $cells[] = $anchorCell + $dx + $dy * $gridWidth;
            }
        }

        return $cells;
    }

    /** @return array{0: int, 1: int} world x and y of a cell */
    public static function xy(int $cell, int $gridWidth): array
    {
        return [$cell % $gridWidth, intdiv($cell, $gridWidth)];
    }

    public static function cell(int $x, int $y, int $gridWidth): int
    {
        return $y * $gridWidth + $x;
    }
}
