import { describe, expect, it } from 'vitest';
import {
    breedingStopped,
    populationGauge,
    removalOptions,
} from './zonePopulation';

describe('breedingStopped', () => {
    it('is false while unknown', () => {
        expect(breedingStopped(null)).toBe(false);
    });
});

describe('populationGauge', () => {
    it('puts the limit at the end while the region has room', () => {
        const gauge = populationGauge({
            cellCount: 100,
            occupied: 50,
            eggOccupied: 10,
        });

        expect(gauge).toEqual({ critters: 50, eggs: 10, limit: 100 });
    });

    it('rescales when the critters and eggs exceed the region', () => {
        const gauge = populationGauge({
            cellCount: 104,
            occupied: 88,
            eggOccupied: 24,
        });

        expect(gauge.critters + gauge.eggs).toBeCloseTo(100);
        expect(gauge.limit).toBeCloseTo((104 / 112) * 100);
    });
});

describe('removalOptions', () => {
    const pond = {
        cellCount: 203,
        occupied: 168,
        eggOccupied: 48,
        overcrowded: false,
    };
    const option = (prefabId: string, toRemove: number, canFix: boolean) => ({
        prefabId,
        count: 4,
        personalSpace: 8,
        toRemove,
        canFix,
        derived: true,
    });

    it('is null when nothing is to be removed', () => {
        expect(removalOptions({ ...pond, perType: [] })).toBeNull();
    });

    it('tells whether one species alone is enough', () => {
        expect(
            removalOptions({
                ...pond,
                perType: [
                    option('Pacu', 2, true),
                    option('SeaHorse', 1, false),
                ],
            }),
        ).toEqual({ anyFixes: true, overOccupiedCells: 13 });
    });

    it('gives the cells to free when no species alone is enough', () => {
        expect(
            removalOptions({
                ...pond,
                perType: [option('SeaHorse', 1, false)],
            }),
        ).toEqual({ anyFixes: false, overOccupiedCells: 13 });
    });

    it('counts only the critters of an overcrowded region', () => {
        expect(
            removalOptions({
                ...pond,
                occupied: 210,
                overcrowded: true,
                perType: [{ ...option('Pacu', 1, true), derived: false }],
            })?.overOccupiedCells,
        ).toBe(7);
    });
});
