<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesZone;
use App\Services\Mcp\McpHeader;
use App\Services\Mcp\ZoneMapRenderer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('zone_map')]
#[Description('Where things are in a zone: two aligned text grids, T (terrain: tiles and elements) and O (occupancy: buildings over their footprint, plants and critters in lowercase), one char per cell, rows labelled with their local y and a column ruler. The legend names every glyph and lists each building (id, world anchor, size, state), plant and critter. Boxes over 40x25 need a rect.')]
#[IsReadOnly]
final class ZoneMapTool extends Tool
{
    use ResolvesZone;

    private const array LAYERS = ['terrain', 'occupancy'];

    public function handle(Request $request, ZoneMapRenderer $renderer): Response
    {
        $request->validate([
            'layers' => ['nullable', 'array'],
            'layers.*' => ['string', 'in:'.implode(',', self::LAYERS)],
        ]);
        $rect = $this->rect($request);

        $resolved = $this->colonyAndZone($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$colony, $zone] = $resolved;

        $layers = $request->get('layers');

        return Response::text(McpHeader::line($colony, $zone)."\n\n".$renderer->render(
            $zone,
            $colony,
            $rect,
            is_array($layers) && $layers !== [] ? array_values($layers) : self::LAYERS,
        ));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'zone' => $this->zoneSchema($schema),
            'rect' => $this->rectSchema($schema, 'Part of the zone box to draw, world coordinates of its bottom-left cell plus size; required beyond 40x25.'),
            'layers' => $schema->array()->items($schema->string()->enum(self::LAYERS))->description('Grids to draw; default both.'),
            'colony' => $this->colonySchema($schema),
        ];
    }
}
