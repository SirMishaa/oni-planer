import { gzipSync } from 'node:zlib';
import { describe, expect, it } from 'vitest';
import type { ZoneCritterData } from '@/types/App/Data/data.d';
import {
    applyFastFrame,
    applyReload,
    applyTripFrame,
    decodeUplinkPayload,
    frameIsAhead,
    overlayBook,
    readFastFrame,
    readTripFrame,
    type RelayBook,
    sampleOverridesTrip,
    tripsFrameConcerns,
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

describe('frameIsAhead', () => {
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
});

// region Trips

const gridWidth = 606;
const box = { x: 160, y: 230, width: 30, height: 15 };
const cellAt = (x: number, y: number): number => y * gridWidth + x;

/** A trip entry as the mod sends it: from (169, 239) three cells right at 2 cells/s. */
function tripRow(
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        instanceId: '731359',
        kind: 'trip',
        cell: cellAt(169, 239),
        x: 169.5,
        y: 239,
        navType: 'Swim',
        speed: 2,
        facing: 'right',
        path: [170, 171, 172].map((x) => ({
            cell: cellAt(x, 239),
            navType: 'Swim',
        })),
        ...overrides,
    };
}

function tripsMessage(
    trips: unknown[] = [tripRow()],
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        schema: 1,
        colonyId: 'colony-a',
        sessionId: 'session-a',
        gameSeconds: 130,
        zones: [
            { zoneId: 'zone-b', trips: [tripRow({ instanceId: 'elsewhere' })] },
            { zoneId: 'zone-a', trips },
        ],
        ...overrides,
    };
}

const tripFrame = (
    trips: unknown[] = [tripRow()],
    overrides: Record<string, unknown> = {},
) => readTripFrame(tripsMessage(trips, overrides), target)!;

const overrides = (
    sample: Parameters<typeof sampleOverridesTrip>[0],
    trip: Parameters<typeof sampleOverridesTrip>[1],
    now: number,
) => sampleOverridesTrip(sample, trip, now, gridWidth, box);

/** A fast frame at `gameSeconds` with the critter sampled at `x` (still unless a path is given). */
function sample(gameSeconds: number, critterRow: Record<string, unknown>) {
    return readFastFrame(
        message({
            gameSeconds,
            critters: [row({ moving: false, path: [], ...critterRow })],
        }),
        target,
    )!;
}

describe('tripsFrameConcerns', () => {
    it('skips a frame whose zone ids leave the zone out, before decoding', () => {
        expect(
            tripsFrameConcerns(
                { zoneIds: ['zone-b', 'zone-a'], payload: '{}' },
                'zone-a',
            ),
        ).toBe(true);
        expect(
            tripsFrameConcerns(
                { zoneIds: ['zone-b'], payload: '{}' },
                'zone-a',
            ),
        ).toBe(false);
        expect(tripsFrameConcerns({ payload: '{}' }, 'zone-a')).toBe(false);
    });
});

describe('readTripFrame', () => {
    it('maps a trip to a walk along its whole path, a stop to a rest', () => {
        const frame = tripFrame([
            tripRow(),
            tripRow({
                instanceId: '42',
                kind: 'stop',
                cell: cellAt(172, 239),
                x: 172.5,
                facing: 'left',
                path: undefined,
            }),
        ]);

        expect(frame).toMatchObject({
            sessionId: 'session-a',
            gameSeconds: 130,
        });
        expect(frame.trips.get('731359')).toEqual({
            instanceId: '731359',
            cell: cellAt(169, 239),
            x: 169.5,
            y: 239,
            facing: 'right',
            navType: 'Swim',
            moving: true,
            speed: 2,
            path: [170, 171, 172].map((x) => ({
                cell: cellAt(x, 239),
                navType: 'Swim',
            })),
        });
        expect(frame.trips.get('42')).toMatchObject({
            x: 172.5,
            facing: 'left',
            moving: false,
            path: [],
        });
        expect(frame.trips.has('elsewhere')).toBe(false);
    });

    it('skips the entries it cannot read', () => {
        const frame = tripFrame([
            tripRow({ instanceId: '1', kind: 'wander' }),
            tripRow({ instanceId: '2', cell: null }),
            tripRow({ instanceId: '3', x: 'far' }),
            tripRow({ instanceId: '4' }),
        ]);

        expect([...frame.trips.keys()]).toEqual(['4']);
    });

    it('keeps only the frames of the colony, in a schema it reads', () => {
        expect(
            readTripFrame(
                tripsMessage(undefined, { colonyId: 'colony-b' }),
                target,
            ),
        ).toBeNull();
        expect(
            readTripFrame(tripsMessage(undefined, { schema: 2 }), target),
        ).toBeNull();
        expect(
            readTripFrame(
                tripsMessage(undefined, { gameSeconds: null }),
                target,
            ),
        ).toBeNull();
        expect(
            readTripFrame(tripsMessage(undefined, { zones: null }), target),
        ).toBeNull();
    });
});

