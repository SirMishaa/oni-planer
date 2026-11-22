<?php

declare(strict_types=1);

use App\Enums\ZonePlanChangeState;
use App\Events\ZoneUpdated;
use App\Models\Building;
use App\Models\Element;
use App\Models\Zone;
use App\Models\ZonePlan;
use App\Models\ZonePlanChange;
use Database\Factories\ZoneFactory;
use Illuminate\Support\Facades\Event;

/** The cell of a world position on the sample grid. */
function editCell(int $x, int $y): int
{
    return $y * ZoneFactory::GRID_WIDTH + $x;
}

/**
 * The sodicane farm and an empty plan for it.
 *
 * @return array{0: Zone, 1: ZonePlan}
 */
function editPlan(): array
{
    Element::factory()->solid()->create(['element_id' => 'Dirt']);
    Element::factory()->gas()->create(['element_id' => 'Oxygen']);

    foreach ([
        ['PlanterBox', 1, 1, 'OnFloor', 'Unrotatable'],
        ['Tile', 1, 1, 'Tile', 'Unrotatable'],
    ] as [$id, $width, $height, $rule, $rotations]) {
        Building::factory()->create([
            'building_id' => $id,
            'width' => $width,
            'height' => $height,
            'object_layer' => 'Building',
            'build_location_rule' => $rule,
            'permitted_rotations' => $rotations,
        ]);
    }

    $zone = Zone::factory()->sodicaneFarm()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-sodicane']);

    return [$zone, ZonePlan::factory()->for($zone)->create()];
}

/** @param  array<string, mixed>  $attributes */
function editAdd(ZonePlan $plan, string $prefabId, int $x, int $y, array $attributes = []): ZonePlanChange
{
    return ZonePlanChange::factory()->add()->for($plan, 'plan')->create([
        'prefab_id' => $prefabId,
        'anchor_cell' => editCell($x, $y),
        ...$attributes,
    ]);
}

it('moves an add and remembers the player moved it', function (): void {
    Event::fake([ZoneUpdated::class]);
    [$zone, $plan] = editPlan();
    $add = editAdd($plan, 'PlanterBox', 105, 201);

    $this->patch(route('zones.plan.changes.update', [$zone, $add]), ['x' => 106, 'y' => 201])->assertRedirect();

    expect($add->fresh()->anchor_cell)->toBe(editCell(106, 201))
        ->and($add->fresh()->moved_by_player)->toBeTrue();
    Event::assertDispatched(ZoneUpdated::class, fn (ZoneUpdated $event): bool => $event->kind === 'plan' && $event->zoneGuid === 'zone-sodicane');
});

it('refuses a move onto a built cell and leaves the add where it was', function (): void {
    [$zone, $plan] = editPlan();
    $add = editAdd($plan, 'PlanterBox', 105, 201);

    $this->patch(route('zones.plan.changes.update', [$zone, $add]), ['x' => 114, 'y' => 201])->assertSessionHasErrors('position');

    expect($add->fresh()->anchor_cell)->toBe(editCell(105, 201))
        ->and($add->fresh()->moved_by_player)->toBeFalse();
});

it('refuses a move outside the zone box', function (): void {
    [$zone, $plan] = editPlan();
    $add = editAdd($plan, 'PlanterBox', 105, 201);

    $this->patch(route('zones.plan.changes.update', [$zone, $add]), ['x' => 300, 'y' => 201])->assertSessionHasErrors('position');

    expect($add->fresh()->anchor_cell)->toBe(editCell(105, 201));
});

it('refuses to move an add the game has built', function (): void {
    [$zone, $plan] = editPlan();
    $add = editAdd($plan, 'PlanterBox', 105, 201, ['state' => ZonePlanChangeState::Applied]);

    $this->patch(route('zones.plan.changes.update', [$zone, $add]), ['x' => 106, 'y' => 201])->assertSessionHasErrors('position');

    expect($add->fresh()->state)->toBe(ZonePlanChangeState::Applied)
        ->and($add->fresh()->anchor_cell)->toBe(editCell(105, 201));
});

it('refuses a move on a zone whose layout has not arrived', function (): void {
    [$zone, $plan] = editPlan();
    $add = editAdd($plan, 'PlanterBox', 105, 201);
    $zone->update(['layout' => null]);

    $this->patch(route('zones.plan.changes.update', [$zone, $add]), ['x' => 106, 'y' => 201])
        ->assertSessionHasErrors(['position' => 'The layout of this zone has not arrived from the game yet: nothing to move against.']);
});

