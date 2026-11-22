<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesZone;
use App\Models\Zone;
use App\Models\ZonePlan;
use App\Models\ZonePlanChange;
use App\Services\Mcp\McpFormat;
use App\Services\Mcp\McpHeader;
use App\Services\Mcp\ZoneMapRenderer;
use App\Services\Zones\Plans\ZonePlanLayout;
use App\Services\Zones\ZoneGeometry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('zone_map')]
#[Description('Where things are in a zone: two aligned text grids, T (terrain: tiles and elements) and O (occupancy: buildings over their footprint, plants and critters in lowercase), one char per cell, rows labelled with their local y and a column ruler. The legend names every glyph and lists each building (id, world anchor, size, state), plant and critter. Boxes over 40x25 need a rect. With plan: true it draws the zone as its plan would leave it: planned buildings listed as plan#id with their state and note, removed targets listed apart.')]
#[IsReadOnly]
final class ZoneMapTool extends Tool
{
    use ResolvesZone;

    private const array LAYERS = ['terrain', 'occupancy'];

    public function handle(Request $request, ZoneMapRenderer $renderer, ZonePlanLayout $planLayout): Response
    {
        $request->validate([
            'layers' => ['nullable', 'array'],
            'layers.*' => ['string', 'in:'.implode(',', self::LAYERS)],
            'plan' => ['nullable', 'boolean'],
        ]);
        $rect = $this->rect($request);

        $resolved = $this->colonyAndZone($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$colony, $zone] = $resolved;

        $layers = $request->get('layers');
        $layers = is_array($layers) && $layers !== [] ? array_values($layers) : self::LAYERS;

        if (! (bool) $request->get('plan') || ! is_array($zone->layout)) {
            return Response::text(McpHeader::line($colony, $zone)."\n\n".$renderer->render($zone, $colony, $rect, $layers));
        }

        $plan = $zone->plan()->first();
        if (! $plan instanceof ZonePlan) {
            return Response::text(McpHeader::line($colony, $zone)."\n\n".sprintf('No plan for zone #%d: drawing the current layout.', $zone->id)."\n".$renderer->render($zone, $colony, $rect, $layers));
        }

        $changes = $plan->changes()->get()->keyBy('id');
        $definitions = $planLayout->definitions($changes);

        return Response::text(implode("\n", [
            McpHeader::line($colony, $zone),
            '',
            sprintf('Planned layout of zone #%d: the zone as its plan would leave it (zone_plan lists the changes).', $zone->id),
            $renderer->render($zone, $colony, $rect, $layers, $this->plannedLayout($zone, $changes, $definitions, $planLayout)),
            ...$this->planNotes($zone, $changes, $definitions),
        ]));
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
            'plan' => $schema->boolean()->description('Draw the zone as its plan would leave it; default false.'),
            'colony' => $this->colonySchema($schema),
        ];
    }

    /**
     * The real layout with the plan applied, planned buildings carrying their change state, whether
     * they are checked and their note.
     *
     * @param  Collection<int, ZonePlanChange>  $changes  keyed by id
     * @param  array<string, array{width: int, height: int, isTile: bool}>  $definitions
     * @return array<string, mixed>
     */
    private function plannedLayout(Zone $zone, Collection $changes, array $definitions, ZonePlanLayout $planLayout): array
    {
        $layout = $planLayout->apply((array) $zone->layout, $changes, $definitions);

        foreach ($layout['buildings'] as $index => $building) {
            $instanceId = (string) ($building['instanceId'] ?? '');
            $change = str_starts_with($instanceId, 'plan:') ? $changes->get((int) mb_substr($instanceId, 5)) : null;
            if ($change !== null) {
                $layout['buildings'][$index]['planState'] = $change->state->value;
                $layout['buildings'][$index]['planChecked'] = $change->checked;
                $layout['buildings'][$index]['planNote'] = $change->note;
            }
        }

        return $layout;
    }

    /**
     * What the grids cannot show: the buildings and tiles the plan takes out, and the tiles it lays.
     *
     * @param  Collection<int, ZonePlanChange>  $changes
     * @param  array<string, array{width: int, height: int, isTile: bool}>  $definitions
     * @return list<string>
     */
    private function planNotes(Zone $zone, Collection $changes, array $definitions): array
    {
        $layout = (array) $zone->layout;
        $gridWidth = (int) ($layout['gridWidth'] ?? 0);
        $buildings = collect((array) ($layout['buildings'] ?? []))->keyBy(fn (array $building): string => (string) ($building['instanceId'] ?? ''));
        $tiles = collect((array) ($layout['tiles'] ?? []))->keyBy(fn (array $tile): int => (int) ($tile['cell'] ?? -1));

        $removed = [];
        $laid = [];
        foreach ($changes as $change) {
            $label = sprintf('plan#%d %s "%s"', $change->id, $change->state->value, $change->note);
            if ($change->removes() && $change->target_instance_id !== null && ($building = $buildings->get($change->target_instance_id)) !== null) {
                [$x, $y] = ZoneGeometry::xy((int) ($building['anchorCell'] ?? 0), $gridWidth);
                $removed[] = sprintf(' #%s %s %s (%s)', $change->target_instance_id, $building['prefabId'] ?? '?', McpFormat::world($x, $y), $label);
            } elseif ($change->removes() && $change->target_cell !== null && ($tile = $tiles->get($change->target_cell)) !== null) {
                [$x, $y] = ZoneGeometry::xy($change->target_cell, $gridWidth);
                $removed[] = sprintf(' tile %s %s (%s)', $tile['prefabId'] ?? '?', McpFormat::world($x, $y), $label);
            }

            if ($change->adds() && $change->anchor_cell !== null && ($definitions[(string) $change->prefab_id]['isTile'] ?? false)) {
                [$x, $y] = ZoneGeometry::xy($change->anchor_cell, $gridWidth);
                $laid[] = sprintf(' tile %s %s (%s)', $change->prefab_id, McpFormat::world($x, $y), $label);
            }
        }

        return [
            ...($removed === [] ? [] : ['Removed by the plan:', ...$removed]),
            ...($laid === [] ? [] : ['Tiles laid by the plan:', ...$laid]),
        ];
    }
}
