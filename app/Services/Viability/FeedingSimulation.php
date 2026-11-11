<?php

declare(strict_types=1);

namespace App\Services\Viability;

/**
 * Feeds the duplicants cycle by cycle from a food stock and the harvests
 * arriving in it, until one of them cannot eat. Body reserves of the
 * duplicants are ignored.
 */
final class FeedingSimulation
{
    /** kcal below which a stock or a need counts as zero (float noise). */
    private const float EPSILON = 1e-6;

    /**
     * Each cycle first feeds the most constrained duplicants first, each one
     * drawing first on the food the fewest duplicants may eat, then adds the
     * arrivals landing in it: a harvest feeds the cycles after the one it
     * lands in. A harvest that is ripe now (offset 0 or less) is already in
     * hand and feeds cycle 1.
     *
     * @param  array<string, float>  $stockKcal  by food id
     * @param  list<array{id: string, name: string, needKcal: float, forbidden: list<string>, ...}>  $eaters
     * @param  list<array{offset: float, cropId: string, kcal: float, ...}>  $arrivals
     * @return array{autonomy: int|null, firstShort: string|null, stockByCycle: list<float>}
     */
    public static function run(array $stockKcal, array $eaters, array $arrivals, int $horizon = HarvestSchedule::HORIZON): array
    {
        $stock = array_map(floatval(...), $stockKcal);
        $stockByCycle = [array_sum($stock)];

        foreach ($arrivals as $arrival) {
            if ($arrival['offset'] <= 0) {
                $stock[$arrival['cropId']] = ($stock[$arrival['cropId']] ?? 0.0) + $arrival['kcal'];
            }
        }

        for ($cycle = 1; $cycle <= $horizon; $cycle++) {
            $short = self::feed($stock, $eaters);

            if ($short !== null) {
                $stockByCycle[] = array_sum($stock);

                return [
                    'autonomy' => $cycle - 1,
                    'firstShort' => $short,
                    'stockByCycle' => array_pad($stockByCycle, $horizon + 1, 0.0),
                ];
            }

            foreach ($arrivals as $arrival) {
                if ($arrival['offset'] > $cycle - 1 && $arrival['offset'] <= $cycle) {
                    $stock[$arrival['cropId']] = ($stock[$arrival['cropId']] ?? 0.0) + $arrival['kcal'];
                }
            }

            $stockByCycle[] = array_sum($stock);
        }

        return ['autonomy' => null, 'firstShort' => null, 'stockByCycle' => $stockByCycle];
    }

    /**
     * Feeds one cycle, drawing from $stock in place.
     *
     * @param  array<string, float>  $stock
     * @param  list<array{id: string, name: string, needKcal: float, forbidden: list<string>, ...}>  $eaters
     * @return string|null the name of the first duplicant who could not be fully fed
     */
    private static function feed(array &$stock, array $eaters): ?string
    {
        $foodIds = array_keys($stock);
        $allowedByEater = array_map(
            fn (array $eater): array => array_values(array_filter($foodIds, fn (string|int $foodId): bool => ! in_array((string) $foodId, $eater['forbidden'], true))),
            $eaters,
        );

        $eatersByFood = [];
        foreach ($allowedByEater as $allowed) {
            foreach ($allowed as $foodId) {
                $eatersByFood[$foodId] = ($eatersByFood[$foodId] ?? 0) + 1;
            }
        }

        $inStock = function (string|int $foodId) use (&$stock): bool {
            return ($stock[$foodId] ?? 0.0) > self::EPSILON;
        };
        $order = array_keys($eaters);
        $choices = array_map(fn (array $allowed): int => count(array_filter($allowed, $inStock)), $allowedByEater);
        usort($order, fn (int $a, int $b): int => [$choices[$a], $a] <=> [$choices[$b], $b]);

        foreach ($order as $index) {
            $allowed = array_values(array_filter($allowedByEater[$index], $inStock));
            usort($allowed, fn (string|int $a, string|int $b): int => [$eatersByFood[$a], $stock[$b], (string) $a] <=> [$eatersByFood[$b], $stock[$a], (string) $b]);

            $remaining = $eaters[$index]['needKcal'];
            foreach ($allowed as $foodId) {
                $taken = min($stock[$foodId], $remaining);
                $stock[$foodId] -= $taken;
                $remaining -= $taken;

                if ($remaining <= self::EPSILON) {
                    break;
                }
            }

            if ($remaining > self::EPSILON) {
                return $eaters[$index]['name'];
            }
        }

        return null;
    }
}
