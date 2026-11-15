import { describe, expect, it } from 'vitest';
import { breedingStopped, populationGauge } from './zonePopulation';

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
