<?php

declare(strict_types=1);

use App\Enums\ZonePlanChangeKind;
use App\Enums\ZonePlanChangeState;
use App\Models\ZonePlanChange;
use App\Services\Zones\Plans\PlanFootprint;
use App\Services\Zones\Plans\ZonePlanLayout;

/**
 * @param  array<string, mixed>  $attributes
 */
function planChange(int $id, ZonePlanChangeKind $kind, array $attributes = []): ZonePlanChange
{
    $change = new ZonePlanChange($attributes + [
        'kind' => $kind,
        'state' => ZonePlanChangeState::Pending,
        'orientation' => 'Neutral',
        'checked' => true,
    ]);
    $change->forceFill(['id' => $id]);

    return $change;
}

/** @return array<string, mixed> */
function planBaseLayout(): array
{
    return [
        'gridWidth' => 10,
        'box' => ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10],
        'buildings' => [
            ['instanceId' => 'b1', 'anchorCell' => 12, 'prefabId' => 'Generator', 'width' => 3, 'height' => 2, 'extra' => 'kept'],
        ],
        'tiles' => [
            ['cell' => 5, 'prefabId' => 'MeshTile', 'connections' => 3],
            ['cell' => 6, 'prefabId' => 'Tile', 'connections' => 0],
        ],
    ];
}

const PLAN_DEFINITIONS = [
    'Lamp' => ['width' => 1, 'height' => 2, 'isTile' => false],
    'Tile' => ['width' => 1, 'height' => 1, 'isTile' => true],
];

it('swaps the footprint of a rotated building', function (): void {
    expect(PlanFootprint::size(3, 1, 'R90'))->toBe([1, 3])
        ->and(PlanFootprint::size(3, 1, 'R270'))->toBe([1, 3])
        ->and(PlanFootprint::size(3, 1, 'Neutral'))->toBe([3, 1])
        ->and(PlanFootprint::cells(12, 2, 2, 10))->toBe([12, 13, 22, 23]);
});

it('removes a building by instance id and a tile by cell', function (): void {
    $changes = [
        planChange(1, ZonePlanChangeKind::Remove, ['target_instance_id' => 'b1']),
        planChange(2, ZonePlanChangeKind::Remove, ['target_cell' => 6]),
    ];

    $layout = (new ZonePlanLayout)->apply(planBaseLayout(), $changes, PLAN_DEFINITIONS);

    expect($layout['buildings'])->toBe([])
        ->and(array_column($layout['tiles'], 'cell'))->toBe([5]);
});

it('adds a planned building with a plan instance id', function (): void {
    $changes = [planChange(7, ZonePlanChangeKind::Add, ['prefab_id' => 'Lamp', 'anchor_cell' => 40, 'orientation' => 'R90'])];

    $layout = (new ZonePlanLayout)->apply(planBaseLayout(), $changes, PLAN_DEFINITIONS);

    expect($layout['buildings'][1])->toBe([
        'instanceId' => 'plan:7', 'anchorCell' => 40, 'prefabId' => 'Lamp', 'width' => 2, 'height' => 1, 'orientation' => 'R90',
    ])->and($layout['buildings'][0]['extra'])->toBe('kept');
});

it('adds a planned tile to tiles', function (): void {
    $changes = [planChange(3, ZonePlanChangeKind::Add, ['prefab_id' => 'Tile', 'anchor_cell' => 44])];

    $layout = (new ZonePlanLayout)->apply(planBaseLayout(), $changes, PLAN_DEFINITIONS);

    expect($layout['tiles'][2])->toBe(['cell' => 44, 'prefabId' => 'Tile', 'connections' => 0])
        ->and($layout['buildings'])->toHaveCount(1);
});

it('turns a mesh tile into a solid tile with a replace', function (): void {
    $changes = [planChange(4, ZonePlanChangeKind::Replace, ['prefab_id' => 'Tile', 'anchor_cell' => 5, 'target_cell' => 5])];

    $layout = (new ZonePlanLayout)->apply(planBaseLayout(), $changes, PLAN_DEFINITIONS);

    $atFive = array_values(array_filter($layout['tiles'], fn (array $tile): bool => $tile['cell'] === 5));
    expect($atFive)->toHaveCount(1)
        ->and($atFive[0]['prefabId'])->toBe('Tile');
});

it('skips applied adds', function (): void {
    $changes = [planChange(5, ZonePlanChangeKind::Add, ['prefab_id' => 'Lamp', 'anchor_cell' => 40, 'state' => ZonePlanChangeState::Applied])];

    $layout = (new ZonePlanLayout)->apply(planBaseLayout(), $changes, PLAN_DEFINITIONS);

    expect($layout)->toBe(planBaseLayout());
});

it('leaves out the excepted change', function (): void {
    $changes = [
        planChange(8, ZonePlanChangeKind::Add, ['prefab_id' => 'Lamp', 'anchor_cell' => 40]),
        planChange(9, ZonePlanChangeKind::Add, ['prefab_id' => 'Lamp', 'anchor_cell' => 50]),
    ];

    $layout = (new ZonePlanLayout)->apply(planBaseLayout(), $changes, PLAN_DEFINITIONS, 8);

    expect(array_column($layout['buildings'], 'instanceId'))->toBe(['b1', 'plan:9']);
});
