<?php

declare(strict_types=1);

use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\ZoneMapTool;
use App\Models\Colony;
use App\Models\Zone;

it('draws the map of a zone with a rect and one layer', function (): void {
    $colony = Colony::factory()->create();
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);

    OniServer::tool(ZoneMapTool::class, ['zone' => 'Sodicane', 'rect' => ['x' => 113, 'y' => 200, 'width' => 4, 'height' => 3], 'layers' => ['occupancy']])
        ->assertOk()
        ->assertSee('origin w(113,200) · 4x3')
        ->assertSee('O  1 |.FF.|')
        ->assertDontSee('T  0 |');
});

it('rejects an unknown layer', function (): void {
    $colony = Colony::factory()->create();
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);

    OniServer::tool(ZoneMapTool::class, ['zone' => 'Sodicane', 'layers' => ['power']])->assertHasErrors();
});

it('draws critters and eggs read from the zone with their instance ids and no derived label', function (): void {
    $colony = Colony::factory()->create();
    $zone = Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);
    $fast = $zone->fast_state;
    $fast['critters'] = [['instanceId' => '1234', 'prefabId' => 'Pacu', 'cell' => 201 * 606 + 105, 'x' => 105.0, 'y' => 201.0, 'tame' => true]];
    $slow = $zone->slow_state;
    $slow['eggs'] = [['instanceId' => '5678', 'prefabId' => 'PacuEgg', 'cell' => 201 * 606 + 106, 'incubation' => 38.0, 'viability' => 100.0, 'tame' => true]];
    $zone->update(['fast_state' => $fast, 'slow_state' => $slow]);

    OniServer::tool(ZoneMapTool::class, ['zone' => 'Sodicane', 'layers' => ['occupancy']])
        ->assertOk()
        ->assertSee('critter Pacu#1234 @ w(105,201) tame')
        ->assertSee('egg PacuEgg#5678 @ w(106,201)')
        ->assertDontSee('(derived)');
});

it('keeps the derived label on critters matched from the colony snapshot', function (): void {
    $colony = Colony::factory()->create(['state' => ['critters' => [['id' => 'Crab', 'worldId' => 0, 'cell' => 201 * 606 + 105, 'tame' => true]]]]);
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);

    OniServer::tool(ZoneMapTool::class, ['zone' => 'Sodicane', 'layers' => ['occupancy']])
        ->assertOk()
        ->assertSee('critter Crab @ w(105,201) tame (derived)');
});
