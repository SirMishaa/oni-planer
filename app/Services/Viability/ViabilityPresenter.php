<?php

declare(strict_types=1);

namespace App\Services\Viability;

use App\Data\Viability\ViabilityCritterReserveData;
use App\Data\Viability\ViabilityData;
use App\Data\Viability\ViabilityEaterData;
use App\Data\Viability\ViabilityHarvestData;
use App\Data\Viability\ViabilityMenuItemData;
use App\Data\Viability\ViabilityNonEaterData;
use App\Data\Viability\ViabilityPrintingPodData;
use App\Data\Viability\ViabilityReservesData;
use App\Data\Viability\ViabilityScenarioData;
use App\Data\Viability\ViabilityScenariosData;
use App\Data\Viability\ViabilitySourceData;
use App\Models\Food;
use App\Services\GameText;

/**
 * Runs {@see FoodViability} on a colony state and adds what the page shows
 * beside the ids: display names in the current locale and icon URLs.
 */
final readonly class ViabilityPresenter
{
    public function __construct(private GameText $text) {}

    /** @param  array<string, mixed>  $state  the stored colony state */
    public function present(array $state): ViabilityData
    {
        $caloriesPerUnit = array_map(floatval(...), Food::query()->pluck('calories_per_unit', 'food_id')->all());
        $result = FoodViability::compute($state, $caloriesPerUnit);

        return new ViabilityData(
            nowCycle: $result['nowCycle'],
            verdict: $result['verdict'],
            consumptionKcalPerCycle: $result['consumptionKcalPerCycle'],
            stockKcal: $result['stockKcal'],
            scenarios: new ViabilityScenariosData(
                none: new ViabilityScenarioData(...$result['scenarios']['none']),
                automatic: new ViabilityScenarioData(...$result['scenarios']['automatic']),
                all: new ViabilityScenarioData(...$result['scenarios']['all']),
            ),
            sources: array_map($this->source(...), $result['sources']),
            harvests: array_map(fn (array $harvest): ViabilityHarvestData => new ViabilityHarvestData(...$harvest), $result['harvests']),
            lastComputedCycle: $result['lastComputedCycle'],
            menu: array_map(fn (array $food): ViabilityMenuItemData => new ViabilityMenuItemData(
                foodId: $food['foodId'],
                name: $this->text->name($food['foodId']),
                icon: $this->icon('foods', $food['foodId']),
                units: $food['units'],
                kcal: $food['kcal'],
                forbiddenFor: $food['forbiddenFor'],
            ), $result['menu']),
            eaters: array_map(fn (array $eater): ViabilityEaterData => new ViabilityEaterData(...$eater), $result['eaters']),
            nonEaters: array_map(fn (array $duplicant): ViabilityNonEaterData => new ViabilityNonEaterData(...$duplicant), $result['nonEaters']),
            reserves: new ViabilityReservesData(
                wildManual: array_map($this->source(...), $result['reserves']['wildManual']),
                critters: array_map(fn (array $critter): ViabilityCritterReserveData => new ViabilityCritterReserveData(
                    id: $critter['id'],
                    name: $this->text->name($critter['id']),
                    icon: $this->icon('critters', $critter['id']),
                    tame: $critter['tame'],
                    count: $critter['count'],
                    kcal: $critter['kcal'],
                ), $result['reserves']['critters']),
                printingPod: new ViabilityPrintingPodData(...$result['reserves']['printingPod']),
            ),
            unavailable: $result['unavailable'],
            unknownCrops: $result['unknownCrops'],
            autoHarvestKnown: $result['autoHarvestKnown'],
        );
    }

    /** @param  array{key: string, plantId: string, cropId: string, kind: 'planted'|'wild_auto'|'wild_manual', count: int, stopped: int, kcalPerCycle: float}  $source */
    private function source(array $source): ViabilitySourceData
    {
        return new ViabilitySourceData(
            key: $source['key'],
            plantId: $source['plantId'],
            plantName: $this->text->name($source['plantId']),
            plantIcon: $this->icon('plants', $source['plantId']),
            cropId: $source['cropId'],
            kind: $source['kind'],
            count: $source['count'],
            stopped: $source['stopped'],
            kcalPerCycle: $source['kcalPerCycle'],
        );
    }

    /** Root-relative URL of a game sprite, or null when it was not imported. */
    private function icon(string $category, string $id): ?string
    {
        $relative = sprintf('%s/%s.webp', $category, $id);

        return file_exists((string) config('game.assets_path').'/'.$relative) ? '/assets/game/'.$relative : null;
    }
}
