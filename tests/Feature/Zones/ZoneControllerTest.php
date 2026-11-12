<?php

declare(strict_types=1);

use App\Models\Colony;
use App\Models\Zone;
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
            ->component('Zones/Index', false)
            ->where('colonyName', 'DeepWaterGo')
            ->where('echo', ['channel' => 'uplink', 'event' => '.zone.updated'])
            ->has('zones', 2)
            ->where('zones.0.name', 'Abandoned rocket')
            ->where('zones.0.orphaned', true)
            ->where('zones.0.problemCount', null)
            ->where('zones.0.updatedAt', null)
            ->where('zones.1.id', $farm->id)
            ->where('zones.1.cellCount', 40)
            ->where('zones.1.problemCount', 1)
            ->where('zones.1.updatedAt', now()->toIso8601String()));
});

it('lists no zone before any colony was seen', function (): void {
    Zone::factory()->create();

    $this->get(route('zones.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Zones/Index', false)
            ->where('colonyName', null)
            ->where('zones', []));
});

it('renders a zone page', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create(['orphaned' => true]);

    $this->get(route('zones.show', $zone))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Zones/Show', false)
            ->where('zone.name', 'Ferme sodicane')
            ->where('zone.orphaned', true)
            ->where('map.gridWidth', 606)
            ->has('entities', 14)
            ->has('problems', 1)
            ->where('echo', ['channel' => 'uplink', 'event' => '.zone.updated']));
});

it('does not find an archived zone', function (): void {
    $zone = Zone::factory()->create(['archived_at' => now()]);

    $this->get(route('zones.show', $zone))->assertNotFound();
});
