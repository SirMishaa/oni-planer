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
            return $morphId === 'Pacu' ? [
                ['resource' => 'Algae', 'kgPerCycle' => 7.5, 'produces' => 'ToxicSand', 'conversionRatio' => 0.6666667],
                ['resource' => 'Kelp', 'kgPerCycle' => 20.0, 'produces' => 'ToxicSand', 'conversionRatio' => 0.5],
                ['resource' => 'FishFood', 'kgPerCycle' => 1.0, 'produces' => null, 'conversionRatio' => null],
            ] : [];
        }

        public function outputs(string $morphId): array
        {
            return $morphId === 'Pacu' ? [
                ['resource' => 'PacuEgg', 'perCycle' => 0.06666666, 'kind' => 'egg'],
            ] : [];
        }
    });

    $this->consumed = fn (): ResourceFlowData => collect($this->feeding->flows('Pacu:adult', 'Pacu', 38, 65))
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
        ->and($balance->autonomyCycles)->toEqualWithDelta(810.0 / 760.0, 0.0001);
});
