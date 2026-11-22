<?php

declare(strict_types=1);

namespace App\Actions\Zones;

use App\Enums\ZonePlanChangeKind;
use App\Enums\ZonePlanChangeState;
use App\Models\Zone;
use App\Models\ZonePlanChange;
use App\Services\Zones\Plans\PlanPlacement;
use App\Services\Zones\Plans\ZonePlanLayout;
use Illuminate\Support\Facades\DB;

/**
 * Brings a zone plan in line with the layout the game just sent: adds the game has built become
 * applied (and back to pending when their building disappears), checked adds that no longer fit
 * become conflicted (pending again once they fit), removes whose target is gone are dropped, and
 * a plan left without changes is deleted. Unchecked changes are left alone, and the changes the
 * player deleted are never read nor revived (a plan holding only those is kept for the assistant).
 */
final readonly class ReconcileZonePlan
{
    public function __construct(
        private PlanPlacement $placement,
        private ZonePlanLayout $planLayout,
    ) {}

    /** True when a change or the plan itself was updated or deleted. */
    public function handle(Zone $zone): bool
    {
        $layout = $zone->layout;
        $plan = $zone->plan()->first();
        if ($plan === null || ! is_array($layout)) {
            return false;
        }

        return DB::transaction(function () use ($zone, $layout, $plan): bool {
            $changed = false;
            $changes = $plan->changes()->get();
            $definitions = $this->planLayout->definitions($changes);

            $kept = collect();
            /** @var array<int, true> $builtBesideTarget replaces built while their target still stands */
            $builtBesideTarget = [];
            foreach ($changes as $change) {
                if (! $change->checked) {
                    $kept->push($change);

                    continue;
                }

                $targetStands = $change->removes() && $this->targetStands($layout, $change, $definitions);

                if ($change->kind === ZonePlanChangeKind::Remove) {
                    if ($targetStands) {
                        $kept->push($change);
                    } else {
                        $change->delete();
                        $changed = true;
                    }

                    continue;
                }

                $built = $this->isBuilt($layout, $change);
                if ($built && ! $targetStands) {
                    $change->state = ZonePlanChangeState::Applied;
                } elseif ($change->state === ZonePlanChangeState::Applied) {
                    $change->state = ZonePlanChangeState::Pending;
                }
                if ($built && $targetStands) {
                    $builtBesideTarget[$change->id] = true;
                }
                $kept->push($change);
            }

            foreach ($kept as $change) {
                if ($change->checked && $change->adds() && $change->state !== ZonePlanChangeState::Applied) {
                    $change->state = ! isset($builtBesideTarget[$change->id]) && $this->placement->problemAmong($zone, $kept, $change) !== null
                        ? ZonePlanChangeState::Conflicted
                        : ZonePlanChangeState::Pending;
                }
                if ($change->isDirty()) {
                    $change->save();
                    $changed = true;
                }
            }

            if ($kept->isEmpty() && ! $plan->deletedChanges()->exists()) {
                $plan->delete();
                $changed = true;
            }

            return $changed;
        });
    }

    /**
     * Whether the real layout holds the change's building: a building of the same prefab, anchor
     * cell and orientation, or a tile entry of the same prefab on the anchor cell (the game sends
     * 1x1 tiles, pressure doors and wide farm tiles as tiles, one per cell).
     *
     * @param  array<string, mixed>  $layout
     */
    private function isBuilt(array $layout, ZonePlanChange $change): bool
    {
        if ($change->prefab_id === null || $change->anchor_cell === null) {
            return false;
        }

        foreach ((array) ($layout['buildings'] ?? []) as $building) {
            if (($building['prefabId'] ?? null) === $change->prefab_id
                && (int) ($building['anchorCell'] ?? -1) === $change->anchor_cell
                && ($building['orientation'] ?? 'Neutral') === $change->orientation) {
                return true;
            }
        }

        foreach ((array) ($layout['tiles'] ?? []) as $tile) {
            if (($tile['cell'] ?? null) === $change->anchor_cell && ($tile['prefabId'] ?? null) === $change->prefab_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the building or tile a remove or replace targets is still in the real layout. A
     * replace that puts a tile on its target's cell does not count its own tile as the target.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, array{width: int, height: int, isTile: bool}>  $definitions
     */
    private function targetStands(array $layout, ZonePlanChange $change, array $definitions): bool
    {
        if ($change->target_instance_id !== null) {
            foreach ((array) ($layout['buildings'] ?? []) as $building) {
                if ((string) ($building['instanceId'] ?? '') === $change->target_instance_id) {
                    return true;
                }
            }

            return false;
        }

        if ($change->target_cell === null) {
            return false;
        }

        $ownTile = $change->adds() && $change->prefab_id !== null && $change->anchor_cell === $change->target_cell
            && ($definitions[$change->prefab_id]['isTile'] ?? false);
        foreach ((array) ($layout['tiles'] ?? []) as $tile) {
            if (($tile['cell'] ?? null) === $change->target_cell && ! ($ownTile && ($tile['prefabId'] ?? null) === $change->prefab_id)) {
                return true;
            }
        }

        return false;
    }
}
