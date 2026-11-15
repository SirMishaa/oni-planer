import { describe, expect, it } from 'vitest';
import type {
    ZoneCritterData,
    ZoneFeedingData,
    ZoneFeedingLineData,
} from '@/types/App/Data/data.d';
import {
    amountDigits,
    bandId,
    cyclesToHatch,
    entitySpriteKind,
    feedingChip,
    feedingFoods,
    groupFlock,
    individualAlerts,
    isGroupAlert,
    sortByMetric,
} from './zoneFlock';

let nextId = 1;

/** A critter shaped like the wire format, with the values a test does not care about left neutral. */
function critter(overrides: Partial<ZoneCritterData> = {}): ZoneCritterData {
    return {
        instanceId: String(nextId++),
        prefabId: 'Pacu',
        name: 'Pacu',
        sprite: '/assets/game/critters/Pacu.webp',
        cell: 1000,
        x: 10,
        y: 20,
        facing: 'left',
        navType: 'Swim',
        moving: false,
        speed: 1,
        path: [],
        aquatic: true,
        tame: true,
        baby: false,
        ageCycles: 10,
        lifespanCycles: 25,
        caloriesRatio: 0.7,
        fertilityRatio: 0.5,
        breeding: [],
        happiness: 0,
        happinessState: 'neutral',
        stomach: [],
        nextPoop: null,
        hungry: false,
        statuses: [],
        regionId: 'pond:1',
        ...overrides,
    };
}

const cramped = { id: 'Cramped', name: "À l'étroit", bad: true };
const hungry = { id: 'Hungry', name: 'Affamé', bad: true };

describe('groupFlock', () => {
    it('groups by prefab and stage, largest first', () => {
        const groups = groupFlock([
            critter({ baby: true, prefabId: 'PacuBaby' }),
            critter(),
            critter({ tame: false }),
            critter(),
            critter({ prefabId: 'PacuTropical', tame: false }),
            critter({ tame: false }),
            critter(),
        ]);

        expect(
            groups.map((group) => [group.key, group.critters.length]),
        ).toEqual([
            ['Pacu:adult', 3],
            ['Pacu:wild', 2],
            ['PacuBaby:baby', 1],
            ['PacuTropical:wild', 1],
        ]);
        expect(groups[0]).toMatchObject({
            prefabId: 'Pacu',
            name: 'Pacu',
            stage: 'adult',
        });
    });

    it('calls a wild baby a baby', () => {
        const [group] = groupFlock([critter({ baby: true, tame: false })]);

        expect(group.stage).toBe('baby');
    });
});

describe('alerts', () => {
    it('a status on every critter is a group alert, not an individual one', () => {
        const [group] = groupFlock([
            critter({ statuses: [cramped] }),
            critter({ statuses: [cramped, hungry] }),
            critter({ statuses: [cramped] }),
        ]);

        expect(group.groupAlerts).toEqual([
            { name: "À l'étroit", count: 3 },
            { name: 'Affamé', count: 1 },
        ]);
        expect(isGroupAlert(group, "À l'étroit")).toBe(true);
        expect(isGroupAlert(group, 'Affamé')).toBe(false);
    });

    it('individual alerts exclude group alerts', () => {
        const starving = critter({
            statuses: [
                cramped,
                hungry,
                { id: 'Groomed', name: 'Toiletté', bad: false },
            ],
        });
        const [group] = groupFlock([
            critter({ statuses: [cramped] }),
            starving,
        ]);

        expect(individualAlerts(group, starving)).toEqual(['Affamé']);
        expect(individualAlerts(group, group.critters[0])).toEqual([]);
    });
});

describe('sortByMetric', () => {
    it('sorts by metric with unknown values last', () => {
        const low = critter({ fertilityRatio: 0.2 });
        const unknown = critter({ fertilityRatio: null });
        const high = critter({ fertilityRatio: 0.9 });
        const input = [low, unknown, high];

        expect(sortByMetric(input, 'fertility')).toEqual([high, low, unknown]);
        expect(input).toEqual([low, unknown, high]);
    });

    it('measures the growth of babies only, against their lifespan', () => {
        const baby = critter({ baby: true, ageCycles: 2, lifespanCycles: 5 });
        const adult = critter({ ageCycles: 20 });

        expect(sortByMetric([adult, baby], 'growth')).toEqual([baby, adult]);
    });
});

