<?php

declare(strict_types=1);

namespace App\Services\Zones;

/** Readings of a fast-state plant shared by the zone page and the MCP server. */
final class ZonePlants
{
    /** Game seconds in a cycle; a plant's maturity is counted in cycles. */
    private const float CYCLE_SECONDS = 600.0;

    /**
     * Progress to the next harvest, from the remaining time, the growth rate
     * (maturity per second, maturity counted in cycles) and the crop duration
     * at the normal rate: 1 - remaining maturity / full maturity.
     *
     * @param  array<mixed>  $plant
     */
    public static function growth(array $plant): ?float
    {
        if (($plant['grown'] ?? false) === true) {
            return 1.0;
        }

        $seconds = is_numeric($plant['secondsUntilHarvest'] ?? null) ? (float) $plant['secondsUntilHarvest'] : null;
        $rate = is_numeric($plant['growthPerSecond'] ?? null) ? (float) $plant['growthPerSecond'] : null;
        $duration = is_numeric($plant['cropDurationSeconds'] ?? null) ? (float) $plant['cropDurationSeconds'] : null;

        if ($seconds === null || $rate === null || $duration === null || $duration <= 0) {
            return null;
        }

        return max(0.0, min(1.0, 1 - $seconds * $rate * self::CYCLE_SECONDS / $duration));
    }
}
