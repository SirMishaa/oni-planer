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
