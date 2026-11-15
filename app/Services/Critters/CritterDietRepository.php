<?php

declare(strict_types=1);

namespace App\Services\Critters;

/**
 * The diet and output rows of a critter morph, as plain arrays, so the feeding rule stays free of the database.
 *
 * Amounts are per critter. A diet's `kgPerCycle` is what one critter eats per cycle when that food alone feeds it,
 * and `conversionRatio` is kg produced per kg eaten. An output's `perCycle` is in its own unit (eggs per cycle).
 */
interface CritterDietRepository
{
    /**
     * @return list<array{resource: string, kgPerCycle: float, produces: string|null, conversionRatio: float|null}>
     */
    public function diets(string $morphId): array;

    /**
     * @return list<array{resource: string, perCycle: float, kind: string}>
     */
    public function outputs(string $morphId): array;
}
