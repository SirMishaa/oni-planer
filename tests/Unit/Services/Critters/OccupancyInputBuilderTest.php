<?php

declare(strict_types=1);

use App\Data\ZoneCrowdingRegionData;
use App\Data\ZoneEggData;
use App\Models\Critter;
use App\Models\CritterMorph;
use App\Services\Critters\OccupancyInputBuilder;

function builderMorph(string $morphId, int $space): void
{
    CritterMorph::factory()->for(Critter::factory())->create(['morph_id' => $morphId, 'overcrowding_threshold' => $space]);
}

/**
 * @param  array<string, int>  $counts
 * @param  array<string, array{overcrowded: bool, cramped: bool, confined: bool, modifier: int}>|null  $states
 */
function builderRegion(array $counts = ['Pacu' => 2], ?array $states = null, string $kind = 'pond'): ZoneCrowdingRegionData
{
    $states ??= array_map(fn (): array => ['overcrowded' => false, 'cramped' => false, 'confined' => false, 'modifier' => 0], $counts);

    return new ZoneCrowdingRegionData('pond:1', $kind, 104, 16, 0, $counts, $states);
}

function builderEgg(?string $spawns, ?string $regionId = 'pond:1'): ZoneEggData
{
    return new ZoneEggData('9', 'PacuEgg', 'Egg', null, 1, null, null, true, $spawns, 'floor', null, $regionId);
}

it('builds the critters from the counts of the whole region, not only those in the zone', function (): void {
    builderMorph('Pacu', 8);

    $input = (new OccupancyInputBuilder)->forRegion(builderRegion(['Pacu' => 20]), []);

    expect($input->cellCount)->toBe(104)
        ->and($input->isPond)->toBeTrue()
        ->and($input->critters)->toHaveCount(20)
        ->and($input->critters[0]->personalSpace)->toBe(8)
        ->and($input->critters[0]->aquatic)->toBeTrue();
});

it('marks the critters of a cavity as land critters', function (): void {
    builderMorph('Hatch', 12);

    $input = (new OccupancyInputBuilder)->forRegion(builderRegion(['Hatch' => 3], kind: 'cavity'), []);

    expect($input->isPond)->toBeFalse()
        ->and($input->critters)->toHaveCount(3)
        ->and($input->critters[0]->aquatic)->toBeFalse();
});

it('uses the hatched critter space for the eggs of the region only', function (): void {
    builderMorph('Pacu', 8);
    builderMorph('PacuBaby', 4);

    $input = (new OccupancyInputBuilder)->forRegion(builderRegion(), [builderEgg('PacuBaby'), builderEgg('PacuBaby', 'pond:2')]);

    expect($input->eggs)->toHaveCount(1)
        ->and($input->eggs[0]->personalSpace)->toBe(4);
});

it('falls back to the adult space when the baby has no morph', function (): void {
    builderMorph('Pacu', 8);

    $input = (new OccupancyInputBuilder)->forRegion(builderRegion(), [builderEgg('PacuBaby')]);

    expect($input->eggs[0]->personalSpace)->toBe(8);
});

it('returns null and lists the unknown prefabs of the counts, the states and the eggs', function (): void {
    builderMorph('Pacu', 8);
    $builder = new OccupancyInputBuilder;

    $input = $builder->forRegion(
        builderRegion(['Pacu' => 2, 'Mystery' => 1], [
            'Pacu' => ['overcrowded' => false, 'cramped' => false, 'confined' => false, 'modifier' => 0],
            'Shadow' => ['overcrowded' => false, 'cramped' => false, 'confined' => false, 'modifier' => 0],
        ]),
        [builderEgg('GhostBaby')],
    );

    expect($input)->toBeNull()
        ->and($builder->unknownPrefabs())->toEqualCanonicalizing(['Mystery', 'Shadow', 'GhostBaby']);
});
