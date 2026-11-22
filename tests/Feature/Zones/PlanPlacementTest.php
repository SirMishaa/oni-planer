<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Element;
use App\Models\Zone;
use App\Models\ZonePlan;
use App\Models\ZonePlanChange;
use App\Services\Zones\Plans\PlanPlacement;
use Database\Factories\ZoneFactory;

/** The cell of a world position on the sample grid. */
function placementCell(int $x, int $y): int
{
    return $y * ZoneFactory::GRID_WIDTH + $x;
}

/**
 * The sodicane farm, its plan and the game data the checks read.
 *
 * @return array{0: Zone, 1: ZonePlan}
 */
function placementPlan(): array
{
    Element::factory()->solid()->create(['element_id' => 'Dirt']);
    Element::factory()->gas()->create(['element_id' => 'Oxygen']);

    foreach ([
        ['FishFeeder', 1, 3, 'Anywhere', 'Unrotatable'],
        ['PlanterBox', 1, 1, 'OnFloor', 'Unrotatable'],
        ['ManualGenerator', 2, 2, 'OnFloor', 'Unrotatable'],
        ['CeilingLight', 1, 1, 'OnCeiling', 'Unrotatable'],
        ['GasPump', 2, 2, 'Anywhere', 'R360'],
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

    $zone = Zone::factory()->sodicaneFarm()->create();

    return [$zone, ZonePlan::factory()->for($zone)->create()];
}

/** @param  array<string, mixed>  $attributes */
function placementAdd(ZonePlan $plan, string $prefabId, int $x, int $y, array $attributes = []): ZonePlanChange
{
    return ZonePlanChange::factory()->add()->for($plan, 'plan')->create([
        'prefab_id' => $prefabId,
        'anchor_cell' => placementCell($x, $y),
        ...$attributes,
    ]);
}

it('names the building an add overlaps', function (): void {
    [$zone, $plan] = placementPlan();
    $change = placementAdd($plan, 'ManualGenerator', 113, 201);

    expect(app(PlanPlacement::class)->problem($zone, $plan, $change))
        ->toBe('ManualGenerator at w(113,201): w(114,201) overlaps #7001 FarmStation.');
});

it('refuses a solid cell outside tiles', function (): void {
    [$zone, $plan] = placementPlan();
    $change = placementAdd($plan, 'FishFeeder', 99, 200);

    expect(app(PlanPlacement::class)->problem($zone, $plan, $change))
        ->toBe('FishFeeder at w(99,200): w(99,200) is a solid cell.');
});

it('refuses a rotation the building does not permit', function (): void {
    [$zone, $plan] = placementPlan();
    $feeder = placementAdd($plan, 'FishFeeder', 105, 201, ['orientation' => 'R90']);
    $pump = placementAdd($plan, 'GasPump', 101, 201, ['orientation' => 'R270']);

    expect(app(PlanPlacement::class)->problem($zone, $plan, $feeder))
        ->toBe('FishFeeder at w(105,201): rotation R90 not permitted for FishFeeder.')
        ->and(app(PlanPlacement::class)->problem($zone, $plan, $pump))->toBeNull();
});

it('needs a floor under an OnFloor building', function (): void {
    [$zone, $plan] = placementPlan();
    $floating = placementAdd($plan, 'PlanterBox', 105, 202);
    $grounded = placementAdd($plan, 'PlanterBox', 106, 201);

    expect(app(PlanPlacement::class)->problem($zone, $plan, $floating))
        ->toBe('PlanterBox at w(105,202): needs a floor under w(105,201).')
        ->and(app(PlanPlacement::class)->problem($zone, $plan, $grounded))->toBeNull();
});

it('needs a ceiling above an OnCeiling building, planned tiles included', function (): void {
    [$zone, $plan] = placementPlan();
    $light = placementAdd($plan, 'CeilingLight', 105, 202);

    expect(app(PlanPlacement::class)->problem($zone, $plan, $light))
        ->toBe('CeilingLight at w(105,202): needs a ceiling above w(105,203).');

    placementAdd($plan, 'Tile', 105, 203, ['position' => 2]);

    expect(app(PlanPlacement::class)->problem($zone, $plan, $light))->toBeNull();
});

it('refuses a footprint outside the zone box', function (): void {
    [$zone, $plan] = placementPlan();
    $change = placementAdd($plan, 'FishFeeder', 121, 201);

    expect(app(PlanPlacement::class)->problem($zone, $plan, $change))
        ->toBe('FishFeeder at w(121,201): w(121,201) is outside the zone box.');
});

it('never finds a problem with an unchecked change or a remove', function (): void {
    [$zone, $plan] = placementPlan();
    $wire = placementAdd($plan, 'Wire', 114, 201, ['object_layer' => 'Wire', 'checked' => false]);
    $remove = ZonePlanChange::factory()->remove()->for($plan, 'plan')->create(['target_instance_id' => '7001', 'target_cell' => null]);

    expect(app(PlanPlacement::class)->problem($zone, $plan, $wire))->toBeNull()
        ->and(app(PlanPlacement::class)->problem($zone, $plan, $remove))->toBeNull();
});

it('accepts an add over a building the plan removes and lists it as dependent on that remove', function (): void {
    [$zone, $plan] = placementPlan();
    $remove = ZonePlanChange::factory()->remove()->for($plan, 'plan')->create(['target_instance_id' => '7001', 'target_cell' => null]);
    $add = placementAdd($plan, 'ManualGenerator', 114, 201, ['position' => 2]);
    $elsewhere = placementAdd($plan, 'PlanterBox', 106, 201, ['position' => 3]);

    expect(app(PlanPlacement::class)->problem($zone, $plan, $add))->toBeNull()
        ->and(array_map(fn (ZonePlanChange $change): int => $change->id, app(PlanPlacement::class)->dependents($zone, $plan, $remove)))->toBe([$add->id])
        ->and(app(PlanPlacement::class)->dependents($zone, $plan, $elsewhere))->toBe([]);
});

it('lets an unchecked change under a checked add', function (): void {
    [$zone, $plan] = placementPlan();
    placementAdd($plan, 'Wire', 105, 201, ['object_layer' => 'Wire', 'checked' => false]);
    $feeder = placementAdd($plan, 'FishFeeder', 105, 201, ['position' => 2]);

    expect(app(PlanPlacement::class)->problem($zone, $plan, $feeder))->toBeNull();
});
