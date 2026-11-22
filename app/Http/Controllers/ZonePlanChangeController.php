<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Zones\ReconcileZonePlan;
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

    /** Moves a planned add; nothing changes when it, or a change that relied on its old spot, no longer stands. */
    public function update(MoveZonePlanChangeRequest $request, Zone $zone, ZonePlanChange $change): RedirectResponse
    {
        $plan = $this->plan($zone, $change);
        if (! $change->adds() || $change->anchor_cell === null) {
            throw ValidationException::withMessages(['position' => 'Only a planned add can be moved.']);
        }

        $layout = is_array($zone->layout) ? $zone->layout : [];
        $gridWidth = (int) ($layout['gridWidth'] ?? 0);
        $box = is_array($layout['box'] ?? null) ? $layout['box'] : null;
        $x = $request->integer('x');
        $y = $request->integer('y');

        if ($gridWidth <= 0 || ($box !== null && ($x < (int) ($box['x'] ?? 0) || $y < (int) ($box['y'] ?? 0)
            || $x >= (int) ($box['x'] ?? 0) + (int) ($box['width'] ?? 0) || $y >= (int) ($box['y'] ?? 0) + (int) ($box['height'] ?? 0)))) {
            throw ValidationException::withMessages(['position' => "({$x}, {$y}) is outside the zone box."]);
        }

        DB::transaction(function () use ($zone, $plan, $change, $x, $y, $gridWidth): void {
            $unharmed = $plan->changes()->get()
                ->reject(fn (ZonePlanChange $other): bool => $other->id === $change->id)
                ->filter(fn (ZonePlanChange $other): bool => $this->placement->problem($zone, $plan, $other) === null);

            $change->forceFill([
                'anchor_cell' => $y * $gridWidth + $x,
                'moved_by_player' => true,
                'state' => $change->state === ZonePlanChangeState::Applied ? ZonePlanChangeState::Pending : $change->state,
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

    /** Deletes a change, unless another planned add stands only thanks to it. */
    public function destroy(Zone $zone, ZonePlanChange $change): RedirectResponse
    {
        $plan = $this->plan($zone, $change);

        $dependents = $this->placement->dependents($zone, $plan, $change);
        if ($dependents !== []) {
            throw ValidationException::withMessages([
                'change' => 'Other changes rely on this one: '.collect($dependents)->map(fn (ZonePlanChange $dependent): string => (string) $dependent->prefab_id)->implode(', ').'.',
            ]);
        }

        DB::transaction(function () use ($plan, $change): void {
            $change->delete();
            if (! $plan->changes()->exists()) {
                $plan->delete();
            }
        });

        ZoneUpdated::dispatch($zone->zone_guid, $zone->colony_guid, 'plan');

        return back();
    }

    /** The plan of the zone the change belongs to; 404 for a change of another zone or a gone zone. */
    private function plan(Zone $zone, ZonePlanChange $change): ZonePlan
    {
        abort_if($zone->archived_at !== null || $zone->transient, 404);
        $plan = $change->plan;
        abort_unless($plan->zone_id === $zone->id, 404);

        return $plan;
    }
}
