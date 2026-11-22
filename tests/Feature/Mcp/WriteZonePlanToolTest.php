<?php

declare(strict_types=1);

use App\Events\ZoneUpdated;
use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\WriteZonePlanTool;
use App\Mcp\Tools\ZonePlanTool;
use App\Models\Building;
use App\Models\Colony;
use App\Models\Element;
use App\Models\Zone;
use App\Models\ZonePlan;
use App\Models\ZonePlanChange;
use Illuminate\Support\Facades\Event;

/** The sodicane farm of a fresh colony, with the game data a plan reads. */
function writePlanZone(): Zone
{
    Element::factory()->solid()->create(['element_id' => 'Dirt']);
    Element::factory()->gas()->create(['element_id' => 'Oxygen']);

    foreach ([
        ['ManualGenerator', 2, 2, 'OnFloor', 'Building'],
        ['Wire', 1, 1, 'Anywhere', 'Wire'],
        ['Tile', 1, 1, 'Tile', 'Building'],
    ] as [$id, $width, $height, $rule, $layer]) {
        Building::factory()->create([
            'building_id' => $id,
            'width' => $width,
            'height' => $height,
            'object_layer' => $layer,
            'build_location_rule' => $rule,
            'permitted_rotations' => 'Unrotatable',
        ]);
    }

    $colony = Colony::factory()->create();

    return Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);
}

it('writes a plan and reads it back', function (): void {
    Event::fake([ZoneUpdated::class]);
    $zone = writePlanZone();

    OniServer::tool(WriteZonePlanTool::class, ['zone' => 'Sodicane', 'changes' => [
        ['kind' => 'add', 'prefab' => 'ManualGenerator', 'x' => 116, 'y' => 201, 'note' => 'Power for the farm'],
        ['kind' => 'remove', 'target' => '#7001', 'note' => 'Station moves out'],
        ['kind' => 'replace', 'prefab' => 'Tile', 'x' => 100, 'y' => 200, 'target' => 'w(100,200)', 'note' => 'Plain floor'],
    ]])->assertOk()->assertSee('Plan of zone #'.$zone->id.' written: 3 changes');

    $changes = ZonePlanChange::query()->orderBy('position')->get();
    expect($changes)->toHaveCount(3)
        ->and($changes[1]->target_instance_id)->toBe('7001')
        ->and($changes[1]->target_cell)->toBeNull()
        ->and($changes[2]->target_cell)->toBe(200 * 606 + 100)
        ->and($changes[2]->target_instance_id)->toBeNull();
    Event::assertDispatched(ZoneUpdated::class, fn (ZoneUpdated $event): bool => $event->zoneGuid === $zone->zone_guid && $event->kind === 'plan');

    OniServer::tool(ZonePlanTool::class, ['zone' => 'Sodicane'])
        ->assertOk()
        ->assertSee(sprintf('#%d add ManualGenerator @ w(116,201) Neutral · pending · checked · "Power for the farm"', $changes[0]->id))
        ->assertSee(sprintf('#%d remove target #7001 FarmStation · pending · checked · "Station moves out"', $changes[1]->id))
        ->assertSee(sprintf('#%d replace Tile @ w(100,200) Neutral, target w(100,200) FarmTile · pending · checked · "Plain floor"', $changes[2]->id));
});

it('replaces the previous plan', function (): void {
    writePlanZone();

    OniServer::tool(WriteZonePlanTool::class, ['zone' => 'Sodicane', 'changes' => [
        ['kind' => 'remove', 'target' => '#7001', 'note' => 'First'],
        ['kind' => 'add', 'prefab' => 'ManualGenerator', 'x' => 116, 'y' => 201, 'note' => 'First'],
    ]])->assertOk();
    OniServer::tool(WriteZonePlanTool::class, ['zone' => 'Sodicane', 'changes' => [
        ['kind' => 'add', 'prefab' => 'ManualGenerator', 'x' => 117, 'y' => 201, 'note' => 'Second'],
    ]])->assertOk();

    expect(ZonePlan::query()->count())->toBe(1)
        ->and(ZonePlanChange::query()->pluck('note')->all())->toBe(['Second']);
});

