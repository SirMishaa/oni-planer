<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesZone;
use App\Services\Mcp\McpHeader;
use App\Services\Mcp\ZonePlanSummary;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('zone_plan')]
#[Description('The plan of a zone (the variant proposed with write_zone_plan, as the player may have adjusted it): one line per change with its id, kind, prefab, world anchor, orientation, state (pending, applied once the game has built it, conflicted when its spot is taken), whether it is checked, whether the player moved it, the target of removes and replaces, and its note. zone_map with plan: true draws it.')]
#[IsReadOnly]
final class ZonePlanTool extends Tool
{
    use ResolvesZone;

    public function handle(Request $request, ZonePlanSummary $summary): Response
    {
        $resolved = $this->colonyAndZone($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$colony, $zone] = $resolved;

        return Response::text(implode("\n", [McpHeader::line($colony, $zone), '', ...$summary->lines($zone, $zone->plan()->first())]));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['zone' => $this->zoneSchema($schema), 'colony' => $this->colonySchema($schema)];
    }
}
