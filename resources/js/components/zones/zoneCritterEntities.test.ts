import { describe, expect, it } from 'vitest';
import type { ZoneCritterData, ZoneEggData } from '@/types/App/Data/data.d';
import { critterEntities, EGG_SIZE_CELLS } from './zoneCritterEntities';

const box = { x: 170, y: 218, width: 12, height: 6 };
const gridWidth = 256;

function critter(overrides: Partial<ZoneCritterData> = {}): ZoneCritterData {
    return {
        instanceId: '1234',
        prefabId: 'Pacu',
        name: 'Pacu',
        sprite: '/assets/game/critters/Pacu.webp',
        cell: 221 * gridWidth + 176,
        x: 176.42,
        y: 221.0,
        facing: 'right',
        navType: 'Swim',
        moving: true,
        speed: 1,
        path: [],
        aquatic: true,
        tame: true,
        baby: false,
        ageCycles: 4,
        lifespanCycles: 25,
        caloriesRatio: 0.5,
        fertilityRatio: 0.2,
        breeding: [],
        happiness: 0,
        happinessState: 'neutral',
        stomach: [],
        nextPoop: null,
        hungry: false,
        statuses: [],
        regionId: 'pond:1234',
        ...overrides,
    } as ZoneCritterData;
}

function egg(overrides: Partial<ZoneEggData> = {}): ZoneEggData {
    return {
        instanceId: '5678',
        prefabId: 'PacuEgg',
        name: 'Pacu Egg',
        sprite: '/assets/game/eggs/PacuEgg.webp',
        cell: 219 * gridWidth + 175,
        incubation: 0.3,
        viability: 1,
        tame: true,
        spawns: 'PacuBaby',
        location: 'floor',
        holderId: null,
        regionId: 'pond:1234',
        incubationCycles: 2,
        ...overrides,
    };
}

describe('critterEntities', () => {
    it('places a critter at its fractional cell inside the box', () => {
        const [entity] = critterEntities([critter()], [], box, gridWidth);

        expect(entity).toMatchObject({
            key: 'c1234',
            instanceId: '1234',
            kind: 'critter',
            x: 176,
            y: 221,
            width: 1,
            height: 1,
            flipped: false,
        });
        // Centred on its x, standing on its y.
        expect(entity?.drawBox?.x).toBeCloseTo(175.92);
        expect(entity?.drawBox).toMatchObject({ y: 221, width: 1, height: 1 });
    });

    it('flips a critter facing left', () => {
        const [left, right] = critterEntities(
            [
                critter({ instanceId: '1', facing: 'left' }),
                critter({ instanceId: '2', facing: 'right' }),
            ],
            [],
            box,
            gridWidth,
        );

        expect(left?.flipped).toBe(true);
        expect(right?.flipped).toBe(false);
    });

    it('drops critters and eggs outside the box', () => {
        const entities = critterEntities(
            [
                critter({ instanceId: 'in' }),
                critter({ instanceId: 'out', x: 190.5, y: 221 }),
            ],
            [egg({ instanceId: 'egg-out', cell: 230 * gridWidth + 175 })],
            box,
            gridWidth,
        );

        expect(entities.map((entity) => entity.instanceId)).toEqual(['in']);
    });

    it('anchors an egg on its cell floor', () => {
        const [entity] = critterEntities([], [egg()], box, gridWidth);

        expect(entity).toMatchObject({
            key: 'e5678',
            kind: 'egg',
            x: 175,
            y: 219,
        });
        expect(entity?.drawBox).toEqual({
            x: 175 + (1 - EGG_SIZE_CELLS) / 2,
            y: 219,
            width: EGG_SIZE_CELLS,
            height: EGG_SIZE_CELLS,
        });
    });

    it('leaves out eggs kept in a storage or an incubator', () => {
        const entities = critterEntities(
            [],
            [
                egg({ instanceId: 'stored', location: 'storage' }),
                egg({ instanceId: 'incubated', location: 'incubator' }),
            ],
            box,
            gridWidth,
        );

        expect(entities).toEqual([]);
    });
});
