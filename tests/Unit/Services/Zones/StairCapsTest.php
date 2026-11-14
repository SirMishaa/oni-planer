<?php

declare(strict_types=1);

use App\Services\Zones\StairCaps;

/**
 * A 10-wide grid; $stairs and $solids are [x, y] cells, a stair facing right unless listed in $leftFacing.
 *
 * @param  list<array{int, int}>  $stairs
 * @param  list<array{int, int}>  $solids
 * @param  list<array{int, int}>  $leftFacing
 */
function stairMask(array $at, array $stairs, array $solids = [], array $leftFacing = []): int
{
    $cell = fn (array $xy): int => $xy[1] * 10 + $xy[0];
    $occupants = [];

    foreach ($solids as $xy) {
        $occupants[$cell($xy)] = ['stair' => false, 'facingLeft' => false, 'solid' => true];
    }

    foreach ($stairs as $xy) {
        $occupants[$cell($xy)] = ['stair' => true, 'facingLeft' => in_array($xy, $leftFacing, true), 'solid' => false];
    }

    return StairCaps::mask($cell($at), in_array($at, $leftFacing, true), 10, fn (int $c): ?array => $occupants[$c] ?? null);
}

it('shows a lone stair like its build preview', function (): void {
    expect(stairMask([5, 5], [[5, 5]]))->toBe(StairCaps::LEFT | StairCaps::TOP_HALF);
});

it('joins a run of stairs going up to the right', function (): void {
    $run = [[4, 4], [5, 5], [6, 6]];

    expect(stairMask([5, 5], $run))->toBe(StairCaps::LEFT)
        ->and(stairMask([6, 6], $run))->toBe(StairCaps::LEFT | StairCaps::TOP_HALF);
});

it('mirrors the neighbours of a stair facing left', function (): void {
    $run = [[6, 4], [5, 5], [4, 6]];

    expect(stairMask([5, 5], $run, leftFacing: $run))->toBe(StairCaps::LEFT)
        ->and(stairMask([5, 5], $run))->toBe(StairCaps::LEFT | StairCaps::TOP_HALF);
});

it('ignores a stair diagonally up that faces the other way', function (): void {
    expect(stairMask([5, 5], [[5, 5], [6, 6]], leftFacing: [[6, 6]]))->toBe(StairCaps::LEFT | StairCaps::TOP_HALF);
});

it('closes a stair under a tile', function (): void {
    expect(stairMask([5, 5], [[5, 5]], solids: [[5, 6]]))->toBe(StairCaps::RIGHT);
});

it('closes a stair against a wall on its low side', function (): void {
    expect(stairMask([5, 5], [[5, 5]], solids: [[4, 5]]))->toBe(StairCaps::RIGHT | StairCaps::TOP);
});

it('stands a stair on a stair below with a half cap', function (): void {
    expect(stairMask([5, 5], [[5, 5], [5, 4]]))->toBe(StairCaps::LEFT | StairCaps::RIGHT_HALF | StairCaps::TOP_HALF);
});

it('rests a stair on solid ground next to a stair', function (): void {
    expect(stairMask([5, 5], [[5, 5], [6, 5]], solids: [[5, 4]]))->toBe(StairCaps::LEFT | StairCaps::RIGHT_HALF | StairCaps::TOP_HALF);
});