describe('cyclesToHatch', () => {
    it('computes cycles to hatch from incubation', () => {
        expect(cyclesToHatch({ incubation: 40 }, 5)).toBeCloseTo(3);
        expect(cyclesToHatch({ incubation: 100 }, 5)).toBe(0);
    });
});

describe('entitySpriteKind', () => {
    it('draws critter problems with the critter placeholder', () => {
        expect(entitySpriteKind('critter')).toBe('critter');
        expect(entitySpriteKind('critter_group')).toBe('critter');
        expect(entitySpriteKind('plant')).toBe('plant');
        expect(entitySpriteKind('building')).toBe('building');
    });
});

describe('bandId', () => {
    it('is a stable element id for a flock band', () => {
        expect(bandId('Pacu:adult')).toBe('zone-band-Pacu:adult');
    });
});

function line(
    overrides: Partial<ZoneFeedingLineData> = {},
): ZoneFeedingLineData {
    return {
        resource: 'Algae',
        name: 'Algues',
        perCycle: 285,
        kind: 'item',
        inZone: null,
        ...overrides,
    };
}

function feeding(overrides: Partial<ZoneFeedingData> = {}): ZoneFeedingData {
    return {
        groupKey: 'Pacu:adult',
        count: 38,
        foods: [line()],
        otherFoods: 0,
        produces: [],
        availableKg: 456,
        deficitKgPerCycle: null,
        autonomyCycles: 1.6,
        derived: true,
        ...overrides,
    };
}

describe('feedingChip', () => {
    it('tones the stock by the cycles it lasts', () => {
        expect(feedingChip(feeding({ autonomyCycles: 3 }))).toEqual({
            kind: 'stock',
            cycles: 3,
            tone: 'ok',
        });
        expect(feedingChip(feeding())).toMatchObject({ tone: 'warn' });
        expect(feedingChip(feeding({ autonomyCycles: 0.4 }))).toMatchObject({
            tone: 'bad',
        });
    });

    it('shows the need as missing without stock', () => {
        expect(
            feedingChip(
                feeding({
                    availableKg: 0,
                    deficitKgPerCycle: 285,
                    autonomyCycles: 0,
                }),
            ),
        ).toEqual({ kind: 'missing', kgPerCycle: 285 });
    });

    it('says the stock is unknown while the feeders were not read', () => {
        expect(
            feedingChip(
                feeding({
                    availableKg: null,
                    deficitKgPerCycle: null,
                    autonomyCycles: null,
                }),
            ),
        ).toEqual({ kind: 'unknown' });
    });

    it('shows nothing for a plant or prey eater', () => {
        expect(
            feedingChip(
                feeding({
                    foods: [line({ kind: 'plant', inZone: 12 })],
                    availableKg: null,
                    autonomyCycles: null,
                }),
            ),
        ).toBeNull();
        expect(
            feedingChip(
                feeding({
                    foods: [line({ kind: 'plant', inZone: 12 })],
                    availableKg: 0,
                    autonomyCycles: null,
                }),
            ),
        ).toBeNull();
    });
});

describe('feedingFoods', () => {
    it('splits the shown foods into items, plants and prey', () => {
        const kelp = line({ resource: 'Kelp', name: 'Varech' });
        const spice = line({ resource: 'SpiceVine', kind: 'plant' });
        const pacu = line({ resource: 'Pacu', kind: 'prey' });

        expect(
            feedingFoods(feeding({ foods: [line(), kelp, spice, pacu] })),
        ).toEqual({
            items: [line(), kelp],
            plants: [spice],
            prey: [pacu],
        });
    });
});

describe('amountDigits', () => {
    it('keeps a small amount from rounding to zero', () => {
        expect(amountDigits(285)).toBe(0);
        expect(amountDigits(41.25)).toBe(1);
        expect(amountDigits(0.55)).toBe(2);
        expect(amountDigits(0.05)).toBe(3);
        expect(amountDigits(0.011)).toBe(3);
        expect(amountDigits(0)).toBe(0);
    });
});