it('clears the plan with an empty list', function (): void {
    Event::fake([ZoneUpdated::class]);
    writePlanZone();

    OniServer::tool(WriteZonePlanTool::class, ['zone' => 'Sodicane', 'changes' => [
        ['kind' => 'remove', 'target' => '#7001', 'note' => 'Gone'],
    ]])->assertOk();
    OniServer::tool(WriteZonePlanTool::class, ['zone' => 'Sodicane', 'changes' => []])
        ->assertOk()
        ->assertSee('cleared');

    expect(ZonePlan::query()->count())->toBe(0);
    Event::assertDispatchedTimes(ZoneUpdated::class, 2);
    OniServer::tool(ZonePlanTool::class, ['zone' => 'Sodicane'])->assertOk()->assertSee('No plan for zone');
});

it('refuses an overlapping add and stores nothing', function (): void {
    writePlanZone();

    OniServer::tool(WriteZonePlanTool::class, ['zone' => 'Sodicane', 'changes' => [
        ['kind' => 'add', 'prefab' => 'ManualGenerator', 'x' => 116, 'y' => 201, 'note' => 'Fits'],
        ['kind' => 'add', 'prefab' => 'ManualGenerator', 'x' => 114, 'y' => 201, 'note' => 'On the station'],
        ['kind' => 'add', 'prefab' => 'ManualGenerator', 'x' => 117, 'y' => 201, 'note' => 'On the first one'],
    ]])->assertHasErrors([
        'change 2: ManualGenerator at w(114,201): w(114,201) overlaps #7001 FarmStation.',
        'change 3: ManualGenerator at w(117,201)',
    ]);

    expect(ZonePlan::query()->count())->toBe(0)
        ->and(ZonePlanChange::query()->count())->toBe(0);
});

it('lists every problem at once: missing targets, unknown prefab, missing cell and placement', function (): void {
    writePlanZone();

    OniServer::tool(WriteZonePlanTool::class, ['zone' => 'Sodicane', 'changes' => [
        ['kind' => 'remove', 'target' => '#999', 'note' => 'Nothing there'],
        ['kind' => 'remove', 'target' => 'w(116,202)', 'note' => 'No tile there'],
        ['kind' => 'add', 'prefab' => 'Teleporter', 'x' => 116, 'y' => 201, 'note' => 'Unknown'],
        ['kind' => 'add', 'prefab' => 'ManualGenerator', 'note' => 'Nowhere'],
        ['kind' => 'add', 'prefab' => 'ManualGenerator', 'x' => 114, 'y' => 201, 'note' => 'On the station'],
    ]])->assertHasErrors([
        'change 1: target #999 is not a building of the zone layout',
        'change 2: target w(116,202) is not a tile of the zone layout',
        'change 3: unknown prefab Teleporter',
        'change 4: an add needs x and y',
        'change 5: ManualGenerator at w(114,201): w(114,201) overlaps #7001 FarmStation.',
    ]);

    expect(ZonePlan::query()->count())->toBe(0);
});

it('refuses a plan on a zone whose layout has not arrived', function (): void {
    $zone = writePlanZone();
    $zone->update(['layout' => null]);

    OniServer::tool(WriteZonePlanTool::class, ['zone' => 'Sodicane', 'changes' => [
        ['kind' => 'add', 'prefab' => 'ManualGenerator', 'x' => 116, 'y' => 201, 'note' => 'Power'],
    ]])->assertHasErrors(['layout']);

    expect(ZonePlan::query()->count())->toBe(0);
});

it('accepts an unchecked wire without validation', function (): void {
    writePlanZone();

    OniServer::tool(WriteZonePlanTool::class, ['zone' => 'Sodicane', 'changes' => [
        ['kind' => 'add', 'prefab' => 'Wire', 'x' => 114, 'y' => 201, 'note' => 'Under the station'],
    ]])->assertOk();

    $change = ZonePlanChange::query()->sole();
    expect($change->checked)->toBeFalse()
        ->and($change->object_layer)->toBe('Wire');

    OniServer::tool(ZonePlanTool::class, ['zone' => 'Sodicane'])->assertOk()->assertSee('Wire @ w(114,201) Neutral · pending · unchecked');
});
