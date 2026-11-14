<?php

declare(strict_types=1);

use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\ColonyStatusTool;
use App\Models\Colony;
use App\Models\UplinkCommand;
use App\Models\Zone;

it('reports sync health and a colony summary', function (): void {
    $colony = Colony::factory()->create(['name' => 'Nova', 'cycle' => 59, 'last_seen_at' => now()->subSeconds(4), 'state' => [
        'schema' => 1,
        'duplicants' => [[], [], []],
        'critters' => [['id' => 'Crab', 'worldId' => 0, 'cell' => 1], ['id' => 'Crab', 'worldId' => 0, 'cell' => 2]],
        'plants' => [['id' => 'SaltySticksPlant', 'worldId' => 0, 'cell' => 3]],
        'food' => [['id' => 'MushBar', 'worldId' => 0, 'units' => 4.0, 'calories' => 3_200_000.0]],
    ]]);
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);
    UplinkCommand::factory()->create(['colony_guid' => $colony->guid, 'status' => UplinkCommand::PENDING, 'expires_at' => now()->addMinute()]);

    OniServer::tool(ColonyStatusTool::class)
        ->assertOk()
        ->assertSee('Colony "Nova" cycle 59')
        ->assertSee('seen 4s ago')
        ->assertSee('Sodicane')
        ->assertSee('duplicants 3')
        ->assertSee('(Crab) 2')
        ->assertSee('(SaltySticksPlant) 1')
        ->assertSee('3200 kcal')
        ->assertSee('commands pending 1');
});

it('flags a colony the game stopped sending', function (): void {
    Colony::factory()->create(['last_seen_at' => now()->subMinutes(5)]);

    OniServer::tool(ColonyStatusTool::class)->assertOk()->assertSee('STALE');
});

it('says when no colony was synced', function (): void {
    OniServer::tool(ColonyStatusTool::class)->assertHasErrors(['No colony synced yet']);
});
