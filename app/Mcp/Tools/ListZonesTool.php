<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesColony;
use App\Models\Colony;
use App\Models\Zone;
use App\Services\Mcp\McpHeader;
use App\Services\Zones\ZoneProblems;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_zones')]
#[Description('One line per active zone of the colony: id, name, world, cell count, box size and world origin, data age, problem count, captures. Zones are named areas the player marked in the game.')]
#[IsReadOnly]
final class ListZonesTool extends Tool
{
    use ResolvesColony;

    public function handle(Request $request): Response
    {
        $colony = $this->colony($request);

        if (! $colony instanceof Colony) {
            return Response::error($this->colonyMissing($request));
        }

        $zones = Zone::query()->active()->where('colony_guid', $colony->guid)
            ->select(['id', 'name', 'world_id', 'cell_count', 'orphaned', 'layout', 'fast_state', 'layout_at', 'slow_state_at', 'fast_state_at'])
            ->withCount('captures')
            ->orderBy('name')->orderBy('id')
            ->get();

        $lines = [McpHeader::line($colony), ''];
        foreach ($zones as $zone) {
            $lines[] = $this->line($zone);
        }
        if ($zones->isEmpty()) {
            $lines[] = 'No zones: mark one in the game with the Zone tool.';
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['colony' => $this->colonySchema($schema)];
    }

    private function line(Zone $zone): string
    {
        $box = $zone->layout['box'] ?? null;
        $problems = ZoneProblems::summary($zone);
        $latest = collect([$zone->layout_at, $zone->slow_state_at, $zone->fast_state_at])->filter()->max();

        return sprintf(
            '#%d %s · world %d · %d cells · %s · updated %s · problems %s · captures %d%s',
            $zone->id,
            $zone->name,
            $zone->world_id,
            $zone->cell_count,
            is_array($box) ? sprintf('box %dx%d @ w(%d,%d)', $box['width'] ?? 0, $box['height'] ?? 0, $box['x'] ?? 0, $box['y'] ?? 0) : 'layout unavailable',
            McpHeader::ago($latest),
            $problems['count'] === null ? 'unavailable' : $problems['count'].($problems['partial'] ? ' (partial)' : ''),
            $zone->captures_count,
            $zone->orphaned ? ' · orphaned' : '',
        );
    }
}
