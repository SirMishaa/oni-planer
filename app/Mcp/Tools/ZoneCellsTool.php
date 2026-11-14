<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesZone;
use App\Services\Mcp\McpFormat;
use App\Services\Mcp\McpHeader;
use App\Services\Mcp\ZoneTerrainSummary;
use App\Services\Zones\ZoneCritters;
use App\Services\Zones\ZoneGeometry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('zone_cells')]
#[Description('Per-cell detail of a small rect of a zone (at most 100 cells): world position, element, mass, temperature, tile, building, and whether the cell is in the zone or on its border ring; the rect is clipped to the zone box.')]
#[IsReadOnly]
final class ZoneCellsTool extends Tool
{
    use ResolvesZone;

    public const int MAX_CELLS = 100;

    public function handle(Request $request): Response
    {
        $rect = $this->rect($request, required: true);
        if ($rect === null || $rect['width'] * $rect['height'] > self::MAX_CELLS) {
            return Response::error(sprintf('The rect covers more than %d cells: narrow it (zone_map shows the whole zone).', self::MAX_CELLS));
        }

        $resolved = $this->colonyAndZone($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$colony, $zone] = $resolved;

        $layout = $zone->layout;
        $gridWidth = (int) ($layout['gridWidth'] ?? 0);
        $box = ZoneGeometry::box($layout['box'] ?? null);
        if (! is_array($layout) || $gridWidth <= 0 || $box === null) {
            return Response::text(McpHeader::line($colony, $zone)."\n\nlayout unavailable");
        }

        $requested = $rect;
        $rect = ZoneGeometry::intersect($requested, $box);
        if ($rect === null) {
            return Response::text(sprintf(
                "%s\n\nThe rect %dx%d @ %s does not overlap the zone box %dx%d @ %s (world coordinates).",
                McpHeader::line($colony, $zone),
                $requested['width'],
                $requested['height'],
                McpFormat::world($requested['x'], $requested['y']),
                $box['width'],
                $box['height'],
                McpFormat::world($box['x'], $box['y']),
            ));
        }

        $tiles = [];
        foreach ((array) ($layout['tiles'] ?? []) as $tile) {
            if (is_array($tile) && is_int($tile['cell'] ?? null)) {
                $tiles[$tile['cell']] = (string) ($tile['prefabId'] ?? '?');
            }
        }
        $buildings = [];
        foreach ((array) ($layout['buildings'] ?? []) as $building) {
            if (is_array($building) && is_int($building['anchorCell'] ?? null)) {
                foreach (ZoneGeometry::footprint($building['anchorCell'], (int) ($building['width'] ?? 1), (int) ($building['height'] ?? 1), $gridWidth) as $cell) {
                    $buildings[$cell][] = sprintf('%s#%s', $building['prefabId'] ?? '?', $building['instanceId'] ?? '?');
                }
            }
        }
        $inZone = array_flip(ZoneCritters::cells($zone));

        $lines = [McpHeader::line($colony, $zone), ''];
        for ($y = $rect['y'] + $rect['height'] - 1; $y >= $rect['y']; $y--) {
            for ($x = $rect['x']; $x < $rect['x'] + $rect['width']; $x++) {
                $cell = ZoneGeometry::cell($x, $y, $gridWidth);
                $reading = is_array($zone->slow_state) ? ZoneTerrainSummary::cellAt($zone->slow_state, $x, $y) : null;
                $lines[] = implode(' ', array_filter([
                    McpFormat::world($x, $y),
                    $reading === null ? 'element unavailable' : sprintf('%s %s %s', $reading['elementId'], McpFormat::kg($reading['mass']), McpFormat::celsius($reading['celsius'])),
                    isset($tiles[$cell]) ? 'tile '.$tiles[$cell] : null,
                    isset($buildings[$cell]) ? implode(' ', $buildings[$cell]) : null,
                    match (true) {
                        isset($inZone[$cell]) => 'zone',
                        default => 'border',
                    },
                ]));
            }
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'zone' => $this->zoneSchema($schema),
            'rect' => $this->rectSchema($schema, 'Cells to detail, world coordinates of the bottom-left cell plus size; at most 100 cells.')->required(),
            'colony' => $this->colonySchema($schema),
        ];
    }
}
