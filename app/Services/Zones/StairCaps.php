<?php

declare(strict_types=1);

namespace App\Services\Zones;

use Closure;

/**
 * The end caps the Stairs mod (faqy003/OniMod-Stairs, AnimStairs.UpdateEndCaps) shows on a
 * stair from its neighbours, as the bitmask OniExtract names its renders after
 * (`stair_caps/{prefab}/{mask}.png`, OniExtract2024.Core StairCaps). A literal port: the
 * mod's flag locals become hypotenuse, below, coveredAbove and stairUpAhead.
 */
final class StairCaps
{
    /** cap_left */
    public const int LEFT = 1;

    /** cap_right and joint */
    public const int RIGHT = 2;

    /** cap_right_half */
    public const int RIGHT_HALF = 4;

    /** cap_top */
    public const int TOP = 8;

    /** cap_top_half */
    public const int TOP_HALF = 16;

    /**
     * The mask of the stair on `$cell`. `$facingLeft` is a flipped stair (orientation FlipH), which
     * mirrors every side the mod looks at. `$occupant` tells what stands on a cell's building
     * layer: a stair (and which way it faces), or a building that makes its cell solid (a
     * SimCellOccupier); null for nothing.
     *
     * @param  Closure(int): (array{stair: bool, facingLeft: bool, solid: bool}|null)  $occupant
     */
    public static function mask(int $cell, bool $facingLeft, int $gridWidth, Closure $occupant): int
    {
        $above = $cell + $gridWidth;
        $below = $cell - $gridWidth;
        $tileable = function (int $neighbour, bool $countSolid = true) use ($occupant): bool {
            $found = $occupant($neighbour);

            return $found !== null && ($found['stair'] || ($countSolid && $found['solid']));
        };

        $coveredAbove = $tileable($above);
        $hypotenuse = ! $coveredAbove && ! $tileable($facingLeft ? $cell + 1 : $cell - 1);

        $stairUpAhead = false;
        if ($hypotenuse) {
            $diagonal = $occupant($facingLeft ? $above - 1 : $above + 1);
            $stairUpAhead = $diagonal !== null && $diagonal['stair'] && $diagonal['facingLeft'] === $facingLeft;
        }

        $standsOnSomething = $coveredAbove || ! $hypotenuse || $tileable($below, countSolid: false);
        if (! $standsOnSomething && $hypotenuse && ($occupant($below)['solid'] ?? false)) {
            $standsOnSomething = $tileable($facingLeft ? $cell - 1 : $cell + 1, countSolid: false);
        }

        $mask = 0;
        if ($hypotenuse) {
            $mask |= self::LEFT;
        }

        if ($standsOnSomething) {
            $mask |= $hypotenuse ? self::RIGHT_HALF : self::RIGHT;
        }

        if (! $coveredAbove && ! $stairUpAhead) {
            $mask |= $hypotenuse ? self::TOP_HALF : self::TOP;
        }

        return $mask;
    }
}
