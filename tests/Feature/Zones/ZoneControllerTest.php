<?php

declare(strict_types=1);

use App\Data\ZoneShowData;
use App\Models\Building;
use App\Models\Colony;
use App\Models\Zone;
use App\Models\ZonePlan;
use App\Models\ZonePlanChange;
use App\Services\Zones\ZonePresenter;
use Database\Factories\ZoneFactory;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;

it('lists the active zones of the most recently seen colony', function (): void {
    $older = Colony::factory()->create(['last_seen_at' => now()->subDay()]);
    $latest = Colony::factory()->create(['name' => 'DeepWaterGo']);
    $farm = Zone::factory()->sodicaneFarm()->create(['colony_guid' => $latest->guid, 'name' => 'Ferme sodicane']);
    Zone::factory()->create(['colony_guid' => $latest->guid, 'name' => 'Abandoned rocket', 'orphaned' => true]);
    Zone::factory()->create(['colony_guid' => $latest->guid, 'name' => 'Old room', 'archived_at' => now()]);
    Zone::factory()->create(['colony_guid' => $older->guid, 'name' => 'Other colony']);

    $this->get(route('zones.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Zones/Index')
            ->where('colonyName', 'DeepWaterGo')
            ->where('echo', ['channel' => 'uplink', 'event' => '.zone.updated'])
            ->has('zones', 2)
            ->where('zones.0.name', 'Abandoned rocket')
            ->where('zones.0.orphaned', true)
            ->where('zones.0.problemCount', null)
            ->where('zones.0.problemsPartial', false)
            ->where('zones.0.updatedAt', null)
            ->where('zones.1.id', $farm->id)
            ->where('zones.1.cellCount', 40)
            ->where('zones.1.problemCount', 1)
            ->where('zones.1.problemsPartial', false)
            ->where('zones.1.updatedAt', now()->toIso8601String()));
});

it('lists no zone before any colony was seen', function (): void {
    Zone::factory()->create();

    $this->get(route('zones.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Zones/Index')
            ->where('colonyName', null)
            ->where('zones', []));
});

it('renders a zone page', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create(['orphaned' => true]);

    $this->get(route('zones.show', $zone))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Zones/Show')
            ->where('zone.name', 'Ferme sodicane')
            ->where('zone.guid', $zone->zone_guid)
            ->where('zone.orphaned', true)
            ->where('map.gridWidth', 606)
            ->where('mapTerrain.box.width', 22)
            ->has('mapPlants', 13)
            ->where('liveProps', ZonePresenter::PROPS_BY_KIND)
            ->has('entities', 14)
            ->has('problems', 1)
            ->where('echo', ['channel' => 'uplink', 'event' => '.zone.updated']));
});

it('computes only the requested props on a partial reload', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();
    $zone->update(['fast_state' => [...$zone->fast_state, 'critters' => [[
        'instanceId' => '1201', 'prefabId' => 'Pacu', 'cell' => 100, 'x' => 1.0, 'y' => 1.0, 'aquatic' => true, 'regionId' => 'pond:1201',
    ]]]]);
    $tables = [];
    DB::listen(function ($query) use (&$tables): void {
        preg_match_all('/(?:from|join) "([a-z_]+)"/i', $query->sql, $matches);
        array_push($tables, ...$matches[1]);
    });

    $this->get(route('zones.show', $zone))->assertOk();
    expect($tables)->toContain('elements');

    $tables = [];
    $partial = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'Zones/Show',
        'X-Inertia-Partial-Data' => 'critters',
    ])->get(route('zones.show', $zone));

    $partial->assertOk();
    expect(array_keys($partial->json('props')))->not->toContain('mapTerrain')
        ->and(array_keys($partial->json('props')))->not->toContain('map')
        ->and(array_keys($partial->json('props')))->not->toContain('entities')
        ->and($partial->json('props.critters.0.instanceId'))->toBe('1201')
        ->and($tables)->not->toContain('elements')
        ->and($tables)->not->toContain('buildings')
        ->and($tables)->not->toContain('zone_captures');
});

it('groups the live props by stream, each a prop of the page', function (): void {
    $properties = array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionMethod(ZoneShowData::class, '__construct'))->getParameters());

    expect(array_keys(ZonePresenter::PROPS_BY_KIND))->toBe(['list', 'layout', 'slow', 'fast', 'capture', 'plan'])
        ->and(array_diff(array_merge(...array_values(ZonePresenter::PROPS_BY_KIND)), $properties))->toBe([])
        ->and(ZonePresenter::PROPS_BY_KIND['fast'])->toContain('critters', 'mapPlants', 'zone')
        ->and(ZonePresenter::PROPS_BY_KIND['fast'])->not->toContain('mapTerrain')
        ->and(ZonePresenter::PROPS_BY_KIND['fast'])->not->toContain('map')
        ->and(ZonePresenter::PROPS_BY_KIND['slow'])->toContain('mapTerrain', 'eggs', 'zone')
        ->and(ZonePresenter::PROPS_BY_KIND['slow'])->not->toContain('critters')
        ->and(ZonePresenter::PROPS_BY_KIND['slow'])->not->toContain('map');
});

