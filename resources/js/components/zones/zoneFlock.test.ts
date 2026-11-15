import { describe, expect, it } from 'vitest';
import type { ZoneCritterData } from '@/types/App/Data/data.d';
import {
    bandId,
    cyclesToHatch,
    entitySpriteKind,
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
