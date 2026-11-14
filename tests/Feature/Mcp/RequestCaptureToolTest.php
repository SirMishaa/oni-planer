<?php

declare(strict_types=1);

use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\RequestCaptureTool;
use App\Models\Colony;
use App\Models\UplinkCommand;
use App\Models\Zone;

it('asks the mod for a capture of the zone', function (): void {
    $colony = Colony::factory()->create(['last_seen_at' => now()->subSeconds(5)]);
    Zone::factory()->create(['colony_guid' => $colony->guid, 'zone_guid' => 'zone-a', 'name' => 'Bathroom']);

    $response = OniServer::tool(RequestCaptureTool::class, ['zone' => 'Bathroom']);

    $command = UplinkCommand::query()->sole();
    expect($command->type)->toBe('zone.capture')
        ->and($command->colony_guid)->toBe($colony->guid)
        ->and($command->payload)->toBe(['zoneId' => 'zone-a']);
    $response->assertOk()->assertSee($command->id)->assertSee('pending')->assertDontSee('looks offline');
});

it('warns when the game looks offline but still sends the command', function (): void {
    $colony = Colony::factory()->create(['last_seen_at' => now()->subMinutes(2)]);
    Zone::factory()->create(['colony_guid' => $colony->guid, 'name' => 'Bathroom']);

    OniServer::tool(RequestCaptureTool::class, ['zone' => 'Bathroom'])->assertOk()->assertSee('looks offline');

    expect(UplinkCommand::query()->count())->toBe(1);
});

it('does not capture an archived zone', function (): void {
    $colony = Colony::factory()->create();
    Zone::factory()->create(['colony_guid' => $colony->guid, 'name' => 'Bathroom', 'archived_at' => now()]);

    OniServer::tool(RequestCaptureTool::class, ['zone' => 'Bathroom'])->assertHasErrors();

    expect(UplinkCommand::query()->count())->toBe(0);
});
