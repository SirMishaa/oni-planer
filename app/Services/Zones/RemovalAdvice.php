<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Data\GameRules\CritterOverOccupancyData;
use App\Data\ZonePopulationData;

/**
 * The removal advice of a crowded cavity or pond, as one sentence.
 *
 * Each species of `perType` is an alternative (`RegionAnalysis.BuildCritterOccupancies`): removing that many of it
 * alone fixes the region. The sentence joins the species that can fix it with "or", names apart the ones that cannot
 * (`canFix` false), and gives the cells to free when no single species is enough.
 */
final class RemovalAdvice
{
    /**
     * Null when nothing is to be removed.
     *
     * @param  string|null  $locale  the MCP text stays in English, the app follows the user's locale
     */
    public static function text(ZonePopulationData $region, ?string $locale = null): ?string
    {
        $types = array_values(array_filter($region->perType, static fn (CritterOverOccupancyData $type): bool => $type->toRemove > 0));
        if ($types === []) {
            return null;
        }

        $names = array_column($region->types, 'name', 'prefabId');
        $named = static fn (CritterOverOccupancyData $type): string => $type->toRemove.' '.($names[$type->prefabId] ?? $type->prefabId);
        $fixing = array_filter($types, static fn (CritterOverOccupancyData $type): bool => $type->canFix);
        $short = array_filter($types, static fn (CritterOverOccupancyData $type): bool => ! $type->canFix);

        if ($fixing === []) {
            $text = trans_choice('zones.population.none_alone', self::overOccupiedCells($region), [], $locale);
        } else {
            $text = __('zones.population.remove', [
                'options' => implode(' '.__('zones.population.or', [], $locale).' ', array_map($named, $fixing)),
            ], $locale);
            if ($short !== []) {
                $text .= ' — '.implode(', ', array_map(
                    static fn (CritterOverOccupancyData $type): string => trans_choice('zones.population.not_enough', $type->toRemove, [
                        'name' => $names[$type->prefabId] ?? $type->prefabId,
                    ], $locale),
                    $short,
                ));
            }
        }

        return array_any($types, static fn (CritterOverOccupancyData $type): bool => $type->derived) ? $text.' ('.__('zones.population.derived', [], $locale).')' : $text;
    }

    /**
     * The cells over the region's capacity: the critters alone when overcrowded, plus the hatching eggs when cramped
     * (the planner's derived removal).
     */
    public static function overOccupiedCells(ZonePopulationData $region): int
    {
        $withEggs = $region->perType[0]->derived ?? ! $region->overcrowded;

        return max(0, $region->occupied + ($withEggs ? $region->eggOccupied : 0) - $region->cellCount);
    }
}
