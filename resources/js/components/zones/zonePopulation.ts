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