it('reloads every page prop with a stream except the link time, the stream map and the echo channel', function (): void {
    $properties = array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionMethod(ZoneShowData::class, '__construct'))->getParameters());

    expect(array_values(array_diff($properties, ...array_values(ZonePresenter::PROPS_BY_KIND))))->toEqualCanonicalizing(['linkSeenAt', 'liveProps', 'echo', 'fastRelay']);
});

it('does not find an archived zone', function (): void {
    $zone = Zone::factory()->create(['archived_at' => now()]);

    $this->get(route('zones.show', $zone))->assertNotFound();
});

it('shares the active zones of the latest colony with every page, for the side navigation', function (): void {
    $older = Colony::factory()->create(['last_seen_at' => now()->subDay()]);
    $latest = Colony::factory()->create();
    $pacu = Zone::factory()->create(['colony_guid' => $latest->guid, 'name' => 'Pacu']);
    $farm = Zone::factory()->create(['colony_guid' => $latest->guid, 'name' => 'Farm']);
    Zone::factory()->create(['colony_guid' => $latest->guid, 'name' => 'Old room', 'archived_at' => now()]);
    Zone::factory()->create(['colony_guid' => $older->guid, 'name' => 'Other colony']);

    $this->get(route('zones.show', $pacu))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('navZones', [
                ['id' => $farm->id, 'name' => 'Farm'],
                ['id' => $pacu->id, 'name' => 'Pacu'],
            ]));
});

it('gives the page what it needs to apply the raw fast frames of the zone', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create(['fast_session_id' => 'session-a', 'fast_game_seconds' => 3600.5]);

    $this->get(route('zones.show', $zone))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('zone.colonyGuid', $zone->colony_guid)
            ->where('zone.fastSessionId', 'session-a')
            ->where('zone.fastGameSeconds', 3600.5)
            ->where('fastRelay', ['channel' => 'uplink-data', 'event' => '.client-zone.fast', 'schema' => 1, 'tripsEvent' => '.client-zone.trips', 'tripsSchema' => 1]));
});

it('sends no plan while the zone has none', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();

    $this->get(route('zones.show', $zone))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan', null)
            ->where('planMap', null));
});

it('sends the plan and the map of the planned layout', function (): void {
    foreach ([['FarmStation', 2, 2], ['ManualGenerator', 2, 2]] as [$id, $width, $height]) {
        Building::factory()->create(['building_id' => $id, 'width' => $width, 'height' => $height, 'build_location_rule' => 'Anywhere']);
    }
    $zone = Zone::factory()->sodicaneFarm()->create();
    $plan = ZonePlan::factory()->for($zone)->create();
    $remove = ZonePlanChange::factory()->remove()->for($plan, 'plan')->create(['position' => 1, 'target_instance_id' => '7001', 'target_cell' => null, 'note' => 'Out']);
    $add = ZonePlanChange::factory()->add()->for($plan, 'plan')->create(['position' => 2, 'anchor_cell' => 10 * ZoneFactory::GRID_WIDTH + 5, 'note' => 'Power']);

    $this->get(route('zones.show', $zone))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.updatedAt', $plan->updated_at->toIso8601String())
            ->has('plan.changes', 2)
            ->where('plan.changes.0.id', $remove->id)
            ->where('plan.changes.0.kind', 'remove')
            ->where('plan.changes.0.movable', false)
            ->where('plan.changes.0.target.label', 'FarmStation #7001')
            ->where('plan.changes.0.target.width', 2)
            ->where('plan.changes.1.id', $add->id)
            ->where('plan.changes.1.name', 'ManualGenerator')
            ->where('plan.changes.1.x', 5)
            ->where('plan.changes.1.y', 10)
            ->where('plan.changes.1.width', 2)
            ->where('plan.changes.1.movable', true)
            ->where('plan.changes.1.target', null)
            ->where('plan.changes.1.note', 'Power')
            ->where('planMap.buildings', fn ($buildings) => collect($buildings)->pluck('instanceId')->all() === ['plan:'.$add->id])
            ->where('map.buildings.0.instanceId', '7001'));
});

it('reloads the plan props on a plan event', function (): void {
    expect(ZonePresenter::PROPS_BY_KIND['plan'])->toBe(['plan', 'planMap'])
        ->and(ZonePresenter::PROPS_BY_KIND['list'])->toContain('plan', 'planMap');
});
