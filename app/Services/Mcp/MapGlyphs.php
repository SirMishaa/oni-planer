<?php

declare(strict_types=1);

namespace App\Services\Mcp;

/**
 * The glyphs of zone_map. Common elements, tiles and buildings have a fixed glyph, the same
 * in every zone and every call, so the assistant learns them; anything else gets a glyph
 * from a pool, assigned per zone (the same in every rect of that zone, not across zones)
 * and always named in its legend.
 */
final class MapGlyphs
{
    /** @var array<string, string> tile prefab => terrain glyph */
    public const array TILES = [
        'Tile' => '#',
        'MeshTile' => '=',
        'GasPermeableMembrane' => '"',
    ];

    /** Glyphs for the other tile prefabs, in order of appearance. */
    public const string TILE_POOL = '+&$@!^';

    /** @var array<string, string> gas or liquid element => terrain glyph */
    public const array FLUIDS = [
        'Oxygen' => '.',
        'ContaminatedOxygen' => ',',
        'CarbonDioxide' => '_',
        'Hydrogen' => 'h',
        'ChlorineGas' => 'c',
        'Methane' => 'm',
        'Steam' => 'v',
        'Vacuum' => "'",
        'Water' => '~',
        'DirtyWater' => '%',
        'SaltWater' => 's',
        'Brine' => 'b',
        'CrudeOil' => 'o',
        'Petroleum' => 'p',
    ];

    public const string OTHER_GAS = 'g';

    public const string OTHER_LIQUID = 'l';

    /** @var array<string, string> solid element => terrain glyph */
    public const array SOLIDS = [
        'Dirt' => 'D',
        'Sand' => 'S',
        'SandStone' => 'R',
        'Granite' => 'G',
        'IgneousRock' => 'I',
        'Obsidian' => 'O',
        'SedimentaryRock' => 'K',
        'SiltStone' => 'T',
        'Algae' => 'A',
        'Clay' => 'Y',
        'Ice' => 'E',
        'Salt' => 'N',
        'Sulfur' => 'U',
        'IronOre' => 'F',
        'Unobtanium' => 'Z',
    ];

    /** Glyphs for the other solids, in order of appearance; then {@see self::UNKNOWN}. */
    public const string SOLID_POOL = '123456789';

    /** A cell whose element is not known (no slow state there, or an element missing from the game data). */
    public const string UNKNOWN = '?';

    /** A gas or liquid cell on the border ring: inside the box, outside the zone. */
    public const string BORDER = ':';

    public const string EMPTY = '.';

    /** @var array<string, string> building prefab => occupancy glyph */
    public const array BUILDINGS = [
        'Ladder' => 'H',
        'Door' => 'D',
        'Stairs' => 'Z',
        'Bed' => 'B',
        'Shower' => 'W',
        'FlushToilet' => 'T',
        'WashSink' => 'K',
        'StorageLocker' => 'L',
        'FarmStation' => 'F',
        'RanchStation' => 'R',
        'CreatureFeeder' => 'C',
        'CritterDropOff' => 'Q',
        'DiningTable' => 'E',
        'CrownMoulding' => 'M',
        'CornerMoulding' => 'N',
    ];

    /** Glyphs for the other building prefabs: free uppercase letters, then digits. */
    public const string BUILDING_POOL = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /** Glyphs for critters and plants: their lowercase initial, else the next free one. */
    public const string ENTITY_POOL = 'abcdefghijklmnopqrstuvwxyz';

    /** Last resort when a pool is exhausted. */
    public const string OVERFLOW = '*';
}
