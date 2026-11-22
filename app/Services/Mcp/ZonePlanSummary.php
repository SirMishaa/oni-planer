<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Models\Zone;
use App\Models\ZonePlan;
use App\Models\ZonePlanChange;
use App\Services\Zones\ZoneGeometry;

/**
 * The text of a zone plan for the assistant: a title line, then one line per change in order
 * (`#id kind prefab @ w(x,y) orientation · state · checked · "note"`, the target for removes
 * and replaces), world coordinates throughout. The changes the player deleted follow apart,
 * marked "deleted by player".
 */
final class ZonePlanSummary
{
    /** @return list<string> */
    public function lines(Zone $zone, ?ZonePlan $plan): array
    {
        $changes = $plan?->changes()->get();
        $deleted = $plan?->deletedChanges()->get();
        if ($plan === null || $changes === null || $deleted === null || ($changes->isEmpty() && $deleted->isEmpty())) {
            return [sprintf('No plan for zone #%d "%s".', $zone->id, $zone->name)];
        }

        $layout = is_array($zone->layout) ? $zone->layout : [];
        $gridWidth = (int) ($layout['gridWidth'] ?? 0);
        $lines = [sprintf('Plan of zone #%d "%s" · %d %s · updated %s', $zone->id, $zone->name, $changes->count(), $changes->count() === 1 ? 'change' : 'changes', McpHeader::ago($plan->updated_at))];

        foreach ($changes as $change) {
            $lines[] = $this->line($change, $layout, $gridWidth);
        }

        if ($deleted->isNotEmpty()) {
            $lines[] = 'Deleted by the player (left out of the plan; write_zone_plan replaces them along with the rest):';
            foreach ($deleted as $change) {
                $lines[] = $this->line($change, $layout, $gridWidth);
            }
        }

        $lines[] = 'unchecked = a layer the game does not send (wire, pipe, logic…): drawn, never validated nor applied.';

        return $lines;
    }

    /**
     * One change: `#12 add ManualGenerator @ w(116,201) Neutral · pending · checked · "note"`.
     *
     * @param  array<string, mixed>  $layout
     */
    public function line(ZonePlanChange $change, array $layout, int $gridWidth): string
    {
        $parts = [];
        if ($change->adds() && $change->prefab_id !== null && $change->anchor_cell !== null && $gridWidth > 0) {
            [$x, $y] = ZoneGeometry::xy($change->anchor_cell, $gridWidth);
            $parts[] = sprintf('%s @ %s %s', $change->prefab_id, McpFormat::world($x, $y), $change->orientation);
        }
        if ($change->removes()) {
            $parts[] = 'target '.$this->target($change, $layout, $gridWidth);
        }

        return sprintf(
            '#%d %s %s · %s · %s%s · "%s"',
            $change->id,
            $change->kind->value,
            implode(', ', $parts),
            $change->deleted_by_player ? 'deleted by player' : $change->state->value,
            $change->checked ? 'checked' : 'unchecked',
            $change->moved_by_player ? ' · moved by player' : '',
            $change->note,
        );
    }

    /**
     * What a remove or replace takes out: `#7001 FarmStation` for a building, `w(100,200) FarmTile`
     * for a tile; the bare id or cell when the real layout no longer holds it.
     *
     * @param  array<string, mixed>  $layout
     */
    public function target(ZonePlanChange $change, array $layout, int $gridWidth): string
    {
        if ($change->target_instance_id !== null) {
            foreach ((array) ($layout['buildings'] ?? []) as $building) {
                if ((string) ($building['instanceId'] ?? '') === $change->target_instance_id) {
                    return sprintf('#%s %s', $change->target_instance_id, $building['prefabId'] ?? '?');
                }
            }

            return '#'.$change->target_instance_id;
        }

        if ($change->target_cell === null || $gridWidth <= 0) {
            return 'none';
        }

        [$x, $y] = ZoneGeometry::xy($change->target_cell, $gridWidth);
        foreach ((array) ($layout['tiles'] ?? []) as $tile) {
            if (($tile['cell'] ?? null) === $change->target_cell) {
                return sprintf('%s %s', McpFormat::world($x, $y), $tile['prefabId'] ?? '?');
            }
        }

        return McpFormat::world($x, $y);
    }
}
