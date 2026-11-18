<?php

declare(strict_types=1);

use App\Actions\Uplink\SyncZoneList;
use App\Events\Uplink\UplinkCommandIssued;
use App\Events\Uplink\ZoneListSynced;
use App\Events\Uplink\ZoneStateStored;
use App\Listeners\Uplink\ResyncZoneWithoutLayout;
use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\ZoneOverviewTool;
use App\Models\Colony;
use App\Models\UplinkCommand;
use App\Models\Zone;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

it('keeps transient zones out of the active zones', function (): void {
    $regular = Zone::factory()->create(['colony_guid' => 'colony-1']);
    Zone::factory()->transient()->create(['colony_guid' => 'colony-1']);

    expect(Zone::query()->active()->pluck('id')->all())->toBe([$regular->id])
        ->and(Zone::query()->where('transient', true)->count())->toBe(1);
});

it('does not archive transient zones when the zone list syncs', function (): void {
    Event::fake([ZoneListSynced::class]);
    $transient = Zone::factory()->transient()->create(['colony_guid' => 'colony-1']);
    $dropped = Zone::factory()->create(['colony_guid' => 'colony-1']);

    app(SyncZoneList::class)->handle(['colonyId' => 'colony-1', 'zones' => []]);

    expect($transient->fresh()->archived_at)->toBeNull()
        ->and($dropped->fresh()->archived_at)->not->toBeNull();
    Event::assertDispatched(ZoneListSynced::class, fn (ZoneListSynced $event): bool => ! in_array($transient->zone_guid, $event->zoneGuids, true));
});

it('does not resync a transient zone without layout', function (): void {
    Event::fake([UplinkCommandIssued::class]);
    Zone::factory()->transient()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-t', 'layout' => null, 'created_at' => now()->subMinutes(5)]);

    app(ResyncZoneWithoutLayout::class)->handle(new ZoneStateStored('colony-1', 'zone-t', 'fast', 10.0));

    expect(UplinkCommand::query()->count())->toBe(0);
});

it('resolves a transient zone by id but not by name in MCP tools', function (): void {
    $colony = Colony::factory()->create();
    $zone = Zone::factory()->sodicaneFarm()->transient()->create(['colony_guid' => $colony->guid, 'name' => 'Hidden/Sample']);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => (string) $zone->id])
        ->assertOk()
        ->assertSee('Hidden/Sample');

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'Hidden/Sample'])
        ->assertHasErrors(['No active zone matches "Hidden/Sample". Call list_zones.']);
});

it('answers 404 for the page and capture of a transient zone', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->transient()->create();

    $this->get(route('zones.show', $zone))->assertNotFound();
    $this->post(route('zones.captures.store', $zone))->assertNotFound();
});

it('does not show the crowding mismatches of a transient zone on the colony page', function (): void {
    $mismatch = [['regionId' => 'pond:1', 'prefabId' => 'Pacu', 'field' => 'overcrowded', 'game' => true, 'planner' => false, 'at' => '2026-11-14T10:00:00+00:00']];
    Zone::factory()->transient()->create(['name' => 'Hidden', 'crowding_mismatches' => $mismatch]);

    $this->get('/colony/uplink')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('crowdingMismatches', 0));
});
