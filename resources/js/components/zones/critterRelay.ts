import type { ZoneBoxData, ZoneCritterData } from '@/types/App/Data/data.d';
import {
    positionAt,
    teleportCells,
    trackEndsAt,
    trackFrom,
} from './critterMotion';

// region Types

/** The fields of a critter its motion needs, read from a raw frame as `ZoneCritterState` reads them. */
export type RelayMotion = Pick<
    ZoneCritterData,
    | 'instanceId'
    | 'cell'
    | 'x'
    | 'y'
    | 'facing'
    | 'navType'
    | 'moving'
    | 'speed'
    | 'path'
    | 'aquatic'
>;

/** The motion a trip entry carries: the fast stream's fields, without `aquatic`. */
export type TripMotion = Omit<RelayMotion, 'aquatic'>;

/** The trips of the zone in a `client-zone.trips` flush: a trip walks its whole path, a stop rests. */
export type TripFrame = {
    sessionId: string | null;
    gameSeconds: number;
    trips: Map<string, TripMotion>;
};

/**
 * The latest motion the relay holds for a critter, from a trip or a fast
 * frame, with its place in the session and when (ms, `performance.now()`)
 * it arrived.
 */
export type RelayEntry = {
    kind: 'fast' | 'trip';
    sessionId: string | null;
    gameSeconds: number;
    motion: TripMotion | RelayMotion;
    receivedAt: number;
};

/** The relay's motion of each critter by instance id. */
export type RelayBook = Map<string, RelayEntry>;

/** Whether a sample newer than a trip replaces it, at `now` (ms). */
export type TripCheck = (
    sample: TripMotion,
    trip: RelayEntry,
    now: number,
) => boolean;

/** A fast frame of the zone: its place in the session, and the motion of each critter by instance id. */
export type RelayFrame = {
    sessionId: string | null;
    gameSeconds: number;
    critters: Map<string, RelayMotion>;
};

/** Session and game time of the stored fast state the page props come from. */
export type StoredFastState = {
    sessionId: string | null;
    gameSeconds: number | null;
};

type Message = Record<string, unknown>;

// endregion

// region Decoding

const GZIP_MAGIC = [0x1f, 0x8b];

