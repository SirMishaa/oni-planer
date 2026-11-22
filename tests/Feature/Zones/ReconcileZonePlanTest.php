<?php

declare(strict_types=1);

use App\Actions\Uplink\StoreZoneLayout;
use App\Actions\Zones\ReconcileZonePlan;
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
function reconcileCell(int $x, int $y): int
{
    return $y * ZoneFactory::GRID_WIDTH + $x;
}

/**
 * The sodicane farm and an empty plan for it.
 *
 * @return array{0: Zone, 1: ZonePlan}
 */
function reconcilePlan(): array
{
    Element::factory()->solid()->create(['element_id' => 'Dirt']);
    Element::factory()->gas()->create(['element_id' => 'Oxygen']);

    foreach ([
        ['FarmStation', 2, 2, 'OnFloor', 'Unrotatable'],
        ['PlanterBox', 1, 1, 'OnFloor', 'Unrotatable'],
        ['Tile', 1, 1, 'Tile', 'Unrotatable'],
        ['FarmTile', 1, 1, 'Tile', 'FlipV'],
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
function reconcileAdd(ZonePlan $plan, string $prefabId, int $x, int $y, array $attributes = []): ZonePlanChange
{
    return ZonePlanChange::factory()->add()->for($plan, 'plan')->create([
        'prefab_id' => $prefabId,
        'anchor_cell' => reconcileCell($x, $y),
        ...$attributes,
    ]);
}

/** @param  array<string, mixed>  $building */
function reconcileBuild(Zone $zone, array $building): void
{
    $layout = $zone->layout;
    $layout['buildings'][] = $building;
    $zone->update(['layout' => $layout]);
}

it('marks an add applied once the game has built it', function (): void {
    [$zone, $plan] = reconcilePlan();
    $add = reconcileAdd($plan, 'FarmStation', 114, 201);

    expect(app(ReconcileZonePlan::class)->handle($zone))->toBeTrue()
        ->and($add->fresh()->state)->toBe(ZonePlanChangeState::Applied);
});

it('puts an applied add back to pending when its building is gone', function (): void {
    [$zone, $plan] = reconcilePlan();
    $add = reconcileAdd($plan, 'PlanterBox', 105, 201, ['state' => ZonePlanChangeState::Applied]);

    expect(app(ReconcileZonePlan::class)->handle($zone))->toBeTrue()
        ->and($add->fresh()->state)->toBe(ZonePlanChangeState::Pending);
});

it('marks an applied add conflicted when its building is gone and its spot taken', function (): void {
    [$zone, $plan] = reconcilePlan();
    $add = reconcileAdd($plan, 'PlanterBox', 114, 201, ['state' => ZonePlanChangeState::Applied]);

    app(ReconcileZonePlan::class)->handle($zone);

    expect($add->fresh()->state)->toBe(ZonePlanChangeState::Conflicted);
});

it('marks a pending add conflicted when something else is built on its cells', function (): void {
    [$zone, $plan] = reconcilePlan();
    $add = reconcileAdd($plan, 'PlanterBox', 105, 201);

    expect(app(ReconcileZonePlan::class)->handle($zone))->toBeFalse()
        ->and($add->fresh()->state)->toBe(ZonePlanChangeState::Pending);

    reconcileBuild($zone, ['instanceId' => '7002', 'prefabId' => 'FarmStation', 'anchorCell' => reconcileCell(104, 201), 'width' => 2, 'height' => 2, 'orientation' => 'Neutral']);

    expect(app(ReconcileZonePlan::class)->handle($zone))->toBeTrue()
        ->and($add->fresh()->state)->toBe(ZonePlanChangeState::Conflicted);
});

it('drops a remove whose target is gone', function (): void {
    [$zone, $plan] = reconcilePlan();
    $gone = ZonePlanChange::factory()->remove()->for($plan, 'plan')->create(['target_instance_id' => '9999', 'target_cell' => null]);
    $kept = ZonePlanChange::factory()->remove()->for($plan, 'plan')->create(['position' => 2, 'target_instance_id' => '7001', 'target_cell' => null]);

    expect(app(ReconcileZonePlan::class)->handle($zone))->toBeTrue()
        ->and($gone->fresh())->toBeNull()
        ->and($kept->fresh())->not->toBeNull();
});

it('marks a replace applied once its building stands where the target was', function (): void {
    [$zone, $plan] = reconcilePlan();
    $replace = ZonePlanChange::factory()->replace()->for($plan, 'plan')->create([
        'prefab_id' => 'FarmTile',
        'anchor_cell' => reconcileCell(113, 200),
        'target_instance_id' => null,
        'target_cell' => reconcileCell(113, 200),
    ]);

    app(ReconcileZonePlan::class)->handle($zone);
    expect($replace->fresh()->state)->toBe(ZonePlanChangeState::Pending);

    $layout = $zone->layout;
    $layout['tiles'] = array_map(fn (array $tile): array => $tile['cell'] === reconcileCell(113, 200) ? [...$tile, 'prefabId' => 'FarmTile'] : $tile, $layout['tiles']);
    $zone->update(['layout' => $layout]);

    expect(app(ReconcileZonePlan::class)->handle($zone))->toBeTrue()
        ->and($replace->fresh()->state)->toBe(ZonePlanChangeState::Applied);
});

it('deletes the plan once no change remains', function (): void {
    [$zone, $plan] = reconcilePlan();
    ZonePlanChange::factory()->remove()->for($plan, 'plan')->create(['target_instance_id' => '9999', 'target_cell' => null]);

    expect(app(ReconcileZonePlan::class)->handle($zone))->toBeTrue()
        ->and($plan->fresh())->toBeNull();
});

it('follows the game when a zone layout is stored and tells open pages', function (): void {
    Event::fake([ZoneUpdated::class]);
    [$zone, $plan] = reconcilePlan();
    $add = reconcileAdd($plan, 'FarmStation', 114, 201);

    app(StoreZoneLayout::class)->handle([...$zone->layout, 'version' => 2]);

    expect($add->fresh()->state)->toBe(ZonePlanChangeState::Applied);
    Event::assertDispatched(ZoneUpdated::class, fn (ZoneUpdated $event): bool => $event->kind === 'plan' && $event->zoneGuid === 'zone-sodicane');
});
