<?php

declare(strict_types=1);

use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\EntityTool;
use App\Models\Colony;
use App\Models\Zone;

beforeEach(function (): void {
    $colony = Colony::factory()->create();
    $this->zone = Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Ferme sodicane']);
});

it('details a building with its layout and state', function (): void {
    OniServer::tool(EntityTool::class, ['instanceId' => '7001'])
        ->assertOk()
        ->assertSee('(FarmStation) #7001')
        ->assertSee('w(114,201) 2x2')
        ->assertSee('MaterialsUnavailable')
        ->assertSee('Fertilizer 0 kg')
        ->assertSee('zones: #'.$this->zone->id.' Ferme sodicane')
        ->assertSee('lookup FarmStation');
});

it('details a plant with its growth', function (): void {
    OniServer::tool(EntityTool::class, ['instanceId' => '5100'])
        ->assertOk()
        ->assertSee('(SaltySticksPlant) #5100')
        ->assertSee('w(100,201)')
        ->assertSee('growth 75 %')
        ->assertSee('next harvest in 1500 s');
});

it('reports an unknown instance id', function (): void {
    OniServer::tool(EntityTool::class, ['instanceId' => 'nope'])->assertHasErrors(['No plant, building, critter or egg #nope']);
});

it('does not claim no problems for a building known only from the layout', function (): void {
    $this->zone->update(['fast_state' => null]);

    OniServer::tool(EntityTool::class, ['instanceId' => '7001'])
        ->assertOk()
        ->assertSee('problems: unavailable')
        ->assertDontSee('problems: none');
});

it('dates the entity with the age of its zone data and accepts a numeric id', function (): void {
    OniServer::tool(EntityTool::class, ['instanceId' => 7001])
        ->assertOk()
        ->assertSee('zone fast')
        ->assertSee('(FarmStation) #7001');
});

it('describes a critter by instance id', function (): void {
    $fast = $this->zone->fast_state;
    $fast['critters'] = [[
        'instanceId' => '1234', 'prefabId' => 'Pacu', 'cell' => 100 * 0 + 201 * 256 + 105, 'x' => 105.0, 'y' => 201.0,
        'facing' => 'left', 'navType' => 'Swim', 'moving' => false, 'speed' => 1.0, 'path' => [],
        'aquatic' => true, 'tame' => true, 'baby' => false,
        'age' => ['value' => 12.4, 'max' => 25], 'calories' => ['value' => 720, 'max' => 1000],
        'fertility' => ['value' => 64, 'max' => 100], 'breeding' => [['egg' => 'PacuEgg', 'weight' => 0.94]],
        'happiness' => ['value' => 3, 'state' => 'happy'], 'stomach' => [['food' => 'Algae', 'calories' => 1200]],
        'nextPoop' => 'ToxicSand', 'hungry' => false,
        'statuses' => [['id' => 'Hungry', 'bad' => true]], 'effects' => [],
        'regionId' => 'pond:1201',
    ]];
    $fast['crowding'] = [[
        'id' => 'pond:1201', 'kind' => 'pond', 'cellCount' => 104, 'occupied' => 88, 'eggOccupied' => 24, 'counts' => ['Pacu' => 11],
        'states' => ['Pacu' => ['overcrowded' => false, 'cramped' => true, 'confined' => false, 'modifier' => 0]],
    ]];
    $this->zone->update(['fast_state' => $fast]);

    OniServer::tool(EntityTool::class, ['instanceId' => '1234'])
        ->assertOk()
        ->assertSee('critter')
        ->assertSee('(Pacu) #1234')
        ->assertSee('region pond:1201')
        ->assertSee('cramped')
        ->assertSee('Looking for Food')
        ->assertSee('fertility 64 %')
        ->assertSee('PacuEgg 94 %')
        ->assertSee('zones: #'.$this->zone->id.' Ferme sodicane');
});

it('describes an egg by instance id', function (): void {
    $slow = $this->zone->slow_state;
    $slow['eggs'] = [[
        'instanceId' => '5678', 'prefabId' => 'PacuEgg', 'cell' => 201 * 256 + 105, 'incubation' => 38.0, 'viability' => 100.0,
        'tame' => true, 'spawns' => 'PacuBaby', 'location' => 'floor', 'holderId' => null, 'regionId' => 'pond:1201',
    ]];
    $this->zone->update(['slow_state' => $slow]);

    OniServer::tool(EntityTool::class, ['instanceId' => '5678'])
        ->assertOk()
        ->assertSee('egg')
        ->assertSee('(PacuEgg) #5678')
        ->assertSee('incubation 38 %')
        ->assertSee('viability 100 %')
        ->assertSee('PacuBaby')
        ->assertSee('region pond:1201');
});

it('gives only the confined flag of a confined critter', function (): void {
    $fast = $this->zone->fast_state;
    $fast['critters'] = [[
        'instanceId' => '1234', 'prefabId' => 'Pacu', 'cell' => 201 * 256 + 105, 'x' => 105.0, 'y' => 201.0, 'regionId' => 'pond:1201',
    ]];
    $fast['crowding'] = [[
        'id' => 'pond:1201', 'kind' => 'pond', 'cellCount' => 4, 'occupied' => 8, 'eggOccupied' => 4, 'counts' => ['Pacu' => 1],
        'states' => ['Pacu' => ['overcrowded' => true, 'cramped' => true, 'confined' => true, 'modifier' => -1]],
    ]];
    $this->zone->update(['fast_state' => $fast]);

    OniServer::tool(EntityTool::class, ['instanceId' => '1234'])
        ->assertOk()
        ->assertSee('1 Pacu, confined)')
        ->assertDontSee('overcrowded')
        ->assertDontSee('cramped');
});

it('prints a feeder filter and capacity', function (): void {
    $fast = $this->zone->fast_state;
    $fast['buildings'] = [['instanceId' => '9001', 'prefabId' => 'CreatureFeeder', 'operational' => true, 'settings' => [
        'filter' => ['Algae', 'Kelp'],
        'capacity' => ['limit' => 500.0, 'max' => 2000.0, 'stored' => 340.0, 'unit' => 'kg'],
    ]]];
    $this->zone->update(['fast_state' => $fast]);

    OniServer::tool(EntityTool::class, ['instanceId' => '9001'])
        ->assertOk()
        ->assertSee('filter: Algae, Kelp; capacity 340/500 kg (max 2000)')
        ->assertDontSee('settings:');
});

it('prints nothing ticked', function (): void {
    $fast = $this->zone->fast_state;
    $fast['buildings'] = [['instanceId' => '9001', 'prefabId' => 'CreatureFeeder', 'operational' => true, 'settings' => ['filter' => [], 'capacity' => null]]];
    $this->zone->update(['fast_state' => $fast]);

    OniServer::tool(EntityTool::class, ['instanceId' => '9001'])
        ->assertOk()
        ->assertSee('filter: nothing ticked')
        ->assertDontSee('capacity');
});

it('finds an entity in a transient zone when no active zone holds it', function (): void {
    $this->zone->update(['fast_state' => null, 'layout' => null]);
    Zone::factory()->sodicaneFarm()->transient()->create(['colony_guid' => $this->zone->colony_guid, 'name' => 'Chat capture']);

    OniServer::tool(EntityTool::class, ['instanceId' => '7001'])
        ->assertOk()
        ->assertSee('(FarmStation) #7001')
        ->assertSee('Chat capture');
});
