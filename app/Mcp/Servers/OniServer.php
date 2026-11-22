<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\ColonyStatusTool;
use App\Mcp\Tools\EntityTool;
use App\Mcp\Tools\ListZonesTool;
use App\Mcp\Tools\LookupTool;
use App\Mcp\Tools\RequestCaptureTool;
use App\Mcp\Tools\WriteZonePlanTool;
use App\Mcp\Tools\ZoneCapturesTool;
use App\Mcp\Tools\ZoneCellsTool;
use App\Mcp\Tools\ZoneMapTool;
use App\Mcp\Tools\ZoneOverviewTool;
use App\Mcp\Tools\ZonePlanTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('oni')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    Explore the Oxygen Not Included colony synced by the Uplink mod, and the imported game data.

    Flow: colony_status (sync health, colony summary) → list_zones → zone_overview (one zone in
    one screen) → zone_map (where things are) → zone_cells / entity / lookup (detail) →
    zone_captures (the game's own picture of the zone; request_capture asks for a new one).

    Plans: zone_plan reads the variant proposed for a zone (zone_map plan: true draws it);
    write_zone_plan replaces it with your changes (add, remove, replace, each with a note), checked
    against the layout and refused as a whole on any problem. The player can move adds in the
    planner; the game building them marks them applied.

    Every response starts with a header: colony, cycle, age of the data. STALE means the game
    stopped sending: do not advise as if the state were current.

    Coordinates: world cells w(x,y), x = cell % gridWidth, y = cell div gridWidth, y grows
    upward. Maps print local coordinates (0 = bottom-left of the zone box) with the world origin
    in the header; every list uses world coordinates.

    Units: kg, °C, seconds; a cycle is 600 s.

    zone_map prints two aligned grids: T (terrain: elements and tiles) and O (occupancy:
    buildings over their whole footprint, critters and plants in lowercase). Common tiles,
    elements and buildings have fixed glyphs; the others get a glyph per zone, stable across
    rects of that zone but not between zones. The legend lists only the glyphs present, with
    one line per building prefab and its instances.

    "derived" marks a value the planner computed rather than read from the game (the removal
    count of a cramped region, feeding stock and autonomy, and critters matched to a zone by
    their cell with an older mod). "unavailable" means the game did not send it.
    MARKDOWN)]
final class OniServer extends Server
{
    protected array $tools = [
        ColonyStatusTool::class,
        ListZonesTool::class,
        ZoneOverviewTool::class,
        ZoneMapTool::class,
        ZoneCellsTool::class,
        EntityTool::class,
        LookupTool::class,
        ZoneCapturesTool::class,
        RequestCaptureTool::class,
        ZonePlanTool::class,
        WriteZonePlanTool::class,
    ];
}
