<?php

declare(strict_types=1);

use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\ColonyStatusTool;
use App\Mcp\Tools\EntityTool;
use App\Mcp\Tools\ListZonesTool;
use App\Mcp\Tools\RequestCaptureTool;
use App\Mcp\Tools\ZoneCapturesTool;
use App\Mcp\Tools\ZoneCellsTool;
use App\Mcp\Tools\ZoneMapTool;
use App\Mcp\Tools\ZoneOverviewTool;
use App\Models\Colony;
use App\Models\Zone;

dataset('colony tools', [
    'colony_status' => [ColonyStatusTool::class, []],
    'list_zones' => [ListZonesTool::class, []],
    'zone_overview' => [ZoneOverviewTool::class, ['zone' => '1']],
    'zone_map' => [ZoneMapTool::class, ['zone' => '1']],
    'zone_cells' => [ZoneCellsTool::class, ['zone' => '1', 'rect' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]]],
    'entity' => [EntityTool::class, ['instanceId' => '7001']],
    'zone_captures' => [ZoneCapturesTool::class, ['zone' => '1']],
    'request_capture' => [RequestCaptureTool::class, ['zone' => '1']],
]);

it('says no colony was synced yet', function (string $tool, array $arguments): void {
    OniServer::tool($tool, $arguments)->assertHasErrors(['No colony synced yet']);
})->with('colony tools');

it('names an unknown colony guid instead of claiming nothing was synced', function (): void {
    Colony::factory()->create();

    OniServer::tool(ColonyStatusTool::class, ['colony' => 'not-a-guid'])
        ->assertHasErrors(['No colony with guid "not-a-guid"']);
});

it('rejects a rect missing its keys with a readable error', function (): void {
    $colony = Colony::factory()->create();
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);

    OniServer::tool(ZoneMapTool::class, ['zone' => 'Sodicane', 'rect' => []])
        ->assertHasErrors(['rect']);
});

it('treats a layout box missing keys as an unavailable layout', function (string $tool, array $arguments): void {
    $colony = Colony::factory()->create();
    $zone = Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);
    $zone->update(['layout' => [...$zone->layout, 'box' => ['x' => 99]]]);

    OniServer::tool($tool, ['zone' => 'Sodicane', ...$arguments])->assertOk()->assertSee('layout unavailable');
})->with([
    'zone_map' => [ZoneMapTool::class, []],
    'zone_cells' => [ZoneCellsTool::class, ['rect' => ['x' => 99, 'y' => 200, 'width' => 1, 'height' => 1]]],
]);

it('reads malformed fast-state entries without failing', function (): void {
    $colony = Colony::factory()->create(['state' => ['critters' => ['oops', ['id' => 'Crab', 'cell' => 'x']]]]);
    $zone = Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);
    $zone->update(['fast_state' => [
        'plants' => ['oops', ['id' => 'SaltySticksPlant', 'cell' => 'nope']],
        'buildings' => [42, ['prefabId' => 'FarmStation', 'statusItems' => 'nope', 'storage' => 'nope']],
    ]]);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'Sodicane'])->assertOk();
    OniServer::tool(ZoneMapTool::class, ['zone' => 'Sodicane'])->assertOk();
    OniServer::tool(EntityTool::class, ['instanceId' => '7001'])->assertOk();
});
