<?php

declare(strict_types=1);

namespace App\Services\Critters;

use App\Data\ZoneCritterData;

/**
 * The key of the flock band a critter belongs to: its prefab and its stage. The same rule as
 * `groupFlock` in `resources/js/components/zones/zoneFlock.ts`: a baby stays a baby whether tame
 * or not, an adult is wild until tamed.
 */
final class CritterGroupKey
{
    public static function of(ZoneCritterData $critter): string
    {
        $stage = $critter->baby ? 'baby' : ($critter->tame ? 'adult' : 'wild');

        return "{$critter->prefabId}:{$stage}";
    }
}
