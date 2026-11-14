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

    /**
     * A box (world x, y of its bottom-left cell, width, height) read from the mod's payload,
     * or null when a key is missing or not a number.
     *
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    public static function box(mixed $box): ?array
    {
        if (! is_array($box)) {
            return null;
        }

        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (! is_numeric($box[$key] ?? null)) {
                return null;
            }
        }

        return ['x' => (int) $box['x'], 'y' => (int) $box['y'], 'width' => (int) $box['width'], 'height' => (int) $box['height']];
    }

    /**
     * The part of a rect inside a box, or null when they do not overlap.
     *
     * @param  array{x: int, y: int, width: int, height: int}  $rect
     * @param  array{x: int, y: int, width: int, height: int}  $box
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    public static function intersect(array $rect, array $box): ?array
    {
        $left = max($rect['x'], $box['x']);
        $bottom = max($rect['y'], $box['y']);
        $right = min($rect['x'] + $rect['width'], $box['x'] + $box['width']);
        $top = min($rect['y'] + $rect['height'], $box['y'] + $box['height']);

        return $right > $left && $top > $bottom ? ['x' => $left, 'y' => $bottom, 'width' => $right - $left, 'height' => $top - $bottom] : null;
    }
}
