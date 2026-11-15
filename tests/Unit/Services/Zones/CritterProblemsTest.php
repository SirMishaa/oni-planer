<?php

declare(strict_types=1);

use App\Data\GameRules\CritterOverOccupancyData;
use App\Data\ZoneCritterData;
use App\Data\ZonePopulationData;
use App\Services\Critters\CritterStatusText;
use App\Services\Zones\CritterProblems;
use App\Services\Zones\ZonePopulation;

/**
 * @param  list<array{id: string, name: string, bad: bool}>  $statuses
 * @param  array<string, mixed>  $overrides
 */
function problemCritter(string $instanceId, array $statuses = [], array $overrides = []): ZoneCritterData
{
    return ZoneCritterData::from([
        'instanceId' => $instanceId, 'prefabId' => 'Pacu', 'name' => 'Pacu', 'sprite' => 'pacu.webp', 'cell' => 1, 'x' => 12.4, 'y' => 30.9,
        'facing' => 'left', 'navType' => 'Swim', 'moving' => false, 'speed' => 0.0, 'path' => [], 'aquatic' => true,
        'tame' => true, 'baby' => false, 'ageCycles' => null, 'lifespanCycles' => null, 'caloriesRatio' => null,
        'fertilityRatio' => null, 'breeding' => [], 'happiness' => null, 'happinessState' => null, 'stomach' => [],
        'nextPoop' => null, 'hungry' => false, 'statuses' => $statuses, 'regionId' => 'pond:1',
        ...$overrides,
    ]);
}

/** @return array{id: string, name: string, bad: bool} */
function badStatus(string $id, ?string $name = null): array
{
    return ['id' => $id, 'name' => $name ?? $id, 'bad' => true];
}

function crowdedRegion(bool $overcrowded = false, bool $cramped = true, bool $confined = false): ZonePopulationData
{
    return new ZonePopulationData(
        regionId: 'pond:1', kind: 'pond', cellCount: 104, occupied: 88, eggOccupied: 24,
        overcrowded: $overcrowded, cramped: $cramped, confined: $confined,
        states: array_map(fn (string $id): array => ['id' => $id, 'name' => CritterStatusText::name($id)], ZonePopulation::shownStates($overcrowded, $cramped, $confined)),
        perType: [new CritterOverOccupancyData('Pacu', 11, 8, 1, true, true)],
        types: [['prefabId' => 'Pacu', 'name' => 'Pacu', 'count' => 11, 'personalSpace' => 8]],
    );
}

it('raises one problem for a status shared by the whole group', function (): void {
    $critters = array_map(fn (int $index): ZoneCritterData => problemCritter((string) $index, [badStatus('Hungry', 'Hungry')]), range(1, 38));

    $problems = CritterProblems::of($critters, []);

    expect($problems)->toHaveCount(1)
        ->and($problems[0]->kind)->toBe('critter_group')
        ->and($problems[0]->name)->toBe('38 Pacu · Hungry')
        ->and($problems[0]->problems)->toBe(['Hungry'])
        ->and($problems[0]->groupKey)->toBe('Pacu:adult')
        ->and($problems[0]->count)->toBe(38)
        ->and($problems[0]->sprite)->toBe('pacu.webp');
});

it('raises one problem per critter with an individual alert', function (): void {
    $critters = [
        problemCritter('1', [badStatus('Starving', 'Starving')]),
        problemCritter('2'),
        problemCritter('3', [badStatus('Scalded', 'Scalded'), badStatus('Hungry', 'Hungry')]),
    ];

    $problems = CritterProblems::of($critters, []);

    expect($problems)->toHaveCount(2)
        ->and($problems[0]->kind)->toBe('critter')
        ->and($problems[0]->instanceId)->toBe('1')
        ->and($problems[0]->problems)->toBe(['Starving'])
        ->and($problems[0]->groupKey)->toBe('Pacu:adult')
        ->and([$problems[0]->x, $problems[0]->y])->toBe([12, 30])
        ->and($problems[1]->instanceId)->toBe('3')
        ->and($problems[1]->problems)->toBe(['Scalded', 'Hungry']);
});

it('raises one problem per crowded region and does not repeat its status on critters', function (): void {
    $critters = array_map(fn (int $index): ZoneCritterData => problemCritter((string) $index, [
        badStatus('Cramped', 'Cramped'), badStatus('FutureOvercrowded', 'Cramped'), badStatus('Overcrowded', 'Crowded'), badStatus('Confined', 'Confined'),
    ]), range(1, 11));

    $healthy = new ZonePopulationData('cavity:9', 'cavity', 20, 4, 0, false, false, false, [], [], []);

    $problems = CritterProblems::of($critters, [crowdedRegion(), $healthy]);

    expect($problems)->toHaveCount(1)
        ->and($problems[0]->kind)->toBe('critter_group')
        ->and($problems[0]->groupKey)->toBeNull()
        ->and($problems[0]->instanceId)->toBe('pond:1')
        ->and($problems[0]->count)->toBe(11)
        ->and($problems[0]->problems[0])->toContain('Cramped')->toContain('remove 1 Pacu');
});

it('does not raise anything for a healthy zone', function (): void {
    expect(CritterProblems::of([problemCritter('1'), problemCritter('2')], [crowdedRegion(cramped: false)]))->toBe([]);
});

it('raises a critter problem, not a group problem, for a lone critter', function (): void {
    $problems = CritterProblems::of([problemCritter('1', [badStatus('Starving', 'Starving')])], []);

    expect($problems)->toHaveCount(1)
        ->and($problems[0]->kind)->toBe('critter')
        ->and($problems[0]->instanceId)->toBe('1')
        ->and([$problems[0]->x, $problems[0]->y])->toBe([12, 30]);
});

it('names only the confined state of a confined region, from the game strings', function (): void {
    $problems = CritterProblems::of([problemCritter('1')], [crowdedRegion(overcrowded: true, cramped: true, confined: true)]);

    expect($problems)->toHaveCount(1)
        ->and($problems[0]->problems[0])->toStartWith(__('STRINGS.CREATURES.MODIFIERS.OVERCROWDED.CONFINED.NAME'))
        ->and($problems[0]->problems[0])->not->toContain(__('STRINGS.CREATURES.MODIFIERS.OVERCROWDED.CROWDED.NAME'))
        ->and($problems[0]->problems[0])->not->toContain(__('STRINGS.CREATURES.MODIFIERS.OVERCROWDED.CRAMPED.NAME'));
});
