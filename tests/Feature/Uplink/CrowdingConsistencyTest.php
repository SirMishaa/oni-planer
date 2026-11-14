<?php

declare(strict_types=1);

use App\Actions\Uplink\StoreZoneState;
use App\Events\Uplink\ZoneStateStored;
use App\Listeners\Uplink\CheckCrowdingConsistency;
use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\Zone;
use Illuminate\Support\Facades\Log;

/**
 * A pond of 104 cells with 11 Pacu of 8 cells each (88 occupied), as the mod sends it.
 *
 * @param  array<string, mixed>  $region
 * @param  list<array<string, mixed>>  $slowEggs
 */
function crowdedZone(array $region = [], array $slowEggs = [], string $prefab = 'Pacu'): Zone
{
    $critter = fn (int $index): array => [
        'instanceId' => (string) (1200 + $index), 'prefabId' => $prefab, 'cell' => 100 + $index, 'x' => 1.0, 'y' => 1.0,
        'aquatic' => true, 'regionId' => 'pond:1201',
    ];

    return Zone::factory()->create([
        'colony_guid' => 'colony-1',
        'zone_guid' => 'zone-a',
        'name' => 'Pond',
        'fast_state' => [
            'schema' => 1,
            'critters' => array_map($critter, range(1, 11)),
            'crowding' => [[
                'id' => 'pond:1201', 'kind' => 'pond', 'cellCount' => 104, 'occupied' => 88, 'eggOccupied' => 24,
                'counts' => [$prefab => 11],
                'states' => [$prefab => ['overcrowded' => false, 'cramped' => true, 'confined' => false, 'modifier' => 0]],
                ...$region,
            ]],
        ],
        'slow_state' => ['schema' => 1, 'eggs' => $slowEggs],
    ]);
}

function checkCrowding(string $kind = 'fast'): void
{
    app(CheckCrowdingConsistency::class)->handle(new ZoneStateStored('colony-1', 'zone-a', $kind, 10.0));
}

beforeEach(function (): void {
    CritterMorph::factory()->for(Critter::factory())->create(['morph_id' => 'Pacu', 'overcrowding_threshold' => 8]);
});

it('records nothing when the planner agrees with the game', function (): void {
    $zone = crowdedZone();

    checkCrowding();

    expect($zone->fresh()->crowding_mismatches)->toBeNull();
});

it('records a mismatch when occupied differs', function (): void {
    Log::spy();
    $zone = crowdedZone(['occupied' => 96]);

    checkCrowding();

    $mismatches = $zone->fresh()->crowding_mismatches;
    expect($mismatches)->toHaveCount(1)
        ->and($mismatches[0])->toMatchArray(['regionId' => 'pond:1201', 'prefabId' => '', 'field' => 'occupied', 'game' => 96, 'planner' => 88]);
    Log::shouldHaveReceived('warning')->with('crowding mismatch', Mockery::type('array'))->once();
});

it('records a per prefab mismatch and clears it once the planner agrees', function (): void {
    $zone = crowdedZone(['states' => ['Pacu' => ['overcrowded' => true, 'cramped' => true, 'confined' => false, 'modifier' => 0]]]);

    checkCrowding();

    expect($zone->fresh()->crowding_mismatches)->toHaveCount(1)
        ->and($zone->fresh()->crowding_mismatches[0])->toMatchArray(['prefabId' => 'Pacu', 'field' => 'overcrowded', 'game' => true, 'planner' => false]);

    $zone->update(['fast_state' => [...$zone->fast_state, 'crowding' => [[...$zone->fast_state['crowding'][0], 'states' => ['Pacu' => ['overcrowded' => false, 'cramped' => true, 'confined' => false, 'modifier' => 0]]]]]]);
    checkCrowding();

    expect($zone->fresh()->crowding_mismatches)->toBeNull();
});

it('does not flag eggs that lag behind the fast state', function (): void {
    $zone = crowdedZone(slowEggs: [[
        'instanceId' => '5678', 'prefabId' => 'PacuEgg', 'cell' => 133900, 'spawns' => 'Pacu', 'regionId' => 'pond:1201',
    ]]);

    checkCrowding();

    expect($zone->fresh()->crowding_mismatches)->toBeNull();
});

it('skips a region with an unknown prefab and logs it', function (): void {
    Log::spy();
    $zone = crowdedZone(prefab: 'Mystery');

    checkCrowding();

    expect($zone->fresh()->crowding_mismatches)->toBeNull();
    Log::shouldHaveReceived('warning')->with('crowding check skipped, unknown prefab', Mockery::on(
        fn (array $context): bool => $context['prefabs'] === ['Mystery'] && $context['regionId'] === 'pond:1201',
    ))->once();
});

it('ignores slow states', function (): void {
    $zone = crowdedZone(['occupied' => 96]);

    checkCrowding('slow');

    expect($zone->fresh()->crowding_mismatches)->toBeNull();
});

it('runs when a fast state is stored', function (): void {
    $zone = crowdedZone(['occupied' => 96]);

    app(StoreZoneState::class)->handle('fast', ['schema' => 1, 'colonyId' => 'colony-1', 'zoneId' => 'zone-a', 'gameSeconds' => 20.0, ...$zone->fast_state]);

    expect($zone->fresh()->crowding_mismatches)->toHaveCount(1);
});
