<?php

declare(strict_types=1);

use App\Exceptions\McpLookupException;
use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\ListZonesTool;
use App\Models\Colony;
use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\Zone;
use App\Services\Mcp\McpZones;

it('lists active zones with size, age and problems', function (): void {
    $colony = Colony::factory()->create();
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'PlantFarm/Sadicane']);
    Zone::factory()->create(['colony_guid' => $colony->guid, 'name' => 'Old room', 'archived_at' => now()]);

    OniServer::tool(ListZonesTool::class)
        ->assertOk()
        ->assertSee('PlantFarm/Sadicane')
        ->assertSee('40 cells')
        ->assertSee('box 22x4 @ w(99,200)')
        ->assertSee('problems 1')
        ->assertDontSee('Old room');
});

it('resolves a zone by id, exact name and unique fragment', function (): void {
    $colony = Colony::factory()->create();
    $zone = Zone::factory()->create(['colony_guid' => $colony->guid, 'name' => 'Bathroom']);
    Zone::factory()->create(['colony_guid' => $colony->guid, 'name' => 'Bathroom annex']);

    expect(McpZones::resolve($colony, (string) $zone->id)->is($zone))->toBeTrue()
        ->and(McpZones::resolve($colony, 'bathroom')->is($zone))->toBeTrue()
        ->and(McpZones::resolve($colony, 'annex')->name)->toBe('Bathroom annex');
});

it('refuses an ambiguous zone name and lists the candidates', function (): void {
    $colony = Colony::factory()->create();
    Zone::factory()->create(['colony_guid' => $colony->guid, 'name' => 'AquaFarm/PacuOne']);
    Zone::factory()->create(['colony_guid' => $colony->guid, 'name' => 'AquaFarm/PacuTwo']);

    expect(fn () => McpZones::resolve($colony, 'aquafarm'))
        ->toThrow(McpLookupException::class, 'AquaFarm/PacuOne');
});

it('does not resolve a zone of another colony', function (): void {
    $colony = Colony::factory()->create();
    $other = Zone::factory()->create(['colony_guid' => 'another-colony']);

    expect(fn () => McpZones::resolve($colony, (string) $other->id))->toThrow(McpLookupException::class);
});

it('refuses an empty zone instead of matching every name', function (): void {
    $colony = Colony::factory()->create();
    Zone::factory()->create(['colony_guid' => $colony->guid, 'name' => 'Only zone']);

    expect(fn () => McpZones::resolve($colony, '  '))->toThrow(McpLookupException::class, 'Name a zone');
});

it('counts critter problems in the zone list summary', function (): void {
    CritterMorph::factory()->for(Critter::factory())->create(['morph_id' => 'Pacu', 'overcrowding_threshold' => 8]);
    $colony = Colony::factory()->create();
    Zone::factory()->create(['colony_guid' => $colony->guid, 'name' => 'Pacu pond', 'fast_state' => [
        'schema' => 1,
        'critters' => array_map(fn (int $index): array => [
            'instanceId' => (string) (1200 + $index), 'prefabId' => 'Pacu', 'cell' => 100 + $index, 'x' => 1.0, 'y' => 1.0,
            'aquatic' => true, 'regionId' => 'pond:1201',
        ], range(1, 11)),
        'crowding' => [[
            'id' => 'pond:1201', 'kind' => 'pond', 'cellCount' => 104, 'occupied' => 88, 'eggOccupied' => 24,
            'counts' => ['Pacu' => 11],
            'states' => ['Pacu' => ['overcrowded' => false, 'cramped' => true, 'confined' => false, 'modifier' => 0]],
        ]],
    ]]);

    OniServer::tool(ListZonesTool::class)
        ->assertOk()
        ->assertSee('Pacu pond')
        ->assertSee('problems 1');
});
