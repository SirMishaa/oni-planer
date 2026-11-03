<?php

declare(strict_types=1);

namespace App\Enums;

/** The game's `PermittedRotations` enum (also shipped in the export's `attribute.json`). */
enum PermittedRotations: int
{
    case Unrotatable = 0;
    case R90 = 1;
    case R360 = 2;
    case FlipH = 3;
    case FlipV = 4;
}
