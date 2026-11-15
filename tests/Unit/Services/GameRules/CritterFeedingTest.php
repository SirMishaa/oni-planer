<?php

declare(strict_types=1);

use App\Data\GameRules\AcceptedFoodData;
use App\Data\GameRules\InventoryItemData;
use App\Data\GameRules\ResourceFlowData;
use App\Services\Critters\CritterDietRepository;
use App\Services\GameRules\Critters\CritterFeeding;

beforeEach(function (): void {
    $this->feeding = new CritterFeeding(new class implements CritterDietRepository
    {
        public function diets(string $morphId): array
        {
            return match ($morphId) {
                'Pacu' => [
                    ['resource' => 'Algae', 'kgPerCycle' => 7.5, 'produces' => 'ToxicSand', 'conversionRatio' => 0.6666667, 'kind' => 'item'],
                    ['resource' => 'Kelp', 'kgPerCycle' => 20.0, 'produces' => 'ToxicSand', 'conversionRatio' => 0.5, 'kind' => 'item'],
                    ['resource' => 'FishFood', 'kgPerCycle' => 1.0, 'produces' => null, 'conversionRatio' => null, 'kind' => 'item'],
                ],
                'Hatch' => [
                    ['resource' => 'Sand', 'kgPerCycle' => 140.0, 'produces' => 'Carbon', 'conversionRatio' => 0.5, 'kind' => 'item'],
                    ['resource' => 'Meat', 'kgPerCycle' => 0.4375, 'produces' => 'Carbon', 'conversionRatio' => 0.75, 'kind' => 'item'],
                ],
                'Drecko' => [
                    ['resource' => 'SpiceVine', 'kgPerCycle' => 0.75, 'produces' => 'Phosphorite', 'conversionRatio' => 13.333333, 'kind' => 'plant'],
                ],
                'GoldBelly' => [
                    ['resource' => 'BeanPlant', 'kgPerCycle' => 0.5, 'produces' => 'Gold', 'conversionRatio' => 2.0, 'kind' => 'plant'],
                    ['resource' => 'BeanPlantSeed', 'kgPerCycle' => 3.0, 'produces' => 'Gold', 'conversionRatio' => 1.0, 'kind' => 'item'],
                ],
                'Odd' => [
                    ['resource' => 'Sand', 'kgPerCycle' => 10.0, 'produces' => null, 'conversionRatio' => null, 'kind' => 'item'],
                    ['resource' => 'Dust', 'kgPerCycle' => 0.0, 'produces' => null, 'conversionRatio' => null, 'kind' => 'item'],
                ],
                'Zero' => [
                    ['resource' => 'Dust', 'kgPerCycle' => 0.0, 'produces' => null, 'conversionRatio' => null, 'kind' => 'item'],
                ],
                default => [],
            };
        }

        public function outputs(string $morphId): array
        {
            return $morphId === 'Pacu' ? [
                ['resource' => 'PacuEgg', 'perCycle' => 0.06666666, 'kind' => 'egg'],
            ] : [];
        }
    });

    $this->consumed = fn (string $morphId = 'Pacu', int $count = 38): ResourceFlowData => collect($this->feeding->flows("{$morphId}:adult", $morphId, $count, 65))
        ->firstOrFail(fn (ResourceFlowData $flow): bool => $flow->direction === 'consumed');
});

it('builds one consumed flow listing every accepted food', function (): void {
    $consumed = array_values(array_filter(
        $this->feeding->flows('Pacu:adult', 'Pacu', 38, 65),
        fn (ResourceFlowData $flow): bool => $flow->direction === 'consumed',
    ));

    expect($consumed)->toHaveCount(1);

    $flow = $consumed[0];
    expect($flow->resource)->toBe('Algae')
        ->and($flow->perCycle)->toEqualWithDelta(285.0, 0.0001)
        ->and($flow->sourceKind)->toBe('critter_group')
        ->and($flow->sourceId)->toBe('Pacu:adult')
        ->and($flow->zoneId)->toBe(65)
        ->and(array_map(fn (AcceptedFoodData $food): string => $food->resource, $flow->accepts))->toBe(['Algae', 'Kelp', 'FishFood']);

    [$algae, $kelp, $fishFood] = $flow->accepts;
    expect($algae->kgPerCycle)->toEqualWithDelta(285.0, 0.0001)
        ->and($algae->kind)->toBe('item')
        ->and($algae->produces)->toBe('ToxicSand')
        ->and($algae->producedKgPerCycle)->toEqualWithDelta(190.0, 0.001)
        ->and($kelp->kgPerCycle)->toEqualWithDelta(760.0, 0.0001)
        ->and($kelp->producedKgPerCycle)->toEqualWithDelta(380.0, 0.0001)
        ->and($fishFood->produces)->toBeNull()
        ->and($fishFood->producedKgPerCycle)->toBeNull();
});

it('builds produced flows from outputs times count', function (): void {
    $produced = array_values(array_filter(
        $this->feeding->flows('Pacu:adult', 'Pacu', 38, 65),
        fn (ResourceFlowData $flow): bool => $flow->direction === 'produced',
    ));

    expect($produced)->toHaveCount(1)
        ->and($produced[0]->resource)->toBe('PacuEgg')
        ->and($produced[0]->perCycle)->toEqualWithDelta(38 * 0.06666666, 0.0001)
        ->and($produced[0]->sourceId)->toBe('Pacu:adult')
        ->and($produced[0]->accepts)->toBe([]);
});

