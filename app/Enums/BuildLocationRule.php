<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The game's `BuildLocationRule` enum (Assembly-CSharp, build U59-744825).
 * Value 19 is `OnBackWall` in the game; blueprintnotincluded calls it `Underwater`.
 */
enum BuildLocationRule: int
{
    case Anywhere = 0;
    case OnFloor = 1;
    case OnFloorOverSpace = 2;
    case OnCeiling = 3;
    case OnWall = 4;
    case InCorner = 5;
    case Tile = 6;
    case NotInTiles = 7;
    case Conduit = 8;
    case LogicBridge = 9;
    case WireBridge = 10;
    case HighWattBridgeTile = 11;
    case BuildingAttachPoint = 12;
    case OnFloorOrBuildingAttachPoint = 13;
    case OnFoundationRotatable = 14;
    case BelowRocketCeiling = 15;
    case OnRocketEnvelope = 16;
    case WallFloor = 17;
    case NoLiquidConduitAtOrigin = 18;
    case OnBackWall = 19;
}
