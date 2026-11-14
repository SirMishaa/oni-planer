<?php

declare(strict_types=1);

use App\Data\GameRules\OccupancyInputData;
use App\Data\GameRules\OccupantData;
use App\Services\GameRules\Critters\Overcrowding;

/**
 * @return list<OccupantData>
 */
function occupants(string $prefabId, int $count, int $space, bool $aquatic = false): array
{
    return array_fill(0, $count, new OccupantData($prefabId, $space, $aquatic, false));
}

it('is not crowded below the limit', function (): void {
    $verdict = (new Overcrowding)->analyze(
        new OccupancyInputData(104, false, occupants('Pacu', 11, 8), []),
        8,
    );

    expect($verdict->occupied)->toBe(88)
        ->and($verdict->free)->toBe(16)
        ->and($verdict->overcrowded)->toBeFalse()
        ->and($verdict->cramped)->toBeFalse()
        ->and($verdict->confined)->toBeFalse()
        ->and($verdict->happinessModifier)->toBe(0)
        ->and($verdict->perType)->toBe([]);
});

it('is cramped when eggs would overflow and derives the removal', function (): void {
    $verdict = (new Overcrowding)->analyze(
        new OccupancyInputData(104, false, occupants('Pacu', 11, 8), occupants('Pacu', 3, 8)),
        8,
    );

    expect($verdict->eggOccupied)->toBe(24)
        ->and($verdict->cramped)->toBeTrue()
        ->and($verdict->overcrowded)->toBeFalse()
        ->and($verdict->perType)->toHaveCount(1);

    $pacu = $verdict->perType[0];
    expect($pacu->prefabId)->toBe('Pacu')
        ->and($pacu->toRemove)->toBe(1)
        ->and($pacu->canFix)->toBeTrue()
        ->and($pacu->derived)->toBeTrue();
});

it('is overcrowded and gives the game removal count', function (): void {
    $verdict = (new Overcrowding)->analyze(
        new OccupancyInputData(80, false, occupants('Pacu', 11, 8), []),
        8,
    );

    expect($verdict->overcrowded)->toBeTrue()
        ->and($verdict->free)->toBe(-8)
        ->and($verdict->happinessModifier)->toBe(-1)
        ->and($verdict->perType[0]->toRemove)->toBe(1)
        ->and($verdict->perType[0]->canFix)->toBeTrue()
        ->and($verdict->perType[0]->derived)->toBeFalse();
});

it('rounds the removal up', function (): void {
    $critters = [...occupants('Pacu', 11, 8), ...occupants('Other', 1, 12)];

    $verdict = (new Overcrowding)->analyze(new OccupancyInputData(80, false, $critters, []), 8);

    expect($verdict->occupied)->toBe(100)
        ->and($verdict->happinessModifier)->toBe(-3)
        ->and($verdict->perType)->toHaveCount(2);

    [$first, $second] = $verdict->perType;
    expect($first->prefabId)->toBe('Pacu')
        ->and($first->toRemove)->toBe(3)
        ->and($first->canFix)->toBeTrue()
        ->and($second->prefabId)->toBe('Other')
        ->and($second->toRemove)->toBe(1)
        ->and($second->canFix)->toBeFalse();
});

it('sorts the species by occupied space, largest first', function (): void {
    $critters = [...occupants('Small', 2, 4), ...occupants('Big', 3, 10)];

    $verdict = (new Overcrowding)->analyze(new OccupancyInputData(20, false, $critters, []), 4);

    expect(array_map(fn ($type): string => $type->prefabId, $verdict->perType))->toBe(['Big', 'Small']);
});

it('is confined in a region smaller than one critter', function (): void {
    $input = new OccupancyInputData(5, false, [], []);

    expect((new Overcrowding)->analyze($input, 8)->confined)->toBeTrue()
        ->and((new Overcrowding)->analyze($input, 8, true)->confined)->toBeFalse();
});

it('ignores fish in a land cavity and land critters in a pond', function (): void {
    $critters = [...occupants('Pacu', 5, 8, true), ...occupants('Hatch', 2, 8)];
    $eggs = [...occupants('Pacu', 1, 8, true), ...occupants('Hatch', 1, 8)];

    $land = (new Overcrowding)->analyze(new OccupancyInputData(100, false, $critters, $eggs), 8);
    $pond = (new Overcrowding)->analyze(new OccupancyInputData(100, true, $critters, $eggs), 8);

    expect($land->occupied)->toBe(16)
        ->and($land->eggOccupied)->toBe(8)
        ->and($pond->occupied)->toBe(40)
        ->and($pond->eggOccupied)->toBe(8);
});

it('is never crowded with zero personal space', function (): void {
    $verdict = (new Overcrowding)->analyze(
        new OccupancyInputData(0, false, occupants('Egg', 3, 0), occupants('Egg', 2, 0)),
        0,
    );

    expect($verdict->overcrowded)->toBeFalse()
        ->and($verdict->cramped)->toBeFalse()
        ->and($verdict->confined)->toBeFalse()
        ->and($verdict->happinessModifier)->toBe(0)
        ->and($verdict->perType)->toBe([]);
});
