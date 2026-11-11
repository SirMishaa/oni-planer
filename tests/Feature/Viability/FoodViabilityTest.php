<?php

declare(strict_types=1);

use App\Services\Viability\FoodViability;

beforeEach(function (): void {
    $this->cal = ['Lettuce' => 400000.0, 'FishMeat' => 1000000.0];
});

it('computes the headline from automatic arrivals only', function (): void {
    $v = FoodViability::compute(fixtureState(), $this->cal);

    expect($v['consumptionKcalPerCycle'])->toEqualWithDelta(2000.0, 0.1)
        ->and($v['stockKcal'])->toBe(7000.0)
        ->and($v['scenarios']['none']['autonomy'])->toBe(3)
        ->and($v['scenarios']['automatic']['autonomy'])->toBeGreaterThan(3)
        ->and($v['scenarios']['all']['autonomy'])->toBeGreaterThanOrEqual($v['scenarios']['automatic']['autonomy'])
        ->and($v['verdict'])->toBeIn(['critical', 'tight', 'holds']);
});

it('excludes duplicants that do not eat', function (): void {
    $state = fixtureState();
    $state['duplicants'][] = ['id' => 'd3', 'name' => 'Bix', 'model' => 'Bionic', 'worldId' => 0, 'calories' => null, 'forbiddenFoods' => [], 'traits' => []];

    $v = FoodViability::compute($state, $this->cal);

    expect($v['consumptionKcalPerCycle'])->toEqualWithDelta(2000.0, 0.1)
        ->and($v['nonEaters'])->toBe([['name' => 'Bix', 'model' => 'Bionic']]);
});

it('marks an unreadable section as unavailable', function (): void {
    $state = fixtureState();
    $state['plants'] = ['error' => 'boom'];

    $v = FoodViability::compute($state, $this->cal);

    expect($v['unavailable'])->toBe(['plants'])
        ->and($v['scenarios']['automatic']['autonomy'])->toBe(3)
        ->and($v['verdict'])->toBe('tight');
});

it('withholds the verdict when the duplicants or the food cannot be read', function (string $section): void {
    $state = fixtureState();
    $state[$section] = ['error' => 'boom'];

    $v = FoodViability::compute($state, $this->cal);

    expect($v['unavailable'])->toBe([$section])
        ->and($v['verdict'])->toBe('unknown');
})->with(['duplicants', 'food']);

it('leaves zero-calorie edibles off the menu', function (): void {
    $state = fixtureState();
    $state['food'][] = ['id' => 'ColdWheatSeed', 'worldId' => 0, 'units' => 40, 'calories' => 0];

    $v = FoodViability::compute($state, $this->cal);

    expect(collect($v['menu'])->pluck('foodId')->all())->not->toContain('ColdWheatSeed')
        ->and(collect($v['eaters'])->flatMap(fn (array $eater): array => $eater['allowed'])->all())->not->toContain('ColdWheatSeed')
        ->and($v['stockKcal'])->toBe(7000.0);
});

it('lists critter reserves without counting them', function (): void {
    $v = FoodViability::compute(fixtureState(), $this->cal);

    expect($v['reserves']['critters'][0])->toMatchArray(['id' => 'Pacu', 'tame' => true, 'count' => 2, 'kcal' => 2000.0])
        ->and($v['reserves']['printingPod']['cyclesUntilNextPrint'])->toEqualWithDelta(2.1, 0.01)
        ->and($v['scenarios']['all']['autonomy'])->toBe(10);
});

it('leaves critter food unknown when drops are not reported', function (): void {
    $state = fixtureState();
    $state['critters'] = array_map(function (array $critter): array {
        unset($critter['drops']);

        return $critter;
    }, $state['critters']);

    expect(FoodViability::compute($state, $this->cal)['reserves']['critters'][0]['kcal'])->toBeNull();
});

it('grades the verdict from automatic arrivals', function (int $lettuceUnits, string $verdict): void {
    $state = fixtureState();
    $state['plants'] = [];
    $state['food'] = [['id' => 'Lettuce', 'worldId' => 0, 'units' => $lettuceUnits, 'calories' => $lettuceUnits * 400000]];

    expect(FoodViability::compute($state, $this->cal)['verdict'])->toBe($verdict);
})->with([
    'under 3 cycles' => [10, 'critical'],
    'under 10 cycles' => [30, 'tight'],
    '10 cycles or more' => [51, 'holds'],
    'beyond the horizon' => [300, 'holds'],
]);

it('describes the menu, eaters and harvests on the absolute cycle', function (): void {
    $v = FoodViability::compute(fixtureState(), $this->cal);

    expect($v['nowCycle'])->toBe(59.5)
        ->and($v['lastComputedCycle'])->toBe(109.5)
        ->and($v['autoHarvestKnown'])->toBeTrue()
        ->and(collect($v['menu'])->firstWhere('foodId', 'FishMeat'))->toMatchArray(['units' => 3, 'kcal' => 3000.0, 'forbiddenFor' => ['Ellie']])
        ->and(collect($v['eaters'])->firstWhere('name', 'Ellie'))->toMatchArray(['allowed' => ['Lettuce'], 'forbidden' => ['FishMeat'], 'kcal' => 3000.0, 'maxKcal' => 4000.0])
        ->and($v['harvests'][0])->toMatchArray(['sourceKey' => 'SeaLettuce:planted', 'kind' => 'planted', 'kcal' => 4800.0])
        ->and($v['harvests'][0]['cycle'])->toEqualWithDelta(60.0, 0.001)
        ->and($v['reserves']['wildManual'])->toHaveCount(1);
});

it('sets apart the food and the crops nobody may eat', function (): void {
    $state = fixtureState();
    $state['duplicants'] = array_map(fn (array $duplicant): array => [...$duplicant, 'forbiddenFoods' => ['Lettuce']], $state['duplicants']);

    $v = FoodViability::compute($state, $this->cal);

    expect($v['stockKcal'])->toBe(3000.0)
        ->and($v['uneatenStockKcal'])->toBe(4000.0)
        ->and(collect($v['sources'])->firstWhere('key', 'SeaLettuce:planted')['forbiddenToAll'])->toBeTrue()
        ->and($v['scenarios']['automatic']['autonomy'])->toBe($v['scenarios']['none']['autonomy']);
});

it('keeps a crop that some duplicants may eat', function (): void {
    $v = FoodViability::compute(fixtureState(), $this->cal);

    expect($v['uneatenStockKcal'])->toBe(0.0)
        ->and(collect($v['sources'])->pluck('forbiddenToAll')->unique()->all())->toBe([false]);
});
