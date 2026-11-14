<?php

declare(strict_types=1);

use App\Data\ZoneCritterData;
use App\Data\ZoneCrowdingRegionData;
use App\Data\ZoneEggData;
use App\Models\Critter;
use App\Models\CritterMorph;
use App\Services\Critters\OccupancyInputBuilder;

function builderMorph(string $morphId, int $space): void
{
    CritterMorph::factory()->for(Critter::factory())->create(['morph_id' => $morphId, 'overcrowding_threshold' => $space]);
}

function builderRegion(array $states = ['Pacu' => ['overcrowded' => false, 'cramped' => false, 'confined' => false, 'modifier' => 0]]): ZoneCrowdingRegionData
{
    return new ZoneCrowdingRegionData('pond:1', 'pond', 104, 16, 0, ['Pacu' => 2], $states);
}

function builderCritter(string $prefab, ?string $regionId = 'pond:1'): ZoneCritterData
{
    return ZoneCritterData::from([
        'instanceId' => '1', 'prefabId' => $prefab, 'name' => $prefab, 'sprite' => null, 'cell' => 1, 'x' => 0.0, 'y' => 0.0,
        'facing' => 'left', 'navType' => 'Swim', 'moving' => false, 'speed' => 0.0, 'path' => [], 'aquatic' => true,
        'tame' => true, 'baby' => false, 'ageCycles' => null, 'lifespanCycles' => null, 'caloriesRatio' => null,
        'fertilityRatio' => null, 'breeding' => [], 'happiness' => null, 'happinessState' => null, 'stomach' => [],
        'nextPoop' => null, 'hungry' => false, 'statuses' => [], 'regionId' => $regionId,
    ]);
}

function builderEgg(?string $spawns, ?string $regionId = 'pond:1'): ZoneEggData
{
    return new ZoneEggData('9', 'PacuEgg', 'Egg', null, 1, null, null, true, $spawns, 'floor', null, $regionId);
}

it('builds the input from the critters of the region only', function (): void {
    builderMorph('Pacu', 8);

    $input = (new OccupancyInputBuilder)->forRegion(builderRegion(), [builderCritter('Pacu'), builderCritter('Pacu', 'pond:2')], []);

    expect($input->cellCount)->toBe(104)
        ->and($input->isPond)->toBeTrue()
        ->and($input->critters)->toHaveCount(1)
        ->and($input->critters[0]->personalSpace)->toBe(8);
});

it('uses the hatched critter space for eggs', function (): void {
    builderMorph('Pacu', 8);
    builderMorph('PacuBaby', 4);

    $input = (new OccupancyInputBuilder)->forRegion(builderRegion(), [builderCritter('Pacu')], [builderEgg('PacuBaby'), builderEgg('PacuBaby', 'pond:2')]);

    expect($input->eggs)->toHaveCount(1)
        ->and($input->eggs[0]->personalSpace)->toBe(4);
});

it('falls back to the adult space when the baby has no morph', function (): void {
    builderMorph('Pacu', 8);

    $input = (new OccupancyInputBuilder)->forRegion(builderRegion(), [builderCritter('Pacu')], [builderEgg('PacuBaby')]);

    expect($input->eggs[0]->personalSpace)->toBe(8);
});

it('returns null and lists the unknown prefabs', function (): void {
    builderMorph('Pacu', 8);
    $builder = new OccupancyInputBuilder;

    $input = $builder->forRegion(builderRegion(), [builderCritter('Pacu'), builderCritter('Mystery')], [builderEgg('GhostBaby')]);

    expect($input)->toBeNull()
        ->and($builder->unknownPrefabs())->toEqualCanonicalizing(['Mystery', 'GhostBaby']);
});
