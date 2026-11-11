<?php

declare(strict_types=1);

namespace App\Services\Viability;

/**
 * Answers, from a colony state sent by Uplink, how many cycles the colony can
 * feed its duplicants and when each harvest arrives. Pure: no database.
 */
final class FoodViability
{
    /** Sections of the state that the game may report as `{ "error": … }`. */
    private const array SECTIONS = ['duplicants', 'food', 'plants', 'critters', 'printingPod'];

    /** Need of a duplicant whose calorie burn is unknown (kcal per cycle). */
    private const float DEFAULT_NEED_KCAL = 1000.0;

    /** Sections without which no autonomy can be computed. */
    private const array REQUIRED_SECTIONS = ['duplicants', 'food'];

    /** Below this many cycles of autonomy the verdict is critical. */
    private const int CRITICAL_BELOW = 3;

    /** Below this many cycles of autonomy the verdict is tight. */
    private const int TIGHT_BELOW = 10;

    /**
     * Ids only (foods, crops, plants, critters); duplicant names come from the
     * state. Kcal are floats; cycles are absolute decimal cycles.
     *
     * @param  array<string, mixed>  $state  the `data` of a colony state message
     * @param  array<string, float>  $caloriesPerUnit  calories (cal) per unit, by food id
     * @return array{
     *   nowCycle: float,
     *   verdict: 'critical'|'tight'|'holds'|'unknown',
     *   consumptionKcalPerCycle: float,
     *   stockKcal: float,
     *   uneatenStockKcal: float,
     *   scenarios: array{
     *     none: array{autonomy: int|null, firstShort: string|null, stockByCycle: list<float|null>},
     *     automatic: array{autonomy: int|null, firstShort: string|null, stockByCycle: list<float|null>},
     *     all: array{autonomy: int|null, firstShort: string|null, stockByCycle: list<float|null>},
     *   },
     *   sources: list<array{key: string, plantId: string, cropId: string, kind: 'planted'|'wild_auto'|'wild_manual', count: int, stopped: int, kcalPerCycle: float, forbiddenToAll: bool}>,
     *   harvests: list<array{sourceKey: string, kind: 'planted'|'wild_auto'|'wild_manual', cycle: float, kcal: float}>,
     *   lastComputedCycle: float,
     *   menu: list<array{foodId: string, units: float, kcal: float, forbiddenFor: list<string>}>,
     *   eaters: list<array{id: string, name: string, kcal: float|null, maxKcal: float|null, needKcal: float, allowed: list<string>, forbidden: list<string>}>,
     *   nonEaters: list<array{name: string, model: string|null}>,
     *   reserves: array{
     *     wildManual: list<array{key: string, plantId: string, cropId: string, kind: 'wild_manual', count: int, stopped: int, kcalPerCycle: float, forbiddenToAll: bool}>,
     *     critters: list<array{id: string, tame: bool, count: int, kcal: float|null}>,
     *     printingPod: array{cyclesUntilNextPrint: float|null},
     *   },
     *   unavailable: list<string>,
     *   unknownCrops: list<string>,
     *   autoHarvestKnown: bool,
     * }
     */
    public static function compute(array $state, array $caloriesPerUnit): array
    {
        $unavailable = [];
        $section = function (string $name) use ($state, &$unavailable): array {
            $value = $state[$name] ?? null;
            if (! is_array($value)) {
                return [];
            }
            if (! array_is_list($value) && array_key_exists('error', $value)) {
                $unavailable[] = $name;

                return [];
            }

            return $value;
        };
        $sections = array_combine(self::SECTIONS, array_map($section, self::SECTIONS));

        $colony = is_array($state['colony'] ?? null) ? $state['colony'] : [];
        $nowCycle = (self::finite($colony['cycle'] ?? null) ?? 0.0) + (self::finite($colony['cyclePercent'] ?? null) ?? 0.0);

        $menu = self::menu($sections['food']);
        $stock = array_map(fn (array $food): float => $food['kcal'], $menu);
        [$eaters, $nonEaters] = self::duplicants($sections['duplicants']);

        $schedule = HarvestSchedule::build(array_values(array_filter($sections['plants'], is_array(...))), $caloriesPerUnit);
        $automatic = array_values(array_filter($schedule['harvests'], fn (array $harvest): bool => $harvest['kind'] !== 'wild_manual'));

        $scenarios = [
            'none' => FeedingSimulation::run($stock, $eaters, []),
            'automatic' => FeedingSimulation::run($stock, $eaters, $automatic),
            'all' => FeedingSimulation::run($stock, $eaters, $schedule['harvests']),
        ];

        foreach ($menu as $foodId => $food) {
            $menu[$foodId]['forbiddenFor'] = array_values(array_map(
                fn (array $eater): string => $eater['name'],
                array_filter($eaters, fn (array $eater): bool => in_array((string) $foodId, $eater['forbidden'], true)),
            ));
        }

        $stockedFoodIds = array_map(strval(...), array_keys($menu));
        $uneatenStock = array_filter($stock, fn (string|int $foodId): bool => FeedingSimulation::nobodyEats((string) $foodId, $eaters), ARRAY_FILTER_USE_KEY);
        $sources = array_map(fn (array $source): array => [
            ...$source,
            'forbiddenToAll' => FeedingSimulation::nobodyEats($source['cropId'], $eaters),
        ], $schedule['sources']);
        $printingPod = $sections['printingPod'];
        $secondsUntilNextPrint = self::finite($printingPod['secondsUntilNextPrint'] ?? null);

        return [
            'nowCycle' => $nowCycle,
            'verdict' => array_intersect(self::REQUIRED_SECTIONS, $unavailable) === [] ? self::verdict($scenarios['automatic']['autonomy']) : 'unknown',
            'consumptionKcalPerCycle' => array_sum(array_column($eaters, 'needKcal')),
            'stockKcal' => (float) array_sum(array_diff_key($stock, $uneatenStock)),
            'uneatenStockKcal' => (float) array_sum($uneatenStock),
            'scenarios' => $scenarios,
            'sources' => $sources,
            'harvests' => array_map(fn (array $harvest): array => [
                'sourceKey' => $harvest['sourceKey'],
                'kind' => $harvest['kind'],
                'cycle' => $nowCycle + $harvest['offset'],
                'kcal' => $harvest['kcal'],
            ], $schedule['harvests']),
            'lastComputedCycle' => $nowCycle + HarvestSchedule::HORIZON,
            'menu' => array_values($menu),
            'eaters' => array_map(fn (array $eater): array => [
                'id' => $eater['id'],
                'name' => $eater['name'],
                'kcal' => $eater['kcal'],
                'maxKcal' => $eater['maxKcal'],
                'needKcal' => $eater['needKcal'],
                'allowed' => array_values(array_diff($stockedFoodIds, $eater['forbidden'])),
                'forbidden' => array_values(array_intersect($stockedFoodIds, $eater['forbidden'])),
            ], $eaters),
            'nonEaters' => $nonEaters,
            'reserves' => [
                'wildManual' => array_values(array_filter($sources, fn (array $source): bool => $source['kind'] === 'wild_manual')),
                'critters' => self::critters($sections['critters'], $caloriesPerUnit),
                'printingPod' => ['cyclesUntilNextPrint' => $secondsUntilNextPrint === null ? null : $secondsUntilNextPrint / HarvestSchedule::CYCLE_SECONDS],
            ],
            'unavailable' => $unavailable,
            'unknownCrops' => $schedule['unknownCrops'],
            'autoHarvestKnown' => $schedule['autoHarvestKnown'],
        ];
    }

