import type { ZoneCritterData } from '@/types/App/Data/data.d';

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

/** The motion of a critter row, or null for a row `ZoneCritterState` skips. */
function relayMotion(row: unknown): RelayMotion | null {
    if (!isObject(row)) {
        return null;
    }

    const id = instanceId(row.instanceId);

    if (
        id === null ||
        nonEmptyString(row.prefabId) === null ||
        !Number.isInteger(row.cell) ||
        !isNumeric(row.x) ||
        !isNumeric(row.y)
    ) {
        return null;
    }

    const path = Array.isArray(row.path) ? row.path : [];

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
        aquatic: row.aquatic === true,
    };
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
    const schema = message.schema;

    if (
        !Number.isInteger(schema) ||
        (schema as number) < 1 ||
        (schema as number) > zone.schema ||
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

/**
 * True when a reload of the stored state should replay the critters: no
 * frame was applied, it belongs to another session, or the stored state is
 * newer. A stored state the frame already showed would move them backward.
 */
export function reloadMovesCritters(
    stored: StoredFastState,
    applied: RelayFrame | null,
): boolean {
    return (
        applied === null ||
        !sameSession(applied.sessionId, stored.sessionId) ||
        stored.gameSeconds === null ||
        stored.gameSeconds > applied.gameSeconds
    );
}

/**
 * The critters of the props, the known ones moved to `frame` while it is
 * ahead of the stored state. A critter only in the frame waits for the
 * reload; everything but the motion still comes from the props.
 */
export function overlayRelay(
    critters: ZoneCritterData[],
    frame: RelayFrame | null,
    stored: StoredFastState,
): ZoneCritterData[] {
    if (frame === null || !frameIsAhead(frame, stored)) {
        return critters;
    }

    return critters.map((critter) => {
        const motion = frame.critters.get(critter.instanceId);

        return motion ? { ...critter, ...motion } : critter;
    });
}

// endregion
