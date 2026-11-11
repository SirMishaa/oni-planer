<?php

declare(strict_types=1);

use App\Services\Viability\HarvestSchedule;

beforeEach(function (): void {
    $this->cal = ['Lettuce' => 400000.0, 'FishMeat' => 1000000.0];
});

it('schedules planted, wild auto and wild manual harvests', function (): void {
    $r = HarvestSchedule::build(fixtureState()['plants'], $this->cal);
    $by = collect($r['harvests'])->groupBy('kind');

    expect($by['planted']->first())->toMatchArray(['offset' => 0.5, 'kcal' => 4800.0])
        ->and($by['planted']->pluck('offset')->take(3)->all())->toEqualWithDelta([0.5, 12.5, 24.5], 0.01)
        ->and($by['wild_auto']->pluck('offset')->take(2)->all())->toEqualWithDelta([2.0, 50.0], 0.05)
        ->and($by['wild_manual']->first()['offset'])->toEqualWithDelta(1.0, 0.01);
});

it('counts stopped plants and yields nothing for them', function (): void {
    $planted = collect(HarvestSchedule::build(fixtureState()['plants'], $this->cal)['sources'])->firstWhere('key', 'SeaLettuce:planted');

    expect($planted)->toMatchArray(['count' => 2, 'stopped' => 1])
        ->and($planted['kcalPerCycle'])->toEqualWithDelta(400.0, 0.5);
});

it('ignores crops that are not foods', function (): void {
    $r = HarvestSchedule::build(fixtureState()['plants'], $this->cal);

    expect($r['unknownCrops'])->toBe(['NotAFood'])
        ->and(collect($r['harvests'])->pluck('cropId')->unique()->values()->all())->toBe(['Lettuce']);
});

it('tolerates null numbers', function (): void {
    $plants = [['id' => 'X', 'cropId' => 'Lettuce', 'cropUnits' => null, 'cropDurationSeconds' => null, 'planted' => true,
        'autoHarvest' => null, 'wilting' => false, 'grown' => false, 'secondsUntilHarvest' => null, 'growthPerSecond' => null]];

    expect(HarvestSchedule::build($plants, $this->cal)['harvests'])->toBe([]);
});

it('treats a plant with a harvest period under one second as stopped', function (array $numbers): void {
    $plants = [[...['id' => 'Z', 'cropId' => 'Lettuce', 'cropUnits' => 12, 'cropDurationSeconds' => 7200, 'planted' => true,
        'autoHarvest' => true, 'wilting' => false, 'grown' => false, 'secondsUntilHarvest' => 300, 'growthPerSecond' => 1 / 600], ...$numbers]];

    $r = HarvestSchedule::build($plants, $this->cal);

    expect($r['harvests'])->toBe([])
        ->and($r['sources'][0])->toMatchArray(['count' => 1, 'stopped' => 1, 'kcalPerCycle' => 0.0]);
})->with([
    'huge growth rate' => [['growthPerSecond' => 1e6]],
    'tiny crop duration' => [['cropDurationSeconds' => 1e-9]],
]);

it('does not know auto harvest when every wild plant reports null', function (): void {
    $plants = [['id' => 'W', 'cropId' => 'Lettuce', 'cropUnits' => 12, 'cropDurationSeconds' => 7200, 'planted' => false,
        'autoHarvest' => null, 'wilting' => false, 'grown' => true, 'secondsUntilHarvest' => null, 'growthPerSecond' => null]];

    $r = HarvestSchedule::build($plants, $this->cal);

    expect($r['autoHarvestKnown'])->toBeFalse()
        ->and(collect($r['harvests'])->pluck('offset')->all())->toEqualWithDelta([0.0, 48.0], 0.001)
        ->and($r['harvests'][0]['kind'])->toBe('wild_manual');
});
