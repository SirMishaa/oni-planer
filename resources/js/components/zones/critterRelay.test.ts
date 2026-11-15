import { gzipSync } from 'node:zlib';
import { describe, expect, it } from 'vitest';
import type { ZoneCritterData } from '@/types/App/Data/data.d';
import {
    decodeUplinkPayload,
    frameIsAhead,
    overlayRelay,
    readFastFrame,
    reloadMovesCritters,
} from './critterRelay';

const target = { colonyGuid: 'colony-a', zoneGuid: 'zone-a', schema: 1 };

/** A critter row as the mod sends it (zone 64, trimmed). */
function row(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        instanceId: '731359',
        prefabId: 'PufferFish',
        cell: 145003,
        x: 169.5,
        y: 239.01,
        facing: 'right',
        navType: 'Swim',
        moving: true,
        speed: 2.0,
        path: [{ cell: 145004, navType: 'Swim' }],
        aquatic: true,
        tame: true,
        statuses: [{ id: 'Happy', bad: false }],
        ...overrides,
    };
}

function message(
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        schema: 1,
        colonyId: 'colony-a',
        zoneId: 'zone-a',
        sessionId: 'session-a',
        gameSeconds: 120.5,
        plants: [],
        critters: [row()],
        ...overrides,
    };
}

function critter(overrides: Partial<ZoneCritterData> = {}): ZoneCritterData {
    return {
        instanceId: '731359',
        prefabId: 'PufferFish',
        name: 'Puft',
        sprite: null,
        cell: 145000,
        x: 166.5,
        y: 239,
        facing: 'left',
        navType: 'Swim',
        moving: false,
        speed: 2,
        path: [],
        aquatic: true,
        tame: true,
        baby: false,
        ageCycles: 4,
        lifespanCycles: 25,
        caloriesRatio: 0.5,
        fertilityRatio: 0.2,
        breeding: [],
        happiness: 4,
        happinessState: 'happy',
        stomach: [],
        nextPoop: null,
        hungry: false,
        statuses: [{ id: 'Happy', name: 'Heureux', bad: false }],
        regionId: 'pond:683355',
        ...overrides,
    } as ZoneCritterData;
}

const stored = (gameSeconds: number | null, sessionId = 'session-a') => ({
    sessionId,
    gameSeconds,
});

describe('decodeUplinkPayload', () => {
    const json = JSON.stringify(message());

    it('reads the base64 of gzip the mod sends', async () => {
        const payload = gzipSync(json).toString('base64');

        expect(await decodeUplinkPayload({ payload })).toEqual(message());
    });

    it('reads plain JSON, base64 JSON and an object', async () => {
        expect(await decodeUplinkPayload(json)).toEqual(message());
        expect(
            await decodeUplinkPayload({
                payload: Buffer.from(json).toString('base64'),
            }),
        ).toEqual(message());
        expect(await decodeUplinkPayload(message())).toEqual(message());
    });

    it('returns null for what it cannot read', async () => {
        expect(await decodeUplinkPayload({ payload: '%%%' })).toBeNull();
        expect(await decodeUplinkPayload('[1, 2]')).toBeNull();
        expect(await decodeUplinkPayload(42)).toBeNull();
    });
});