it('moves the plan updated time when a change moves', function (): void {
    [$zone, $plan] = editPlan();
    $add = editAdd($plan, 'PlanterBox', 105, 201);
    $plan->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    $this->patch(route('zones.plan.changes.update', [$zone, $add]), ['x' => 106, 'y' => 201])->assertRedirect();

    expect($plan->fresh()->updated_at->greaterThan(now()->subMinute()))->toBeTrue();
});

it('does not move a remove', function (): void {
    [$zone, $plan] = editPlan();
    $remove = ZonePlanChange::factory()->remove()->for($plan, 'plan')->create(['target_instance_id' => '7001', 'target_cell' => null]);

    $this->patch(route('zones.plan.changes.update', [$zone, $remove]), ['x' => 106, 'y' => 201])->assertSessionHasErrors('position');
});

it('marks a deleted change as deleted by the player and keeps the plan', function (): void {
    Event::fake([ZoneUpdated::class]);
    [$zone, $plan] = editPlan();
    $add = editAdd($plan, 'PlanterBox', 105, 201);

    $this->delete(route('zones.plan.changes.destroy', [$zone, $add]))->assertRedirect();

    expect($add->fresh()->deleted_by_player)->toBeTrue()
        ->and($plan->fresh())->not->toBeNull()
        ->and($plan->changes()->count())->toBe(0);
    Event::assertDispatched(ZoneUpdated::class, fn (ZoneUpdated $event): bool => $event->kind === 'plan');
});

it('answers 404 for a change the player already deleted', function (): void {
    [$zone, $plan] = editPlan();
    $add = editAdd($plan, 'PlanterBox', 105, 201, ['deleted_by_player' => true]);

    $this->patch(route('zones.plan.changes.update', [$zone, $add]), ['x' => 106, 'y' => 201])->assertNotFound();
    $this->delete(route('zones.plan.changes.destroy', [$zone, $add]))->assertNotFound();
});

it('moves an add onto the spot of a change the player deleted', function (): void {
    [$zone, $plan] = editPlan();
    editAdd($plan, 'PlanterBox', 106, 201, ['deleted_by_player' => true]);
    $add = editAdd($plan, 'PlanterBox', 105, 201, ['position' => 2]);

    $this->patch(route('zones.plan.changes.update', [$zone, $add]), ['x' => 106, 'y' => 201])->assertRedirect()->assertSessionHasNoErrors();

    expect($add->fresh()->anchor_cell)->toBe(editCell(106, 201));
});

it('refuses to delete a remove another add depends on', function (): void {
    [$zone, $plan] = editPlan();
    $remove = ZonePlanChange::factory()->remove()->for($plan, 'plan')->create(['target_instance_id' => '7001', 'target_cell' => null]);
    editAdd($plan, 'PlanterBox', 114, 201, ['position' => 2]);

    $this->delete(route('zones.plan.changes.destroy', [$zone, $remove]))->assertSessionHasErrors('change');

    expect($remove->fresh())->not->toBeNull();
});

it('answers 404 for a change of another zone', function (): void {
    [$zone] = editPlan();
    $otherPlan = ZonePlan::factory()->for(Zone::factory()->create(['zone_guid' => 'zone-other']))->create();
    $foreign = ZonePlanChange::factory()->add()->for($otherPlan, 'plan')->create();

    $this->patch(route('zones.plan.changes.update', [$zone, $foreign]), ['x' => 1, 'y' => 1])->assertNotFound();
    $this->delete(route('zones.plan.changes.destroy', [$zone, $foreign]))->assertNotFound();
});

it('refuses a move that leaves another add without its support', function (): void {
    [$zone, $plan] = editPlan();
    $tile = editAdd($plan, 'Tile', 105, 201);
    $planter = editAdd($plan, 'PlanterBox', 105, 202, ['position' => 2]);

    $this->patch(route('zones.plan.changes.update', [$zone, $tile]), ['x' => 106, 'y' => 201])->assertSessionHasErrors('position');

    expect($tile->fresh()->anchor_cell)->toBe(editCell(105, 201))
        ->and($tile->fresh()->moved_by_player)->toBeFalse()
        ->and($planter->fresh()->anchor_cell)->toBe(editCell(105, 202));
});
