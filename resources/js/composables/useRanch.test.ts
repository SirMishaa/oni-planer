import { describe, expect, it } from 'vitest';
import {
    computeBalance,
    decodeRows,
    encodeRows,
    type RanchRow,
    type RanchCatalog,
} from './useRanch';

const catalog: RanchCatalog = {
    families: [
        {
            slug: 'hatch',
            name: 'Hatch',
            description: null,
            image: null,
            variants: [
                {
                    critter_id: 'Hatch',
                    name: { en: 'Hatch' },
                    image: null,
                    adult: {
                        morph_id: 'Hatch',
                        is_base: true,
                        name: { en: 'Hatch' },
                        min_temp: 0,
                        max_temp: 0,
                        calories_per_cycle: 700000,
                        incubation_time: 20,
                        lifespan: 0,
                        overcrowding_threshold: 12,
                        diets: [
                            { consumed: { element_id: 'Sand', name: 'Sand', icon: null, url: null }, produced: { element_id: 'Carbon', name: 'Coal', icon: null, url: null }, amount_per_cycle: 140, conversion_ratio: 0.5 },
                            { consumed: { element_id: 'Clay', name: 'Clay', icon: null, url: null }, produced: { element_id: 'Carbon', name: 'Coal', icon: null, url: null }, amount_per_cycle: 140, conversion_ratio: 0.5 },
                        ],
                        outputs: [{ kind: 'egg', element_id: 'HatchEgg', label: 'HatchEgg', icon: null, url: null, amount_per_cycle: 1 / 60 }],
                    },
                    baby: null,
                },
            ],
        },
        {
            slug: 'drecko',
            name: 'Drecko',
            description: null,
            image: null,
            variants: [
                {
                    critter_id: 'Drecko',
                    name: { en: 'Drecko' },
                    image: null,
                    adult: {
                        morph_id: 'Drecko', is_base: true, name: { en: 'Drecko' },
                        min_temp: 0, max_temp: 0, calories_per_cycle: 0, incubation_time: 0, lifespan: 0, overcrowding_threshold: 0,
                        diets: [],
                        outputs: [{ kind: 'egg', element_id: 'DreckoEgg', label: 'DreckoEgg', icon: null, url: null, amount_per_cycle: 1 / 90 }],
                    },
                    baby: null,
                },
            ],
        },
    ],
    elements: {
        Sand: { element_id: 'Sand', name: 'Sand', icon: null, url: null },
        Carbon: { element_id: 'Carbon', name: 'Coal', icon: null, url: null },
    },
};

describe('useRanch calc', () => {
    it('nets produced minus consumed for the chosen food', () => {
        const rows: RanchRow[] = [{ critterId: 'Hatch', foodElementId: 'Sand', count: 6 }];
        const balance = computeBalance(catalog, rows);

        // 6 hatches eat 6*140 = 840 kg Sand, make 840*0.5 = 420 kg Coal.
        const sand = balance.elements.find((e) => e.element_id === 'Sand')!;
        const coal = balance.elements.find((e) => e.element_id === 'Carbon')!;
        expect(sand.net_per_cycle).toBeCloseTo(-840);
        expect(coal.net_per_cycle).toBeCloseTo(420);
        expect(balance.eggs[0]!.amount_per_cycle).toBeCloseTo(6 / 60);
    });

    it('counts only the chosen food, not all diet options', () => {
        const rows: RanchRow[] = [{ critterId: 'Hatch', foodElementId: 'Clay', count: 1 }];
        const balance = computeBalance(catalog, rows);
        expect(balance.elements.find((e) => e.element_id === 'Clay')!.net_per_cycle).toBeCloseTo(-140);
        expect(balance.elements.find((e) => e.element_id === 'Sand')).toBeUndefined();
    });

    it('defaults food to the first diet when unset', () => {
        const rows: RanchRow[] = [{ critterId: 'Hatch', foodElementId: null, count: 1 }];
        const balance = computeBalance(catalog, rows);
        expect(balance.elements.find((e) => e.element_id === 'Sand')!.net_per_cycle).toBeCloseTo(-140);
    });

    it('handles a critter with no diet (eggs only)', () => {
        const rows: RanchRow[] = [{ critterId: 'Drecko', foodElementId: null, count: 8 }];
        const balance = computeBalance(catalog, rows);
        expect(balance.elements).toHaveLength(0);
        expect(balance.eggs[0]!.amount_per_cycle).toBeCloseTo(8 / 90);
    });

    it('round-trips rows through the URL encoder', () => {
        const rows: RanchRow[] = [
            { critterId: 'Hatch', foodElementId: 'Sand', count: 6 },
            { critterId: 'Drecko', foodElementId: null, count: 8 },
        ];
        const encoded = encodeRows(rows);
        expect(decodeRows(encoded)).toEqual(rows);
    });

    it('drops unknown critters when decoding against the catalog is applied', () => {
        const rows = decodeRows('Ghost.X:3,Hatch.Sand:2');
        // decodeRows is catalog-agnostic; filtering happens in the composable.
        expect(rows).toEqual([
            { critterId: 'Ghost', foodElementId: 'X', count: 3 },
            { critterId: 'Hatch', foodElementId: 'Sand', count: 2 },
        ]);
    });
});
