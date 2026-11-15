<?php

declare(strict_types=1);

namespace App\Services\GameRules\Critters;

use App\Data\GameRules\AcceptedFoodData;
use App\Data\GameRules\FeedingBalanceData;
use App\Data\GameRules\InventoryItemData;
use App\Data\GameRules\ResourceFlowData;
use App\Services\Critters\CritterDietRepository;

/**
 * What a group of critters eats and lays per cycle, and how long the stock of accepted food lasts.
 *
 * Free of database access: the diet and output rows come from a `CritterDietRepository`. A diet line's amount
 * is what one critter eats per cycle when that food alone covers its calories, so the lines are alternatives,
 * not a sum. Only `item` lines (food picked up) are in kg and can be stocked; `plant` and `prey` lines are
 * eaten in place and stay in `accepts` for display only.
 */
final class CritterFeeding
{
    public const SOURCE_KIND = 'critter_group';

    public const STOCKABLE_KIND = 'item';

    /** A critter's Metabolism attribute with no modifier; its calorie burn is the diet rate × metabolism / 100. */
    public const int BASE_METABOLISM = 100;

    /** Metabolism modifier of a wild critter (the game's WILD_CALORIE_BURN_RATIO 0.25). */
    public const int WILD_METABOLISM = -75;

    /** Metabolism modifier of a glum or miserable tame critter. */
    public const int UNHAPPY_TAME_METABOLISM = -80;

    /** Metabolism modifier of a glum or miserable wild critter. */
    public const int UNHAPPY_WILD_METABOLISM = -15;

    /** @var list<string> happiness states that slow the metabolism */
    private const array UNHAPPY_STATES = ['glum', 'miserable'];

    public function __construct(private readonly CritterDietRepository $diets) {}

    /**
     * What one critter eats, as a share of the diet rate (a tame, happy critter): 1.0 tame, 0.25 wild, and 0.20
     * (tame) or 0.10 (wild) when glum or miserable. An unknown mood counts as happy.
     */
    public static function metabolismRatio(bool $wild, ?string $happinessState): float
    {
        $unhappy = in_array($happinessState, self::UNHAPPY_STATES, true);
        $metabolism = self::BASE_METABOLISM
            + ($wild ? self::WILD_METABOLISM : 0)
            + ($unhappy ? ($wild ? self::UNHAPPY_WILD_METABOLISM : self::UNHAPPY_TAME_METABOLISM) : 0);

        return max(0, $metabolism) / self::BASE_METABOLISM;
    }

    /**
     * One consumed flow listing every accepted food, then one produced flow per output row. A morph without a
     * diet has no consumed flow.
     *
     * The consumed flow is named after the first `item` line, in kg. A morph with no item line (a plant or prey
     * eater) gets a flow named after its first line with `unit: 'count'`: its amount is plant growth or prey,
     * not kg, and it must not be added to kg totals.
     *
     * The diet lines are multiplied by `$eaters`, the sum of each critter's {@see self::metabolismRatio()} (the
     * count when omitted); the outputs (eggs) by the count.
     *
     * @return list<ResourceFlowData>
     */
    public function flows(string $groupKey, string $morphId, int $count, ?int $zoneId, ?float $eaters = null): array
    {
        $flows = [];
        $eaters ??= (float) $count;

        $accepts = array_map(
            static function (array $diet) use ($eaters): AcceptedFoodData {
                $kgPerCycle = $diet['kgPerCycle'] * $eaters;

                return new AcceptedFoodData(
                    $diet['resource'],
                    $kgPerCycle,
                    $diet['produces'],
                    $diet['produces'] !== null && $diet['conversionRatio'] !== null ? $kgPerCycle * $diet['conversionRatio'] : null,
                    $diet['kind'],
                );
            },
            $this->diets->diets($morphId),
        );

        if ($accepts !== []) {
            $named = $this->stockable($accepts)[0] ?? null;
            $flows[] = $named !== null
                ? new ResourceFlowData($named->resource, $named->kgPerCycle, 'kg', 'consumed', self::SOURCE_KIND, $groupKey, $zoneId, $accepts)
                : new ResourceFlowData($accepts[0]->resource, $accepts[0]->kgPerCycle, 'count', 'consumed', self::SOURCE_KIND, $groupKey, $zoneId, $accepts);
        }

        foreach ($this->diets->outputs($morphId) as $output) {
            $flows[] = new ResourceFlowData($output['resource'], $output['perCycle'] * $count, $output['kind'] === 'egg' ? 'count' : 'kg', 'produced', self::SOURCE_KIND, $groupKey, $zoneId);
        }

        return $flows;
    }

    /**
     * Stock of the accepted `item` foods against the need of a consumed flow. The main food (shown) is the item
     * food with a need and the most stock, ties going to the earlier diet line. Without such stock the flow's need
     * (its first item line) is reported as the deficit.
     *
     * A flow with no item line (a plant or prey eater) cannot be judged from stock: available is 0, and the
     * deficit and autonomy are null.
     *
     * @param  list<InventoryItemData>  $inventory
     */
    public function balance(ResourceFlowData $consumed, array $inventory): FeedingBalanceData
    {
        $foods = $this->stockable($consumed->accepts);

        $stock = [];
        foreach ($foods as $food) {
            $stock[$food->resource] = 0.0;
        }

        foreach ($inventory as $item) {
            if (array_key_exists($item->resource, $stock)) {
                $stock[$item->resource] += $item->kg;
            }
        }

        $main = null;
        foreach ($foods as $food) {
            if ($food->kgPerCycle > 0 && $stock[$food->resource] > 0 && ($main === null || $stock[$food->resource] > $stock[$main->resource])) {
                $main = $food;
            }
        }

        return new FeedingBalanceData(
            $consumed->sourceId,
            $consumed->accepts,
            array_sum($stock),
            $main?->resource,
            $main === null && $foods !== [] ? $consumed->perCycle : null,
            $this->autonomy($foods, $stock),
        );
    }

    /**
     * @param  list<AcceptedFoodData>  $accepts
     * @return list<AcceptedFoodData>
     */
    private function stockable(array $accepts): array
    {
        return array_values(array_filter(
            $accepts,
            static fn (AcceptedFoodData $food): bool => $food->kind === self::STOCKABLE_KIND,
        ));
    }

    /**
     * The diet lines are alternatives: each kg of food i covers 1 / kgPerCycle_i of a cycle for the whole group, so
     * the stock lasts Σ stock_i ÷ kgPerCycle_i cycles. Each food counts once, and a food eaten at 0 kg per cycle
     * adds nothing. Null when no food has a need.
     *
     * @param  list<AcceptedFoodData>  $foods
     * @param  array<string, float>  $stock
     */
    private function autonomy(array $foods, array $stock): ?float
    {
        $cycles = null;
        $counted = [];
        foreach ($foods as $food) {
            if ($food->kgPerCycle > 0 && ! isset($counted[$food->resource])) {
                $counted[$food->resource] = true;
                $cycles = ($cycles ?? 0.0) + $stock[$food->resource] / $food->kgPerCycle;
            }
        }

        return $cycles;
    }
}
