<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Zones\ReconcileZonePlan;
use App\Enums\ZonePlanChangeKind;
use App\Enums\ZonePlanChangeState;
use App\Events\ZoneUpdated;
use App\Http\Requests\MoveZonePlanChangeRequest;
use App\Models\Zone;
use App\Models\ZonePlan;
use App\Models\ZonePlanChange;
use App\Services\Zones\Plans\PlanPlacement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Lets the player move or delete one change of a zone plan. */
final readonly class ZonePlanChangeController
{
    public function __construct(
        private PlanPlacement $placement,
        private ReconcileZonePlan $reconcile,
    ) {}

    /**
     * Moves a planned add the game has not built; nothing changes when it, or a change that relied
     * on its old spot, no longer stands.
     */
    public function update(MoveZonePlanChangeRequest $request, Zone $zone, ZonePlanChange $change): RedirectResponse
    {
        $plan = $this->plan($zone, $change);
        if ($change->kind !== ZonePlanChangeKind::Add || $change->anchor_cell === null) {
            throw ValidationException::withMessages(['position' => 'Only a planned add can be moved.']);
        }
        if ($change->state === ZonePlanChangeState::Applied) {
            throw ValidationException::withMessages(['position' => 'The game has built this add: it can no longer be moved.']);
        }

        $layout = is_array($zone->layout) ? $zone->layout : [];
        $gridWidth = (int) ($layout['gridWidth'] ?? 0);
        $box = is_array($layout['box'] ?? null) ? $layout['box'] : null;
        $x = $request->integer('x');
        $y = $request->integer('y');

        if ($gridWidth <= 0) {
            throw ValidationException::withMessages(['position' => 'The layout of this zone has not arrived from the game yet: nothing to move against.']);
        }
        if (! $this->insideBox($box, $x, $y)) {
            throw ValidationException::withMessages(['position' => "({$x}, {$y}) is outside the zone box."]);
        }

        DB::transaction(function () use ($zone, $plan, $change, $x, $y, $gridWidth): void {
            $unharmed = $plan->changes()->get()
                ->reject(fn (ZonePlanChange $other): bool => $other->id === $change->id)
                ->filter(fn (ZonePlanChange $other): bool => $this->placement->problem($zone, $plan, $other) === null);

            $change->forceFill([
                'anchor_cell' => $y * $gridWidth + $x,
                'moved_by_player' => true,
            ])->save();

            $problem = $this->placement->problem($zone, $plan, $change);
            foreach ($unharmed as $other) {
                $problem ??= $this->placement->problem($zone, $plan, $other);
            }

            if ($problem !== null) {
                throw ValidationException::withMessages(['position' => $problem]);
            }
        });

        $this->reconcile->handle($zone);
        ZoneUpdated::dispatch($zone->zone_guid, $zone->colony_guid, 'plan');

        return back();
    }

    /**
     * Deletes a change from the plan, unless another planned add stands only thanks to it. The
     * change stays stored as deleted by the player, so the assistant sees it; the plan is kept.
     */
    public function destroy(Zone $zone, ZonePlanChange $change): RedirectResponse
    {
        $plan = $this->plan($zone, $change);

        $dependents = $this->placement->dependents($zone, $plan, $change);
        if ($dependents !== []) {
            throw ValidationException::withMessages([
                'change' => 'Other changes rely on this one: '.collect($dependents)->map(fn (ZonePlanChange $dependent): string => (string) $dependent->prefab_id)->implode(', ').'.',
            ]);
        }

        $change->forceFill(['deleted_by_player' => true])->save();

        ZoneUpdated::dispatch($zone->zone_guid, $zone->colony_guid, 'plan');

        return back();
    }

    /** The plan of the zone the change belongs to; 404 for a change of another zone, a gone zone or a deleted change. */
    private function plan(Zone $zone, ZonePlanChange $change): ZonePlan
    {
        abort_if($zone->archived_at !== null || $zone->transient || $change->deleted_by_player, 404);
        $plan = $change->plan;
        abort_unless($plan->zone_id === $zone->id, 404);

        return $plan;
    }

    /**
     * Whether a world cell lies in the zone box; a zone without a box accepts any cell.
     *
     * @param  array<string, mixed>|null  $box
     */
    private function insideBox(?array $box, int $x, int $y): bool
    {
        if ($box === null) {
            return true;
        }

        $left = (int) ($box['x'] ?? 0);
        $bottom = (int) ($box['y'] ?? 0);

        return $x >= $left && $y >= $bottom
            && $x < $left + (int) ($box['width'] ?? 0) && $y < $bottom + (int) ($box['height'] ?? 0);
    }
}
