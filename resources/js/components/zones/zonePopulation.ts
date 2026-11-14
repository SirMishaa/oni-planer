import type { ZonePopulationData } from '@/types/App/Data/data.d';

/** What a region shows as its state: the game's, Confined first (it suppresses the two others). */
export type PopulationState = 'confined' | 'overcrowded' | 'cramped';

/**
 * States of a region in display order. Confined suppresses Overcrowded and
 * Cramped in the game, so only it is shown when set; the raw flags stay in the data.
 */
export function populationStates(
    region: Pick<ZonePopulationData, 'confined' | 'overcrowded' | 'cramped'>,
): PopulationState[] {
    if (region.confined) {
        return ['confined'];
    }

    return [
        region.overcrowded ? ('overcrowded' as const) : null,
        region.cramped ? ('cramped' as const) : null,
    ].filter((state) => state !== null);
}

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