describe('relay book', () => {
    it('lets a trip newer than the last fast frame or state win', () => {
        const book: RelayBook = new Map();

        expect(
            applyFastFrame(book, sample(125, { x: 168.5 }), 0, overrides),
        ).toBe(true);
        expect(applyTripFrame(book, tripFrame(), stored(120), 10)).toBe(true);
        expect(book.get('731359')).toMatchObject({
            kind: 'trip',
            gameSeconds: 130,
            receivedAt: 10,
        });
        // An older trip, or one older than the stored state, or of another session: ignored.
        expect(
            applyTripFrame(
                book,
                tripFrame([tripRow({ x: 1 })], { gameSeconds: 128 }),
                stored(120),
                11,
            ),
        ).toBe(false);
        expect(applyTripFrame(new Map(), tripFrame(), stored(140), 11)).toBe(
            false,
        );
        expect(
            applyTripFrame(
                new Map(),
                tripFrame(),
                stored(100, 'session-b'),
                11,
            ),
        ).toBe(false);
    });

    it('never rewinds a critter with a newer trip', () => {
        const book: RelayBook = new Map();
        applyTripFrame(book, tripFrame(), stored(120), 0);

        expect(
            applyFastFrame(book, sample(129, { x: 160.5 }), 100, overrides),
        ).toBe(false);
        expect(book.get('731359')?.kind).toBe('trip');
    });

    it('keeps a playing trip over a newer sample taken along the way', () => {
        const book: RelayBook = new Map();
        applyTripFrame(book, tripFrame(), stored(120), 0);

        expect(
            applyFastFrame(
                book,
                sample(131, {
                    x: 170.5,
                    moving: true,
                    path: [{ cell: cellAt(171, 239), navType: 'Swim' }],
                }),
                500,
                overrides,
            ),
        ).toBe(false);
        expect(book.get('731359')?.kind).toBe('trip');
    });

    it('lets a newer sample win beyond the teleport distance: an event was lost', () => {
        const book: RelayBook = new Map();
        applyTripFrame(book, tripFrame(), stored(120), 0);

        expect(
            applyFastFrame(book, sample(131, { x: 186.5 }), 500, overrides),
        ).toBe(true);
        expect(book.get('731359')).toMatchObject({
            kind: 'fast',
            gameSeconds: 131,
        });
    });

    it('does not glide a resting critter to a sub-cell spot the sample disagrees on', () => {
        const book: RelayBook = new Map();
        applyTripFrame(book, tripFrame(), stored(120), 0);

        expect(
            applyFastFrame(book, sample(135, { x: 172.8 }), 5000, overrides),
        ).toBe(false);
        expect(book.get('731359')?.kind).toBe('trip');
        expect(
            applyFastFrame(book, sample(140, { x: 174.5 }), 10000, overrides),
        ).toBe(true);
        expect(book.get('731359')?.motion.x).toBe(174.5);
    });

    it('drops an older fast frame decoded late', () => {
        const book: RelayBook = new Map();
        applyFastFrame(book, sample(130, { x: 170.5 }), 0, overrides);

        expect(
            applyFastFrame(book, sample(125, { x: 160.5 }), 10, overrides),
        ).toBe(false);
        expect(book.get('731359')?.motion.x).toBe(170.5);
    });

    it('settles the book on a reload', () => {
        const book: RelayBook = new Map();
        applyTripFrame(
            book,
            tripFrame([
                tripRow(),
                tripRow({ instanceId: 'gone' }),
                tripRow({
                    instanceId: 'resting',
                    kind: 'stop',
                    path: undefined,
                }),
            ]),
            stored(120),
            0,
        );
        applyFastFrame(
            book,
            readFastFrame(
                message({
                    gameSeconds: 135,
                    critters: [row({ instanceId: 'fish', x: 1 })],
                }),
                target,
            )!,
            0,
            overrides,
        );
        const critters = [
            critter({ x: 186.5, y: 239, moving: false, path: [] }),
            critter({ instanceId: 'resting', x: 169.6, y: 239 }),
            critter({ instanceId: 'fish', x: 1 }),
        ];

        applyReload(book, critters, stored(135), 5000, overrides);

        // Unknown ids go; the fast entry the stored state caught up goes; the lost trip yields to
        // the stored sample; the stop the sample agrees with stays.
        expect([...book.keys()]).toEqual(['resting']);

        applyReload(book, critters, stored(140, 'session-b'), 6000, overrides);
        expect(book.size).toBe(0);
    });

    it('moves the known critters and keeps the rest of their data', () => {
        const book: RelayBook = new Map();
        applyTripFrame(
            book,
            tripFrame([tripRow(), tripRow({ instanceId: '999' })]),
            stored(120),
            0,
        );
        const [moved, other] = overlayBook(
            [critter(), critter({ instanceId: '1' })],
            book,
        );

        expect(moved).toMatchObject({
            x: 169.5,
            moving: true,
            path: [170, 171, 172].map((x) => ({
                cell: cellAt(x, 239),
                navType: 'Swim',
            })),
            aquatic: true,
            name: 'Puft',
            statuses: [{ id: 'Happy', name: 'Heureux', bad: false }],
        });
        expect(other).toMatchObject({ instanceId: '1', x: 166.5 });
        expect(overlayBook([critter()], book)).toHaveLength(1);
    });

    it('returns the critters as they are with an empty book', () => {
        const critters = [critter()];

        expect(overlayBook(critters, new Map())).toBe(critters);
    });
});

// endregion
