import type { ZonePopulationData } from '@/types/App/Data/data.d';

/** Breeding stops in a cramped or confined region. */
export function breedingStopped(
    population: ZonePopulationData[] | null,
): boolean {
    return (population ?? []).some(
        (region) => region.cramped || region.confined,
    );
}

/** Shares of the gauge width taken by the critters, the hatching eggs and the limit marker. */
export function populationGauge(
    region: Pick<ZonePopulationData, 'cellCount' | 'occupied' | 'eggOccupied'>,
): { critters: number; eggs: number; limit: number } {
    const scale = Math.max(
        region.cellCount,
        region.occupied + region.eggOccupied,
        1,
    );

    return {
        critters: (region.occupied / scale) * 100,
        eggs: (region.eggOccupied / scale) * 100,
        limit: (region.cellCount / scale) * 100,
    };
}

/**
 * How the removal of a region reads: each species of `perType` is an alternative, so the rows need to say whether
 * one of them alone is enough, else how many cells to free. Null when nothing is to be removed.
 */
export function removalOptions(
    region: Pick<
        ZonePopulationData,
        'perType' | 'cellCount' | 'occupied' | 'eggOccupied' | 'overcrowded'
    >,
): { anyFixes: boolean; overOccupiedCells: number } | null {
    const types = region.perType.filter((type) => type.toRemove > 0);
    if (types.length === 0) {
        return null;
    }

    const withEggs = region.perType[0]?.derived ?? !region.overcrowded;

    return {
        anyFixes: types.some((type) => type.canFix),
        overOccupiedCells: Math.max(
            0,
            region.occupied +
                (withEggs ? region.eggOccupied : 0) -
                region.cellCount,
        ),
    };
}