it('builds no flow for a morph without diet or outputs', function (): void {
    expect($this->feeding->flows('Mystery:adult', 'Mystery', 3, null))->toBe([]);
});

it('counts eggs and weighs food in the flow units', function (): void {
    $units = array_map(
        fn (ResourceFlowData $flow): array => [$flow->resource, $flow->unit],
        $this->feeding->flows('Pacu:adult', 'Pacu', 38, 65),
    );

    expect($units)->toBe([['Algae', 'kg'], ['PacuEgg', 'count']]);
});

it('names the consumed flow after the first item line and keeps plant lines in accepts', function (): void {
    $flow = ($this->consumed)('GoldBelly', 2);

    expect($flow->resource)->toBe('BeanPlantSeed')
        ->and($flow->perCycle)->toEqualWithDelta(6.0, 0.0001)
        ->and($flow->unit)->toBe('kg')
        ->and(array_map(fn (AcceptedFoodData $food): array => [$food->resource, $food->kind], $flow->accepts))
        ->toBe([['BeanPlant', 'plant'], ['BeanPlantSeed', 'item']]);
});

it('computes autonomy from matching stock only', function (): void {
    $balance = $this->feeding->balance(($this->consumed)(), [
        new InventoryItemData('Algae', 456.0, 'storage', '1201', 65),
        new InventoryItemData('Sand', 100.0, 'storage', '1202', 65),
    ]);

    expect($balance->groupKey)->toBe('Pacu:adult')
        ->and($balance->availableKg)->toEqualWithDelta(456.0, 0.0001)
        ->and($balance->mainResource)->toBe('Algae')
        ->and($balance->deficitKgPerCycle)->toBeNull()
        ->and($balance->autonomyCycles)->toEqualWithDelta(1.6, 0.0001)
        ->and($balance->derived)->toBeTrue()
        ->and($balance->accepts)->toHaveCount(3);
});

it('sums autonomy per food when stock mixes foods', function (): void {
    $balance = $this->feeding->balance(($this->consumed)('Hatch', 2), [
        new InventoryItemData('Sand', 560.0, 'storage', '1301', 65),
        new InventoryItemData('Meat', 7.0, 'storage', '1302', 65),
    ]);

    expect($balance->mainResource)->toBe('Sand')
        ->and($balance->availableKg)->toEqualWithDelta(567.0, 0.0001)
        ->and($balance->autonomyCycles)->toEqualWithDelta(560.0 / 280.0 + 7.0 / 0.875, 0.0001);
});

it('reports the need as deficit without stock', function (): void {
    $balance = $this->feeding->balance(($this->consumed)(), [
        new InventoryItemData('Sand', 100.0, 'storage', '1202', 65),
    ]);

    expect($balance->availableKg)->toBe(0.0)
        ->and($balance->mainResource)->toBeNull()
        ->and($balance->deficitKgPerCycle)->toEqualWithDelta(285.0, 0.0001)
        ->and($balance->autonomyCycles)->toBe(0.0);
});

it('picks the accepted food with the most stock as main resource', function (): void {
    $balance = $this->feeding->balance(($this->consumed)(), [
        new InventoryItemData('Algae', 50.0, 'storage', '1201', 65),
        new InventoryItemData('Kelp', 300.0, 'storage', '1203', 65),
        new InventoryItemData('Kelp', 460.0, 'floor', '1204', 65),
    ]);

    expect($balance->mainResource)->toBe('Kelp')
        ->and($balance->availableKg)->toEqualWithDelta(810.0, 0.0001)
        ->and($balance->autonomyCycles)->toEqualWithDelta(50.0 / 285.0 + 760.0 / 760.0, 0.0001);
});

it('gives a stock tie to the earlier diet line', function (): void {
    $balance = $this->feeding->balance(($this->consumed)(), [
        new InventoryItemData('Kelp', 100.0, 'storage', '1203', 65),
        new InventoryItemData('Algae', 100.0, 'storage', '1201', 65),
    ]);

    expect($balance->mainResource)->toBe('Algae');
});

it('never picks a zero-rate food as main and leaves it out of autonomy', function (): void {
    $balance = $this->feeding->balance(($this->consumed)('Odd', 1), [
        new InventoryItemData('Dust', 500.0, 'storage', '1401', 65),
    ]);
    $zero = $this->feeding->balance(($this->consumed)('Zero', 1), [
        new InventoryItemData('Dust', 500.0, 'storage', '1401', 65),
    ]);

    expect($balance->mainResource)->toBeNull()
        ->and($balance->deficitKgPerCycle)->toEqualWithDelta(10.0, 0.0001)
        ->and($balance->autonomyCycles)->toBe(0.0)
        ->and($zero->autonomyCycles)->toBeNull();
});

it('does not report a deficit for a plant eater', function (): void {
    $flow = ($this->consumed)('Drecko', 4);
    $balance = $this->feeding->balance($flow, [
        new InventoryItemData('SpiceVine', 50.0, 'storage', '1501', 65),
    ]);

    expect($flow->unit)->toBe('count')
        ->and($flow->resource)->toBe('SpiceVine')
        ->and($flow->accepts[0]->kind)->toBe('plant')
        ->and($balance->availableKg)->toBe(0.0)
        ->and($balance->mainResource)->toBeNull()
        ->and($balance->deficitKgPerCycle)->toBeNull()
        ->and($balance->autonomyCycles)->toBeNull();
});
