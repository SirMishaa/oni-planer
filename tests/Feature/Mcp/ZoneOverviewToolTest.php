<?php

declare(strict_types=1);

use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\ZoneOverviewTool;
use App\Models\Colony;
use App\Models\Critter;
use App\Models\CritterMorph;
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

it('labels critters matched by their cell as derived with an older mod', function (): void {
    $colony = Colony::factory()->create(['state' => ['critters' => [
        ['id' => 'Crab', 'worldId' => 0, 'cell' => 201 * 606 + 105, 'tame' => false],
    ]]]);
    $zone = Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);
    $fast = $zone->fast_state;
    unset($fast['critters'], $fast['crowding']);
    $zone->update(['fast_state' => $fast]);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'Sodicane'])
        ->assertOk()
        ->assertSee('Critters (derived from their cell):')
        ->assertSee('(Crab) @ w(105,201) wild age unavailable cal unavailable (derived)');
});

it('lists what each critter drops', function (): void {
    $colony = Colony::factory()->create(['state' => ['critters' => [
        ['id' => 'Crab', 'worldId' => 0, 'cell' => 201 * 606 + 105, 'tame' => true, 'drops' => [['id' => 'CrabShell', 'units' => 60]]],
    ]]]);
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'Sodicane'])->assertOk()->assertSee('drops CrabShell 60');
});

it('reads critters and eggs from the zone section without a derived label', function (): void {
    $colony = Colony::factory()->create();
    $zone = Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);
    $fast = $zone->fast_state;
    $fast['critters'] = [[
        'instanceId' => '1234', 'prefabId' => 'Pacu', 'cell' => 201 * 606 + 105, 'x' => 105.0, 'y' => 201.0, 'tame' => true,
        'age' => ['value' => 12.4, 'max' => 25], 'calories' => ['value' => 720, 'max' => 1000], 'hungry' => true,
    ]];
    $slow = $zone->slow_state;
    $slow['eggs'] = [['instanceId' => '5678', 'prefabId' => 'PacuEgg', 'cell' => 201 * 606 + 106, 'incubation' => 38.0, 'viability' => 100.0, 'tame' => true]];
    $zone->update(['fast_state' => $fast, 'slow_state' => $slow]);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'Sodicane'])
        ->assertOk()
        ->assertSee('(Pacu) #1234 @ w(105,201) tame age 12.4/25 cal 72 % hungry')
        ->assertSee('(PacuEgg) #5678 @ w(106,201) incubation 38 % viability 100 %')
        ->assertDontSee('(derived)')
        ->assertDontSee('Critters: unavailable');
});

it('prints the population per region', function (): void {
    CritterMorph::factory()->for(Critter::factory())->create(['morph_id' => 'Pacu', 'overcrowding_threshold' => 8]);
    $colony = Colony::factory()->create();
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Pond', 'fast_state' => [
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

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'Pond'])
        ->assertOk()
        ->assertSee('Population:')
        ->assertSee('pond:1201 88/104 · +24 eggs · cramped · remove 1 Pacu (derived)');
});

it('prints only the confined state of a confined region', function (): void {
    CritterMorph::factory()->for(Critter::factory())->create(['morph_id' => 'Pacu', 'overcrowding_threshold' => 8]);
    $colony = Colony::factory()->create();
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Pond', 'fast_state' => [
        'schema' => 1,
        'critters' => [],
        'crowding' => [[
            'id' => 'pond:1201', 'kind' => 'pond', 'cellCount' => 4, 'occupied' => 8, 'eggOccupied' => 4,
            'counts' => ['Pacu' => 1],
            'states' => ['Pacu' => ['overcrowded' => true, 'cramped' => true, 'confined' => true, 'modifier' => -1]],
        ]],
    ]]);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'Pond'])
        ->assertOk()
        ->assertSee('pond:1201 8/4 · +4 eggs · confined · remove')
        ->assertDontSee('overcrowded')
        ->assertDontSee('cramped');
});

it('prints no population when the game sent no crowding regions', function (): void {
    $colony = Colony::factory()->create();
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'Sodicane'])->assertOk()->assertSee('Population: unavailable');
});

