<?php

declare(strict_types=1);

use App\Data\ZoneCritterData;
use App\Services\Critters\CritterGroupKey;

/** @param array<string, mixed> $overrides */
function keyedCritter(array $overrides = []): ZoneCritterData
{
    return ZoneCritterData::from([
        'instanceId' => '1', 'prefabId' => 'Pacu', 'name' => 'Pacu', 'sprite' => null, 'cell' => 1, 'x' => 0.0, 'y' => 0.0,
        'facing' => 'left', 'navType' => 'Swim', 'moving' => false, 'speed' => 0.0, 'path' => [], 'aquatic' => true,
        'tame' => true, 'baby' => false, 'ageCycles' => null, 'lifespanCycles' => null, 'caloriesRatio' => null,
        'fertilityRatio' => null, 'breeding' => [], 'happiness' => null, 'happinessState' => null, 'stomach' => [],
        'nextPoop' => null, 'hungry' => false, 'statuses' => [], 'regionId' => null,
        ...$overrides,
    ]);
}

it('keys a tame adult by its prefab and the adult stage', function (): void {
    expect(CritterGroupKey::of(keyedCritter()))->toBe('Pacu:adult');
});

it('keys a wild adult as wild', function (): void {
    expect(CritterGroupKey::of(keyedCritter(['tame' => false])))->toBe('Pacu:wild');
});

it('keys a baby as baby whether tame or not', function (): void {
    expect(CritterGroupKey::of(keyedCritter(['baby' => true])))->toBe('Pacu:baby')
        ->and(CritterGroupKey::of(keyedCritter(['baby' => true, 'tame' => false])))->toBe('Pacu:baby');
});