    /**
     * Stocked foods summed by id over all worlds, unknown foods included.
     * Rows with no calories (seeds and other ingredients the game counts as
     * edible) are left out.
     *
     * @param  array<mixed>  $food
     * @return array<string, array{foodId: string, units: float, kcal: float, forbiddenFor: list<string>}>
     */
    private static function menu(array $food): array
    {
        $menu = [];
        foreach ($food as $item) {
            $calories = is_array($item) ? self::finite($item['calories'] ?? null) : null;
            if (! is_array($item) || ! is_string($item['id'] ?? null) || $calories === null || $calories <= 0) {
                continue;
            }

            $id = $item['id'];
            $menu[$id] ??= ['foodId' => $id, 'units' => 0.0, 'kcal' => 0.0, 'forbiddenFor' => []];
            $menu[$id]['units'] += self::finite($item['units'] ?? null) ?? 0.0;
            $menu[$id]['kcal'] += $calories / 1000;
        }

        return $menu;
    }

    /**
     * Splits the duplicants into eaters (Minion model with a calorie meter)
     * and the others.
     *
     * @param  array<mixed>  $duplicants
     * @return array{
     *   0: list<array{id: string, name: string, kcal: float|null, maxKcal: float|null, needKcal: float, forbidden: list<string>}>,
     *   1: list<array{name: string, model: string|null}>,
     * }
     */
    private static function duplicants(array $duplicants): array
    {
        $eaters = [];
        $nonEaters = [];

        foreach ($duplicants as $duplicant) {
            if (! is_array($duplicant)) {
                continue;
            }

            $name = is_string($duplicant['name'] ?? null) ? $duplicant['name'] : '';
            $model = is_string($duplicant['model'] ?? null) ? $duplicant['model'] : null;
            $calories = $duplicant['calories'] ?? null;

            if ($model !== 'Minion' || ! is_array($calories)) {
                $nonEaters[] = ['name' => $name, 'model' => $model];

                continue;
            }

            $value = self::finite($calories['value'] ?? null);
            $max = self::finite($calories['max'] ?? null);
            $delta = self::finite($calories['deltaPerSecond'] ?? null);

            $eaters[] = [
                'id' => is_string($duplicant['id'] ?? null) ? $duplicant['id'] : $name,
                'name' => $name,
                'kcal' => $value === null ? null : $value / 1000,
                'maxKcal' => $max === null ? null : $max / 1000,
                'needKcal' => $delta !== null && $delta < 0 ? -$delta * HarvestSchedule::CYCLE_SECONDS / 1000 : self::DEFAULT_NEED_KCAL,
                'forbidden' => array_values(array_filter((array) ($duplicant['forbiddenFoods'] ?? []), is_string(...))),
            ];
        }

        return [$eaters, $nonEaters];
    }

