<?php

declare(strict_types=1);

use App\Data\GameRules\OccupancyInputData;
use App\Data\GameRules\OccupantData;
use App\Services\GameRules\Critters\Overcrowding;

/**
 * @return list<OccupantData>
 */
function overcrowdingOccupants(string $prefabId, int $count, int $space, bool $aquatic = false): array
{
    return array_fill(0, $count, new OccupantData($prefabId, $space, $aquatic, false));
}

it('is not crowded below the limit', function (): void {
    $verdict = (new Overcrowding)->analyze(
        new OccupancyInputData(104, false, overcrowdingOccupants('Pacu', 11, 8), []),
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
        new OccupancyInputData(104, false, overcrowdingOccupants('Pacu', 11, 8), overcrowdingOccupants('Pacu', 3, 8)),
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
        new OccupancyInputData(80, false, overcrowdingOccupants('Pacu', 11, 8), []),
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
    $critters = [...overcrowdingOccupants('Pacu', 11, 8), ...overcrowdingOccupants('Other', 1, 12)];

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
    $critters = [...overcrowdingOccupants('Small', 2, 4), ...overcrowdingOccupants('Big', 3, 10)];

    $verdict = (new Overcrowding)->analyze(new OccupancyInputData(20, false, $critters, []), 4);

    expect(array_map(fn ($type): string => $type->prefabId, $verdict->perType))->toBe(['Big', 'Small']);
});

it('is confined in a region smaller than one critter', function (): void {
    $input = new OccupancyInputData(5, false, [], []);

    expect((new Overcrowding)->analyze($input, 8)->confined)->toBeTrue()
        ->and((new Overcrowding)->analyze($input, 8, true)->confined)->toBeFalse();
});

it('ignores fish in a land cavity and land critters in a pond', function (): void {
    $critters = [...overcrowdingOccupants('Pacu', 5, 8, true), ...overcrowdingOccupants('Hatch', 2, 8)];
    $eggs = [...overcrowdingOccupants('Pacu', 1, 8, true), ...overcrowdingOccupants('Hatch', 1, 8)];

    $land = (new Overcrowding)->analyze(new OccupancyInputData(100, false, $critters, $eggs), 8);
    $pond = (new Overcrowding)->analyze(new OccupancyInputData(100, true, $critters, $eggs), 8);

    expect($land->occupied)->toBe(16)
        ->and($land->eggOccupied)->toBe(8)
        ->and($pond->occupied)->toBe(40)
        ->and($pond->eggOccupied)->toBe(8);
});

it('is never crowded with zero personal space', function (): void {
    $verdict = (new Overcrowding)->analyze(
        new OccupancyInputData(0, false, overcrowdingOccupants('Egg', 3, 0), overcrowdingOccupants('Egg', 2, 0)),
        0,
    );

    expect($verdict->overcrowded)->toBeFalse()
        ->and($verdict->cramped)->toBeFalse()
        ->and($verdict->confined)->toBeFalse()
        ->and($verdict->happinessModifier)->toBe(0)
        ->and($verdict->perType)->toBe([]);
});

it('says a cramped species cannot fix it alone when it has too few critters', function (): void {
    $critters = [...overcrowdingOccupants('Small', 2, 4), ...overcrowdingOccupants('Big', 1, 40)];

    $verdict = (new Overcrowding)->analyze(new OccupancyInputData(50, false, $critters, overcrowdingOccupants('Big', 3, 8)), 4);

    expect($verdict->cramped)->toBeTrue()
        ->and($verdict->overcrowded)->toBeFalse();

    [$big, $small] = $verdict->perType;
    expect($big->prefabId)->toBe('Big')
        ->and($big->toRemove)->toBe(1)
        ->and($big->canFix)->toBeTrue()
        ->and($small->prefabId)->toBe('Small')
        ->and($small->toRemove)->toBe(2)
        ->and($small->canFix)->toBeFalse()
        ->and($small->derived)->toBeTrue();
});

it('lists only the fish of a pond in the removal', function (): void {
    $critters = [...overcrowdingOccupants('Pacu', 14, 8, true), ...overcrowdingOccupants('Hatch', 5, 12)];

    $verdict = (new Overcrowding)->analyze(new OccupancyInputData(104, true, $critters, []), 8);

    expect($verdict->overcrowded)->toBeTrue()
        ->and($verdict->perType)->toHaveCount(1)
        ->and($verdict->perType[0]->prefabId)->toBe('Pacu')
        ->and($verdict->perType[0]->count)->toBe(14)
        ->and($verdict->perType[0]->toRemove)->toBe(1);
});

it('reports confined and overcrowded each on its own', function (): void {
    $verdict = (new Overcrowding)->analyze(new OccupancyInputData(4, false, overcrowdingOccupants('Pacu', 1, 8), []), 8);

    expect($verdict->confined)->toBeTrue()
        ->and($verdict->overcrowded)->toBeTrue()
        ->and($verdict->happinessModifier)->toBe(-1);
});
