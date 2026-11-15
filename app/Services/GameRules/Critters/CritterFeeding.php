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
 * not a sum.
 */
final class CritterFeeding
{
    public const SOURCE_KIND = 'critter_group';

    public function __construct(private readonly CritterDietRepository $diets) {}

    /**
     * One consumed flow listing every accepted food (named after the first diet line), then one produced flow
     * per output row. A morph without a diet has no consumed flow.
     *
     * @return list<ResourceFlowData>
     */
    public function flows(string $groupKey, string $morphId, int $count, ?int $zoneId): array
    {
        $flows = [];

        $accepts = array_map(
            static function (array $diet) use ($count): AcceptedFoodData {
                $kgPerCycle = $diet['kgPerCycle'] * $count;

                return new AcceptedFoodData(
                    $diet['resource'],
                    $kgPerCycle,
                    $diet['produces'],
                    $diet['produces'] !== null && $diet['conversionRatio'] !== null ? $kgPerCycle * $diet['conversionRatio'] : null,
                );
            },
            $this->diets->diets($morphId),
        );

        if ($accepts !== []) {
            $flows[] = new ResourceFlowData($accepts[0]->resource, $accepts[0]->kgPerCycle, 'consumed', self::SOURCE_KIND, $groupKey, $zoneId, $accepts);
        }

        foreach ($this->diets->outputs($morphId) as $output) {
            $flows[] = new ResourceFlowData($output['resource'], $output['perCycle'] * $count, 'produced', self::SOURCE_KIND, $groupKey, $zoneId);
        }

        return $flows;
    }

    /**
     * Stock of the accepted foods against the need of a consumed flow. The need is the main food's rate (the
     * accepted food with the most stock, ties going to the earlier diet line); without stock it is the flow's
     * own rate, reported as the deficit.
     *
     * @param  list<InventoryItemData>  $inventory
     */
    public function balance(ResourceFlowData $consumed, array $inventory): FeedingBalanceData
    {
        $stock = [];
        foreach ($consumed->accepts as $food) {
            $stock[$food->resource] = 0.0;
        }

        foreach ($inventory as $item) {
            if (array_key_exists($item->resource, $stock)) {
                $stock[$item->resource] += $item->kg;
            }
        }

        $availableKg = array_sum($stock);
        $main = null;
        foreach ($consumed->accepts as $food) {
            if ($stock[$food->resource] > 0 && ($main === null || $stock[$food->resource] > $stock[$main->resource])) {
                $main = $food;
            }
        }

        $need = $main?->kgPerCycle ?? $consumed->perCycle;

        return new FeedingBalanceData(
            $consumed->sourceId,
            $consumed->accepts,
            $availableKg,
            $main?->resource,
            $main === null ? $need : null,
            $need > 0 ? $availableKg / $need : null,
        );
    }
}
