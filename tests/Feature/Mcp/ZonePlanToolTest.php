<?php

declare(strict_types=1);

use App\Enums\ZonePlanChangeState;
use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\ZonePlanTool;
use App\Models\Colony;
use App\Models\Zone;
use App\Models\ZonePlan;
use App\Models\ZonePlanChange;

it('lists the changes of a plan with their state and who moved them', function (): void {
    $colony = Colony::factory()->create();
    $zone = Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);
    $plan = ZonePlan::factory()->for($zone)->create();
    $add = ZonePlanChange::factory()->add()->for($plan, 'plan')->create([
        'prefab_id' => 'GasPump', 'anchor_cell' => 201 * 606 + 116, 'orientation' => 'R90',
        'state' => ZonePlanChangeState::Applied, 'moved_by_player' => true, 'note' => 'Pump',
    ]);

    OniServer::tool(ZonePlanTool::class, ['zone' => 'Sodicane'])
        ->assertOk()
        ->assertSee('Plan of zone #'.$zone->id.' "Sodicane" · 1 change')
        ->assertSee(sprintf('#%d add GasPump @ w(116,201) R90 · applied · checked · moved by player · "Pump"', $add->id));
});

it('says when a zone has no plan', function (): void {
    $colony = Colony::factory()->create();
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);

    OniServer::tool(ZonePlanTool::class, ['zone' => 'Sodicane'])->assertOk()->assertSee('No plan for zone');
});
