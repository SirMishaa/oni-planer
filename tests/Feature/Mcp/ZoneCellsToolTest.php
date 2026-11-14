<?php

declare(strict_types=1);

use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\ZoneCellsTool;
use App\Models\Colony;
use App\Models\Zone;

beforeEach(function (): void {
    $colony = Colony::factory()->create();
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);
});

it('details each cell of a small rect', function (): void {
    OniServer::tool(ZoneCellsTool::class, ['zone' => 'Sodicane', 'rect' => ['x' => 114, 'y' => 200, 'width' => 2, 'height' => 2]])
        ->assertOk()
        ->assertSee('w(114,201) Oxygen 1.5 kg 22.0 °C FarmStation#7001 zone')
        ->assertSee('w(115,201) Oxygen 1.5 kg 22.0 °C FarmStation#7001 zone')
        ->assertSee('w(114,200) Dirt 1.5 kg 22.0 °C tile Tile border');
});

it('refuses a rect over 100 cells', function (): void {
    OniServer::tool(ZoneCellsTool::class, ['zone' => 'Sodicane', 'rect' => ['x' => 99, 'y' => 200, 'width' => 20, 'height' => 10]])
        ->assertHasErrors(['100']);
});

it('requires a rect', function (): void {
    OniServer::tool(ZoneCellsTool::class, ['zone' => 'Sodicane'])->assertHasErrors();
});
