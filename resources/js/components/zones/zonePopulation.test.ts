import { describe, expect, it } from 'vitest';
import {
    breedingStopped,
    populationGauge,
    populationStates,
} from './zonePopulation';

describe('populationStates', () => {
    it('shows confined alone: it suppresses the two others', () => {
        expect(
            populationStates({
                confined: true,
                overcrowded: true,
                cramped: true,
            }),
        ).toEqual(['confined']);
    });

    it('lists the other states in order', () => {
        expect(
            populationStates({
                confined: false,
                overcrowded: true,
                cramped: true,
            }),
        ).toEqual(['overcrowded', 'cramped']);
        expect(
            populationStates({
                confined: false,
                overcrowded: false,
                cramped: false,
            }),
        ).toEqual([]);
    });
});

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