it('lists the critter alerts among the problems', function (): void {
    $colony = Colony::factory()->create();
    Zone::factory()->create(['colony_guid' => $colony->guid, 'name' => 'Ranch', 'fast_state' => [
        'schema' => 1,
        'critters' => [[
            'instanceId' => '7', 'prefabId' => 'Hatch', 'cell' => 100, 'x' => 1.0, 'y' => 1.0,
            'statuses' => [['id' => 'Starving', 'bad' => true]], 'regionId' => null,
        ]],
    ]]);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'Ranch'])
        ->assertOk()
        ->assertSee('critter Hatch: ');
});

it('prints feeding per group', function (): void {
    $morph = CritterMorph::factory()->for(Critter::factory())->create(['morph_id' => 'Pacu', 'overcrowding_threshold' => 8]);
    App\Models\CritterMorphDiet::factory()->create(['critter_morph_id' => $morph->id, 'consumed_element_id' => 'Algae', 'amount_per_cycle' => 7.5, 'produced_element_id' => 'ToxicSand', 'conversion_ratio' => 0.5, 'food_kind' => 'item']);
    App\Models\CritterMorphOutput::factory()->create(['critter_morph_id' => $morph->id, 'element_id' => 'PacuEgg', 'amount_per_cycle' => 0.05, 'output_type' => 'egg']);
    $colony = Colony::factory()->create();
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Pond', 'fast_state' => [
        'schema' => 1,
        'critters' => array_map(fn (int $index): array => [
            'instanceId' => (string) (1200 + $index), 'prefabId' => 'Pacu', 'cell' => 100 + $index, 'x' => 1.0, 'y' => 1.0, 'tame' => true,
        ], range(1, 38)),
        'buildings' => [['instanceId' => '9001', 'prefabId' => 'CreatureFeeder', 'storage' => [['id' => 'Algae', 'mass' => 456.0]]]],
    ]]);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'Pond'])
        ->assertOk()
        ->assertSee('Feeding:')
        ->assertSee('Pacu:adult ×38 · eats Algae 285 kg/cycle · stock 456 kg · lasts 1.6 cycles (derived) · produces ToxicSand 142.5 kg/cycle, PacuEgg 1.9 eggs/cycle');
});

it('prints the deficit and the grazed plants of a feeding group', function (): void {
    $pacu = CritterMorph::factory()->for(Critter::factory())->create(['morph_id' => 'Pacu']);
    App\Models\CritterMorphDiet::factory()->create(['critter_morph_id' => $pacu->id, 'consumed_element_id' => 'Algae', 'amount_per_cycle' => 7.5, 'produced_element_id' => null, 'conversion_ratio' => null, 'food_kind' => 'item']);
    App\Models\CritterMorphDiet::factory()->create(['critter_morph_id' => $pacu->id, 'consumed_element_id' => 'SpiceVine', 'amount_per_cycle' => 0.5, 'produced_element_id' => null, 'conversion_ratio' => null, 'food_kind' => 'plant']);
    $drecko = CritterMorph::factory()->for(Critter::factory())->create(['morph_id' => 'Drecko']);
    App\Models\CritterMorphDiet::factory()->create(['critter_morph_id' => $drecko->id, 'consumed_element_id' => 'SpiceVine', 'amount_per_cycle' => 0.75, 'produced_element_id' => null, 'conversion_ratio' => null, 'food_kind' => 'plant']);
    $colony = Colony::factory()->create();
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Ranch', 'fast_state' => [
        'schema' => 1,
        'critters' => [
            ['instanceId' => '1', 'prefabId' => 'Pacu', 'cell' => 100, 'x' => 1.0, 'y' => 1.0, 'tame' => true],
            ['instanceId' => '2', 'prefabId' => 'Drecko', 'cell' => 100, 'x' => 1.0, 'y' => 1.0, 'tame' => true],
        ],
        'plants' => [['id' => 'SpiceVine', 'instanceId' => '4001']],
        'buildings' => [],
    ]]);

    OniServer::tool(ZoneOverviewTool::class, ['zone' => 'Ranch'])
        ->assertOk()
        ->assertSee('Pacu:adult ×1 · eats Algae 7.5 kg/cycle · or grazes SpiceVine (1 in the zone) · stock 0 kg · missing 7.5 kg/cycle (derived)')
        ->assertSee('Drecko:adult ×1 · grazes SpiceVine (1 in the zone)');
});