describe('readFastFrame', () => {
    it('maps the motion of each critter as the server does', () => {
        const frame = readFastFrame(message(), target);

        expect(frame).toMatchObject({
            sessionId: 'session-a',
            gameSeconds: 120.5,
        });
        expect(frame?.critters.get('731359')).toEqual({
            instanceId: '731359',
            cell: 145003,
            x: 169.5,
            y: 239.01,
            facing: 'right',
            navType: 'Swim',
            moving: true,
            speed: 2,
            path: [{ cell: 145004, navType: 'Swim' }],
            aquatic: true,
        });
    });

    it('applies the server defaults to missing fields', () => {
        const frame = readFastFrame(
            message({
                critters: [
                    {
                        instanceId: 42,
                        prefabId: 'Hatch',
                        cell: 10,
                        x: '1.5',
                        y: 2,
                        facing: 'up',
                        path: [{ cell: 11 }, { cell: 'x' }],
                    },
                ],
            }),
            target,
        );

        expect(frame?.critters.get('42')).toEqual({
            instanceId: '42',
            cell: 10,
            x: 1.5,
            y: 2,
            facing: 'left',
            navType: '',
            moving: false,
            speed: 0,
            path: [{ cell: 11, navType: '' }],
            aquatic: false,
        });
    });

    it('skips the rows the server skips', () => {
        const frame = readFastFrame(
            message({
                critters: [
                    row({ instanceId: '' }),
                    row({ instanceId: '2', prefabId: null }),
                    row({ instanceId: '3', cell: 1.5 }),
                    row({ instanceId: '4', x: null }),
                    'bad',
                    row({ instanceId: '5' }),
                ],
            }),
            target,
        );

        expect([...(frame?.critters.keys() ?? [])]).toEqual(['5']);
    });

    it('keeps only the frames of the zone, in a schema it reads', () => {
        expect(readFastFrame(message({ zoneId: 'zone-b' }), target)).toBeNull();
        expect(
            readFastFrame(message({ colonyId: 'colony-b' }), target),
        ).toBeNull();
        expect(readFastFrame(message({ schema: 2 }), target)).toBeNull();
        expect(readFastFrame(message({ schema: 0 }), target)).toBeNull();
        expect(readFastFrame(message({ schema: '1' }), target)).toBeNull();
        expect(
            readFastFrame(message({ gameSeconds: undefined }), target),
        ).toBeNull();
        expect(readFastFrame(message({ critters: null }), target)).toBeNull();
    });

    it('reads a frame without session as the current session', () => {
        expect(
            readFastFrame(message({ sessionId: undefined }), target)?.sessionId,
        ).toBeNull();
    });
});

describe('relay ordering', () => {
    const frame = readFastFrame(message(), target)!;

    it('applies a frame newer than the stored state of the same session', () => {
        expect(frameIsAhead(frame, stored(100))).toBe(true);
        expect(frameIsAhead(frame, stored(null))).toBe(true);
        expect(frameIsAhead(frame, stored(120.5))).toBe(false);
        expect(frameIsAhead(frame, stored(200))).toBe(false);
        expect(frameIsAhead(frame, stored(100, 'session-b'))).toBe(false);
        expect(
            frameIsAhead({ ...frame, sessionId: null }, stored(100, 'x')),
        ).toBe(true);
    });

    it('lets a reload move critters only when newer than the frame applied', () => {
        expect(reloadMovesCritters(stored(120.5), frame)).toBe(false);
        expect(reloadMovesCritters(stored(100), frame)).toBe(false);
        expect(reloadMovesCritters(stored(125), frame)).toBe(true);
        expect(reloadMovesCritters(stored(100, 'session-b'), frame)).toBe(true);
        expect(reloadMovesCritters(stored(100), null)).toBe(true);
    });
});

describe('overlayRelay', () => {
    const frame = readFastFrame(
        message({ critters: [row(), row({ instanceId: '999' })] }),
        target,
    )!;

    it('moves the known critters and keeps the rest of their data', () => {
        const [moved, other] = overlayRelay(
            [critter(), critter({ instanceId: '1' })],
            frame,
            stored(100),
        );

        expect(moved).toMatchObject({
            x: 169.5,
            cell: 145003,
            facing: 'right',
            moving: true,
            path: [{ cell: 145004, navType: 'Swim' }],
            name: 'Puft',
            statuses: [{ id: 'Happy', name: 'Heureux', bad: false }],
        });
        expect(other).toMatchObject({ instanceId: '1', x: 166.5 });
    });

    it('leaves new critters for the reload', () => {
        const critters = overlayRelay([critter()], frame, stored(100));

        expect(critters.map((each) => each.instanceId)).toEqual(['731359']);
    });

    it('steps aside once the stored state caught up', () => {
        const critters = [critter()];

        expect(overlayRelay(critters, frame, stored(120.5))).toBe(critters);
        expect(overlayRelay(critters, null, stored(100))).toBe(critters);
    });
});
