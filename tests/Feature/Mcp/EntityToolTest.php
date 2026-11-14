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

it('says critters have no id yet when nothing matches', function (): void {
    OniServer::tool(EntityTool::class, ['instanceId' => 'nope'])->assertHasErrors(['critters have no id yet']);
});

it('does not claim no problems for a building known only from the layout', function (): void {
    $this->zone->update(['fast_state' => null]);

    OniServer::tool(EntityTool::class, ['instanceId' => '7001'])
        ->assertOk()
        ->assertSee('problems: unavailable')
        ->assertDontSee('problems: none');
});