function isObject(value: unknown): value is Message {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function parseObject(json: string): Message | null {
    try {
        const parsed: unknown = JSON.parse(json);

        return isObject(parsed) ? parsed : null;
    } catch {
        return null;
    }
}

async function gunzip(bytes: Uint8Array<ArrayBuffer>): Promise<string | null> {
    try {
        const stream = new Blob([bytes])
            .stream()
            .pipeThrough(new DecompressionStream('gzip'));

        return await new Response(stream).text();
    } catch {
        return null;
    }
}

/**
 * The message of a frame's `data`, as `UplinkPayload::decode` reads it:
 * `{payload}` holding base64 (of gzip, as the mod sends it), JSON text, or
 * an object; null when unreadable.
 */
export async function decodeUplinkPayload(
    data: unknown,
): Promise<Message | null> {
    const inner =
        isObject(data) && typeof data.payload === 'string'
            ? data.payload
            : data;

    if (isObject(inner)) {
        return inner;
    }

    if (typeof inner !== 'string' || inner === '') {
        return null;
    }

    if (['{', '[', '"'].includes(inner[0])) {
        return parseObject(inner);
    }

    let bytes: Uint8Array<ArrayBuffer>;

    try {
        bytes = Uint8Array.from(atob(inner), (char) => char.charCodeAt(0));
    } catch {
        return null;
    }

    const text =
        bytes[0] === GZIP_MAGIC[0] && bytes[1] === GZIP_MAGIC[1]
            ? await gunzip(bytes)
            : new TextDecoder().decode(bytes);

    return text === null ? null : parseObject(text);
}

// endregion

// region Reading

function isNumeric(value: unknown): boolean {
    return (
        (typeof value === 'number' && Number.isFinite(value)) ||
        (typeof value === 'string' &&
            value.trim() !== '' &&
            Number.isFinite(Number(value)))
    );
}

function nonEmptyString(value: unknown): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

/** Instance ids are strings on the wire; a number is accepted. */
function instanceId(value: unknown): string | null {
    return Number.isInteger(value) ? String(value) : nonEmptyString(value);
}

/** The motion fields of a row, read as `ZoneCritterState` reads them; null without an id, a cell or a position. */
function readMotion(row: Message, withPath: boolean): TripMotion | null {
    const id = instanceId(row.instanceId);

    if (
        id === null ||
        !Number.isInteger(row.cell) ||
        !isNumeric(row.x) ||
        !isNumeric(row.y)
    ) {
        return null;
    }

    const path = withPath && Array.isArray(row.path) ? row.path : [];

    return {
        instanceId: id,
        cell: row.cell as number,
        x: Number(row.x),
        y: Number(row.y),
        facing: row.facing === 'right' ? 'right' : 'left',
        navType: nonEmptyString(row.navType) ?? '',
        moving: row.moving === true,
        speed: isNumeric(row.speed) ? Number(row.speed) : 0,
        path: path.flatMap((step: unknown) =>
            isObject(step) && Number.isInteger(step.cell)
                ? [
                      {
                          cell: step.cell as number,
                          navType: nonEmptyString(step.navType) ?? '',
                      },
                  ]
                : [],
        ),
    };
}

/** The motion of a critter row, or null for a row `ZoneCritterState` skips. */
function relayMotion(row: unknown): RelayMotion | null {
    if (!isObject(row) || nonEmptyString(row.prefabId) === null) {
        return null;
    }

    const motion = readMotion(row, true);

    return motion === null
        ? null
        : { ...motion, aquatic: row.aquatic === true };
}

/** A trip entry as motion: a trip moves along its path, a stop rests; null for an entry it cannot read. */
function tripMotion(row: unknown): TripMotion | null {
    if (!isObject(row) || (row.kind !== 'trip' && row.kind !== 'stop')) {
        return null;
    }

    const trip = row.kind === 'trip';
    const motion = readMotion(row, trip);

    return motion === null ? null : { ...motion, moving: trip };
}

/** True when the schema is one the page reads, as `UplinkMessageReader` checks it. */
function isReadableSchema(schema: unknown, newest: number): boolean {
    return (
        Number.isInteger(schema) &&
        (schema as number) >= 1 &&
        (schema as number) <= newest
    );
}

/**
 * The frame of a fast-state message when it is about the zone (`colonyId`,
 * `zoneId`), in a schema the page reads (as `UplinkMessageReader` checks
 * it), with a game time and the critters; null otherwise.
 */
export function readFastFrame(
    message: Message,
    zone: { colonyGuid: string; zoneGuid: string; schema: number },
): RelayFrame | null {
    if (
        !isReadableSchema(message.schema, zone.schema) ||
        message.colonyId !== zone.colonyGuid ||
        message.zoneId !== zone.zoneGuid ||
        !isNumeric(message.gameSeconds) ||
        !Array.isArray(message.critters)
    ) {
        return null;
    }

    const critters = new Map<string, RelayMotion>();

    for (const row of message.critters) {
        const motion = relayMotion(row);

        if (motion !== null) {
            critters.set(motion.instanceId, motion);
        }
    }

    return {
        sessionId: nonEmptyString(message.sessionId),
        gameSeconds: Number(message.gameSeconds),
        critters,
    };
}

/** True when a `client-zone.trips` frame lists the zone in its clear `zoneIds`: the others are not decoded. */
export function tripsFrameConcerns(data: unknown, zoneGuid: string): boolean {
    return (
        isObject(data) &&
        Array.isArray(data.zoneIds) &&
        data.zoneIds.includes(zoneGuid)
    );
}

/**
 * The trips of the zone in a `client-zone.trips` message of the colony, in
 * a schema the page reads, with a game time; null otherwise.
 */
export function readTripFrame(
    message: Message,
    zone: { colonyGuid: string; zoneGuid: string; schema: number },
): TripFrame | null {
    if (
        !isReadableSchema(message.schema, zone.schema) ||
        message.colonyId !== zone.colonyGuid ||
        !isNumeric(message.gameSeconds) ||
        !Array.isArray(message.zones)
    ) {
        return null;
    }

    const trips = new Map<string, TripMotion>();

    for (const group of message.zones) {
        if (
            !isObject(group) ||
            group.zoneId !== zone.zoneGuid ||
            !Array.isArray(group.trips)
        ) {
            continue;
        }

        for (const row of group.trips) {
            const motion = tripMotion(row);

            if (motion !== null) {
                trips.set(motion.instanceId, motion);
            }
        }
    }

    return {
        sessionId: nonEmptyString(message.sessionId),
        gameSeconds: Number(message.gameSeconds),
        trips,
    };
}

// endregion

// region Ordering

/** Same session, as `StoreZoneState` sees it: a state without session counts as the current one. */
function sameSession(a: string | null, b: string | null): boolean {
    return a === null || b === null || a === b;
}

/** True when `frame` is newer than the stored state, in the same session: the server will store it too. */
export function frameIsAhead(
    frame: RelayFrame,
    stored: StoredFastState,
): boolean {
    return (
        sameSession(frame.sessionId, stored.sessionId) &&
        (stored.gameSeconds === null || frame.gameSeconds > stored.gameSeconds)
    );
}

/** A newer sample at most this many cells from a resting trip's end is ignored: no glide to a sub-cell spot. */
export const JITTER_CELLS = 0.5;

/**
 * Whether a sample newer than `trip` replaces it at `now`. While the trip
 * plays, the sample only wins beyond the teleport distance (an event was
 * lost); once the trip rests, it wins from `JITTER_CELLS` on.
 */
export function sampleOverridesTrip(
    sample: TripMotion,
    trip: RelayEntry,
    now: number,
    gridWidth: number,
    box: ZoneBoxData,
): boolean {
    const track = trackFrom(trip.motion, trip.receivedAt, gridWidth, box);
    const pose = positionAt(track, now);
    const gap = Math.hypot(sample.x - pose.x, sample.y - pose.y);

    return now < trackEndsAt(track)
        ? gap > teleportCells(trip.motion.speed)
        : gap >= JITTER_CELLS;
}

/**
 * Records the trips of `frame` received at `now`. A trip wins over an older
 * entry and over the stored state when it is not older; a frame of another
 * session than the stored state waits for the reload. True when an entry
 * changed.
 */
export function applyTripFrame(
    book: RelayBook,
    frame: TripFrame,
    stored: StoredFastState,
    now: number,
): boolean {
    if (
        !sameSession(frame.sessionId, stored.sessionId) ||
        (stored.gameSeconds !== null && frame.gameSeconds < stored.gameSeconds)
    ) {
        return false;
    }

    let changed = false;

    for (const [id, motion] of frame.trips) {
        const entry = book.get(id);

        if (
            entry &&
            sameSession(entry.sessionId, frame.sessionId) &&
            entry.gameSeconds > frame.gameSeconds
        ) {
            continue;
        }

        book.set(id, {
            kind: 'trip',
            sessionId: frame.sessionId,
            gameSeconds: frame.gameSeconds,
            motion,
            receivedAt: now,
        });
        changed = true;
    }

    return changed;
}

/**
 * Records the critters of a fast frame (already ahead of the stored state)
 * received at `now`. An entry at least as new is kept, so a frame decoded
 * late or a sample older than a trip never rewinds a critter; a newer sample
 * replaces a trip only when `overrides` says so. True when an entry changed.
 */
export function applyFastFrame(
    book: RelayBook,
    frame: RelayFrame,
    now: number,
    overrides: TripCheck,
): boolean {
    let changed = false;

    for (const [id, motion] of frame.critters) {
        const entry = book.get(id);

        if (entry && sameSession(entry.sessionId, frame.sessionId)) {
            if (
                entry.gameSeconds >= frame.gameSeconds ||
                (entry.kind === 'trip' && !overrides(motion, entry, now))
            ) {
                continue;
            }
        }

        book.set(id, {
            kind: 'fast',
            sessionId: frame.sessionId,
            gameSeconds: frame.gameSeconds,
            motion,
            receivedAt: now,
        });
        changed = true;
    }

    return changed;
}

/**
 * Settles the book on a reload of the stored state: entries of critters no
 * longer sent or of another session go, a fast entry the stored state caught
 * up goes (the props hold it), and a trip older than the stored state stays
 * only while its sample does not override it.
 */
export function applyReload(
    book: RelayBook,
    critters: readonly ZoneCritterData[],
    stored: StoredFastState,
    now: number,
    overrides: TripCheck,
): void {
    const byId = new Map(
        critters.map((critter) => [critter.instanceId, critter]),
    );

    for (const [id, entry] of book) {
        const critter = byId.get(id);

        if (!critter || !sameSession(entry.sessionId, stored.sessionId)) {
            book.delete(id);
        } else if (
            stored.gameSeconds === null ||
            entry.gameSeconds > stored.gameSeconds ||
            (entry.kind === 'trip' && entry.gameSeconds === stored.gameSeconds)
        ) {
            continue;
        } else if (entry.kind === 'fast' || overrides(critter, entry, now)) {
            book.delete(id);
        }
    }
}

/**
 * The critters of the props, the known ones moved to their relay entry. A
 * critter only in the relay waits for the reload; everything but the motion
 * still comes from the props.
 */
export function overlayBook(
    critters: ZoneCritterData[],
    book: RelayBook,
): ZoneCritterData[] {
    if (book.size === 0) {
        return critters;
    }

    return critters.map((critter) => {
        const entry = book.get(critter.instanceId);

        return entry ? { ...critter, ...entry.motion } : critter;
    });
}

// endregion