    /**
     * Critters grouped by id and tameness, with the food their drops would
     * give; kcal is null when a critter of the group reports no drops.
     *
     * @param  array<mixed>  $critters
     * @param  array<string, float>  $caloriesPerUnit
     * @return list<array{id: string, tame: bool, count: int, kcal: float|null}>
     */
    private static function critters(array $critters, array $caloriesPerUnit): array
    {
        $groups = [];
        foreach ($critters as $critter) {
            if (! is_array($critter) || ! is_string($critter['id'] ?? null)) {
                continue;
            }

            $tame = ($critter['tame'] ?? false) === true;
            $key = $critter['id'].':'.($tame ? 'tame' : 'wild');
            $groups[$key] ??= ['id' => $critter['id'], 'tame' => $tame, 'count' => 0, 'kcal' => 0.0];
            $groups[$key]['count']++;

            if (! is_array($critter['drops'] ?? null)) {
                $groups[$key]['kcal'] = null;

                continue;
            }

            foreach ($critter['drops'] as $drop) {
                if ($groups[$key]['kcal'] === null || ! is_array($drop) || ! is_string($drop['id'] ?? null)) {
                    continue;
                }

                $groups[$key]['kcal'] += (self::finite($drop['units'] ?? null) ?? 0.0) * ($caloriesPerUnit[$drop['id']] ?? 0.0) / 1000;
            }
        }

        return array_values($groups);
    }

    /**
     * @return 'critical'|'tight'|'holds'
     */
    private static function verdict(?int $autonomy): string
    {
        return match (true) {
            $autonomy === null => 'holds',
            $autonomy < self::CRITICAL_BELOW => 'critical',
            $autonomy < self::TIGHT_BELOW => 'tight',
            default => 'holds',
        };
    }

    private static function finite(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null;
    }
}
