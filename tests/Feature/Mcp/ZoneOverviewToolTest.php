<?php

declare(strict_types=1);

use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\ZoneOverviewTool;
use App\Models\Colony;
use App\Models\Zone;

it('summarises a zone in one screen', function (): void {
    $colony = Colony::factory()->create(['state' => ['critters' => [
        ['id' => 'Crab', 'worldId' => 0, 'cell' => 201 * 606 + 105, 'tame' => true, 'age' => ['value' => 12.5, 'max' => 100], 'calories' => ['value' => 500, 'max' => 1000]],
    ]]]);
    $zone = Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'PlantFarm/Sadicane']);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'sadicane'])
        ->assertOk()
        ->assertSee('#'.$zone->id.' PlantFarm/Sadicane')
        ->assertSee('(FarmStation) ×1')
        ->assertSee('Insufficient Resources: Fertilizer')
        ->assertSee('Fertilizer 0 kg')
        ->assertSee('(SaltySticksPlant) ×13')
        ->assertSee('ready 1')
        ->assertSee('Oxygen')
        ->assertSee('Dirt')
        ->assertSee('22.0 °C')
        ->assertSee('(Crab) @ w(105,201) tame age 12.5/100 cal 500/1000 (derived)')
        ->assertSee(route('zones.show', $zone));
});

it('prints unavailable sections for a zone never synced', function (): void {
    $colony = Colony::factory()->create();
    Zone::factory()->create(['colony_guid' => $colony->guid, 'name' => 'Fresh']);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'Fresh'])
        ->assertOk()
        ->assertSee('layout unavailable')
        ->assertSee('slow state unavailable')
        ->assertSee('fast state unavailable')
        ->assertSee('Problems: unavailable')
        ->assertDontSee('Problems: none');
});

it('names the candidates when the zone is ambiguous', function (): void {
    $colony = Colony::factory()->create();
    Zone::factory()->create(['colony_guid' => $colony->guid, 'name' => 'AquaFarm/PacuOne']);
    Zone::factory()->create(['colony_guid' => $colony->guid, 'name' => 'AquaFarm/PacuTwo']);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'aquafarm'])->assertHasErrors(['AquaFarm/PacuTwo']);
});

it('lists what each critter drops', function (): void {
    $colony = Colony::factory()->create(['state' => ['critters' => [
        ['id' => 'Crab', 'worldId' => 0, 'cell' => 201 * 606 + 105, 'tame' => true, 'drops' => [['id' => 'CrabShell', 'units' => 60]]],
    ]]]);
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'Sodicane'])->assertOk()->assertSee('drops CrabShell 60');
});
