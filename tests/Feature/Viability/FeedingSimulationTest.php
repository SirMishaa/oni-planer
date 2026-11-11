<?php

declare(strict_types=1);

use App\Services\Viability\FeedingSimulation;

it('counts cycles with no harvest', function (): void {
    $r = FeedingSimulation::run(['Lettuce' => 4000.0, 'FishMeat' => 3000.0], [
        ['id' => 'd1', 'name' => 'Ruby', 'needKcal' => 1000.0, 'forbidden' => []],
        ['id' => 'd2', 'name' => 'Ellie', 'needKcal' => 1000.0, 'forbidden' => ['FishMeat']],
    ], []);

    // Ellie eats lettuce only; Ruby takes fish first (fewest eaters): 3 cycles of fish then shares lettuce.
    expect($r['autonomy'])->toBe(3)
        ->and($r['firstShort'])->toBe('Ellie')
        ->and($r['stockByCycle'][0])->toBe(7000.0);
});

it('is zero when a duplicant may eat nothing in stock', function (): void {
    $r = FeedingSimulation::run(['FishMeat' => 9000.0], [['id' => 'd2', 'name' => 'Ellie', 'needKcal' => 1000.0, 'forbidden' => ['FishMeat']]], []);

    expect($r['autonomy'])->toBe(0)->and($r['firstShort'])->toBe('Ellie');
});

it('returns null beyond the horizon', function (): void {
    $r = FeedingSimulation::run(['Lettuce' => 60000.0], [['id' => 'd1', 'name' => 'Ruby', 'needKcal' => 1000.0, 'forbidden' => []]], []);

    expect($r['autonomy'])->toBeNull()
        ->and($r['firstShort'])->toBeNull()
        ->and($r['stockByCycle'])->toHaveCount(51)
        ->and($r['stockByCycle'][50])->toBe(10000.0);
});

it('adds the arrivals of a cycle after its meals', function (): void {
    $r = FeedingSimulation::run(['Lettuce' => 1000.0], [['id' => 'd1', 'name' => 'Ruby', 'needKcal' => 1000.0, 'forbidden' => []]],
        [['offset' => 0.5, 'cropId' => 'Lettuce', 'kcal' => 2000.0]]);

    expect($r['autonomy'])->toBe(3)
        ->and(array_slice($r['stockByCycle'], 0, 6))->toBe([1000.0, 2000.0, 1000.0, 0.0, 0.0, null]);
});

it('does not feed a cycle with a harvest landing during it', function (): void {
    $r = FeedingSimulation::run(['Lettuce' => 1000.0], [['id' => 'd1', 'name' => 'Ruby', 'needKcal' => 1000.0, 'forbidden' => []]],
        [['offset' => 1.5, 'cropId' => 'Lettuce', 'kcal' => 2000.0]]);

    expect($r['autonomy'])->toBe(1)
        ->and($r['firstShort'])->toBe('Ruby')
        ->and(array_slice($r['stockByCycle'], 0, 3))->toBe([1000.0, 0.0, 0.0]);
});

it('feeds the first cycle from a harvest that is ripe now', function (): void {
    $r = FeedingSimulation::run([], [['id' => 'd1', 'name' => 'Ruby', 'needKcal' => 1000.0, 'forbidden' => []]],
        [['offset' => 0.0, 'cropId' => 'Lettuce', 'kcal' => 1000.0]]);

    expect($r['autonomy'])->toBe(1);
});

it('stops the projection at the shortage instead of dropping it to zero', function (): void {
    $r = FeedingSimulation::run(['Lettuce' => 1500.0, 'FishMeat' => 5000.0], [
        ['id' => 'd1', 'name' => 'Ruby', 'needKcal' => 1000.0, 'forbidden' => []],
        ['id' => 'd2', 'name' => 'Ellie', 'needKcal' => 1000.0, 'forbidden' => ['FishMeat']],
    ], []);

    // Ruby eats fish, Ellie lettuce: Ellie runs short in cycle 2 while fish remains.
    expect($r['autonomy'])->toBe(1)
        ->and($r['stockByCycle'])->toHaveCount(51)
        ->and(array_slice($r['stockByCycle'], 0, 4))->toBe([6500.0, 4500.0, 4000.0, null]);
});

it('leaves food nobody may eat out of the projection and the autonomy', function (): void {
    $eaters = [
        ['id' => 'd1', 'name' => 'Ruby', 'needKcal' => 1000.0, 'forbidden' => ['Lettuce']],
        ['id' => 'd2', 'name' => 'Ellie', 'needKcal' => 1000.0, 'forbidden' => ['Lettuce']],
    ];

    $withoutHarvest = FeedingSimulation::run(['Lettuce' => 9000.0, 'FishMeat' => 3000.0], $eaters, []);
    $withHarvest = FeedingSimulation::run(['Lettuce' => 9000.0, 'FishMeat' => 3000.0], $eaters, [['offset' => 0.5, 'cropId' => 'Lettuce', 'kcal' => 4000.0]]);

    expect($withoutHarvest['autonomy'])->toBe(1)
        ->and($withHarvest['autonomy'])->toBe(1)
        ->and(array_slice($withHarvest['stockByCycle'], 0, 3))->toBe([3000.0, 1000.0, 0.0]);
});

it('tells whether every duplicant is forbidden a food', function (): void {
    $eaters = [
        ['id' => 'd1', 'name' => 'Ruby', 'needKcal' => 1000.0, 'forbidden' => ['Lettuce', 'FishMeat']],
        ['id' => 'd2', 'name' => 'Ellie', 'needKcal' => 1000.0, 'forbidden' => ['Lettuce']],
    ];

    expect(FeedingSimulation::nobodyEats('Lettuce', $eaters))->toBeTrue()
        ->and(FeedingSimulation::nobodyEats('FishMeat', $eaters))->toBeFalse()
        ->and(FeedingSimulation::nobodyEats('Lettuce', []))->toBeFalse();
});
