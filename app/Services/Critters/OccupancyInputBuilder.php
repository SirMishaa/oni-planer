<?php

declare(strict_types=1);

namespace App\Services\Critters;

use App\Data\GameRules\OccupancyInputData;
use App\Data\GameRules\OccupantData;
use App\Data\ZoneCritterData;
use App\Data\ZoneCrowdingRegionData;
use App\Data\ZoneEggData;
use App\Models\CritterMorph;
use Illuminate\Support\Str;

/**
 * Builds the planner's overcrowding input for one region the game reported, with the personal space of each
 * species read from `critter_morphs.overcrowding_threshold` (never hard-coded).
 */
final class OccupancyInputBuilder
{
    /** @var array<string, int|null> */
    private array $spaces = [];

    /** @var list<string> */
    private array $unknown = [];

    /**
     * Null when a prefab of the region has no morph: the planner cannot judge a region it does not know.
     *
     * @param  list<ZoneCritterData>  $critters  every critter of the zone; only those of the region are kept
     * @param  list<ZoneEggData>  $eggs  every egg of the zone; only those of the region are kept
     */
    public function forRegion(ZoneCrowdingRegionData $region, array $critters, array $eggs): ?OccupancyInputData
    {
        $this->unknown = [];
        $isPond = $region->kind === 'pond';

        $occupants = [];
        foreach ($critters as $critter) {
            if ($critter->regionId !== $region->id) {
                continue;
            }

            $space = $this->personalSpace($critter->prefabId);
            if ($space === null) {
                $this->markUnknown($critter->prefabId);

                continue;
            }

            $occupants[] = new OccupantData($critter->prefabId, $space, $critter->aquatic, false);
        }

        $hatching = [];
        foreach ($eggs as $egg) {
            if ($egg->regionId !== $region->id) {
                continue;
            }

            $species = $egg->spawns ?? $egg->prefabId;
            $space = $this->personalSpace($species) ?? $this->personalSpace(Str::beforeLast($species, 'Baby'));
            if ($space === null) {
                $this->markUnknown($species);

                continue;
            }

            $hatching[] = new OccupantData($species, $space, $isPond, false);
        }

        foreach (array_keys($region->states) as $prefabId) {
            if ($this->personalSpace((string) $prefabId) === null) {
                $this->markUnknown((string) $prefabId);
            }
        }

        if ($this->unknown !== []) {
            return null;
        }

        return new OccupancyInputData($region->cellCount, $isPond, $occupants, $hatching);
    }

    /** `Def.spaceRequiredPerCreature` of a species; null when the game data has no such morph. */
    public function personalSpace(string $prefabId): ?int
    {
        if (! array_key_exists($prefabId, $this->spaces)) {
            $threshold = CritterMorph::query()->where('morph_id', $prefabId)->value('overcrowding_threshold');
            $this->spaces[$prefabId] = $threshold === null ? null : (int) $threshold;
        }

        return $this->spaces[$prefabId];
    }

    /**
     * The prefabs the last {@see self::forRegion()} could not find.
     *
     * @return list<string>
     */
    public function unknownPrefabs(): array
    {
        return $this->unknown;
    }

    private function markUnknown(string $prefabId): void
    {
        if (! in_array($prefabId, $this->unknown, true)) {
            $this->unknown[] = $prefabId;
        }
    }
}
