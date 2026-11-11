<?php

declare(strict_types=1);

namespace App\Services\Viability;

/**
 * Turns the plants of a colony state into the food harvests expected within
 * the horizon, grouped into sources (one per plant id and kind).
 *
 * Growth is given by the game in maturity-cycles per second: a domestic plant
 * grows 1/600 per second, so its harvest period equals its crop duration; a
 * wild one at 25 % takes four times as long.
 */
final class HarvestSchedule
{
    /** Number of cycles computed ahead. */
    public const int HORIZON = 50;

    /** Seconds in one game cycle. */
    public const int CYCLE_SECONDS = 600;

    /** Period multiplier of a wild plant when the game gives no growth rate. */
    private const int WILD_SLOWDOWN = 4;

    /**
     * Shortest believable harvest period (seconds). Below it the reported
     * numbers are degenerate and would make the harvest loop run (nearly)
     * forever, so the plant counts as stopped.
     */
    private const float MIN_PERIOD_SECONDS = 60.0;

    /**
     * Unknown crops (not in $caloriesPerUnit) are listed and dropped. Stopped
     * plants (wilting, not growing, with no known next harvest or crop size,
     * or with a harvest period under one minute) are counted and yield
     * nothing, except a ripe wilting plant, which still yields the harvest
     * waiting on it, once. A growing plant whose crop duration is unknown
     * yields only its next harvest.
     *
     * @param  list<array<mixed>>  $plants
     * @param  array<string, float>  $caloriesPerUnit  calories (cal) per unit, by food id
     * @return array{
     *   harvests: list<array{sourceKey: string, plantId: string, cropId: string, kind: 'planted'|'wild_auto'|'wild_manual', offset: float, kcal: float}>,
     *   sources: list<array{key: string, plantId: string, cropId: string, kind: 'planted'|'wild_auto'|'wild_manual', count: int, stopped: int, kcalPerCycle: float}>,
     *   unknownCrops: list<string>,
     *   autoHarvestKnown: bool,
     * }
     */
    public static function build(array $plants, array $caloriesPerUnit): array
    {
        $harvests = [];
        $sources = [];
        $unknownCrops = [];
        $wildPlants = 0;
        $wildWithUnknownAutoHarvest = 0;

        foreach ($plants as $plant) {
            $cropId = is_string($plant['cropId'] ?? null) ? $plant['cropId'] : null;
            if ($cropId === null || ! array_key_exists($cropId, $caloriesPerUnit)) {
                if ($cropId !== null && ! in_array($cropId, $unknownCrops, true)) {
                    $unknownCrops[] = $cropId;
                }

                continue;
            }

            $plantId = is_string($plant['id'] ?? null) ? $plant['id'] : $cropId;
            $planted = ($plant['planted'] ?? false) === true;
            $kind = match (true) {
                $planted => 'planted',
                ($plant['autoHarvest'] ?? null) === true => 'wild_auto',
                default => 'wild_manual',
            };

            if (! $planted) {
                $wildPlants++;
                if (($plant['autoHarvest'] ?? null) === null) {
                    $wildWithUnknownAutoHarvest++;
                }
            }

            $key = "{$plantId}:{$kind}";
            $sources[$key] ??= ['key' => $key, 'plantId' => $plantId, 'cropId' => $cropId, 'kind' => $kind, 'count' => 0, 'stopped' => 0, 'kcalPerCycle' => 0.0];
            $sources[$key]['count']++;

            $grown = ($plant['grown'] ?? false) === true;
            $growthPerSecond = self::finite($plant['growthPerSecond'] ?? null);
            $secondsUntilHarvest = $grown ? 0.0 : self::finite($plant['secondsUntilHarvest'] ?? null);
            $cropUnits = self::finite($plant['cropUnits'] ?? null);
            $cropDurationSeconds = self::finite($plant['cropDurationSeconds'] ?? null);

            $period = self::period($cropDurationSeconds, $growthPerSecond, $planted);

            $wilting = ($plant['wilting'] ?? false) === true;
            $kcal = $cropUnits === null ? null : $cropUnits * $caloriesPerUnit[$cropId] / 1000;

            if ($wilting && $grown && $kcal !== null) {
                $sources[$key]['stopped']++;
                $harvests[] = ['sourceKey' => $key, 'plantId' => $plantId, 'cropId' => $cropId, 'kind' => $kind, 'offset' => 0.0, 'kcal' => $kcal];

                continue;
            }

            $stopped = $wilting
                || (! $grown && ($growthPerSecond === null || $growthPerSecond <= 0))
                || $secondsUntilHarvest === null
                || $kcal === null
                || ($period !== null && $period < self::MIN_PERIOD_SECONDS);

            if ($stopped) {
                $sources[$key]['stopped']++;

                continue;
            }

            if ($period !== null) {
                $sources[$key]['kcalPerCycle'] += $kcal / ($period / self::CYCLE_SECONDS);
            }

            $offset = max(0.0, $secondsUntilHarvest) / self::CYCLE_SECONDS;
            while ($offset <= self::HORIZON) {
                $harvests[] = ['sourceKey' => $key, 'plantId' => $plantId, 'cropId' => $cropId, 'kind' => $kind, 'offset' => $offset, 'kcal' => $kcal];

                if ($period === null) {
                    break;
                }

                $offset += $period / self::CYCLE_SECONDS;
            }
        }

        usort($harvests, fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return [
            'harvests' => $harvests,
            'sources' => array_values($sources),
            'unknownCrops' => $unknownCrops,
            'autoHarvestKnown' => $wildPlants === 0 || $wildWithUnknownAutoHarvest < $wildPlants,
        ];
    }

    /**
     * Seconds between two harvests, or null when the crop duration is unknown.
     */
    private static function period(?float $cropDurationSeconds, ?float $growthPerSecond, bool $planted): ?float
    {
        if ($cropDurationSeconds === null || $cropDurationSeconds <= 0) {
            return null;
        }

        if ($growthPerSecond === null || $growthPerSecond <= 0) {
            return $planted ? $cropDurationSeconds : self::WILD_SLOWDOWN * $cropDurationSeconds;
        }

        return ($cropDurationSeconds / self::CYCLE_SECONDS) / $growthPerSecond;
    }

    private static function finite(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null;
    }
}
