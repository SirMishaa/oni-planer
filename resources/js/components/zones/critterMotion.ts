import type { ZoneBoxData, ZoneCritterData } from '@/types/App/Data/data.d';
import { layerIndex } from './zoneMapGeometry';

// region Constants

/** Seconds a non-looping step (jump, climb, drop, change of nav type) takes: the game plays an animation, it has no speed. Tune after measuring in game. */
export const NON_LOOPING_SECONDS = 0.6;
/** Shortest travel (seconds) from the drawn position to a new game position, so a small gap still glides. */
export const EASE_SECONDS = 0.4;
/** Seconds between two fast states: the longest travel, so the drawn critter never lags more than one message. */
export const CADENCE_SECONDS = 5;
/** Smallest gap (cells) at which a critter jumps to its new game position instead of travelling. */
export const MIN_TELEPORT_CELLS = 6;
/** A fast state older than this when it arrives (paused game, page opened late) is drawn as sent, not replayed. */
export const REPLAY_WINDOW_MS = 12_000;

/** Nav types whose transitions loop at the navigator's speed, with the axis they cross along (`any`: both). */
const LOOPING_AXES: Record<string, 'horizontal' | 'vertical' | 'any'> = {
    Floor: 'horizontal',
    Ceiling: 'horizontal',
    LeftWall: 'vertical',
    RightWall: 'vertical',
    Swim: 'any',
    Hover: 'any',
};

const EPSILON = 1e-6;

// endregion

// region Types

export type Facing = 'left' | 'right';

export type CritterPose = { x: number; y: number; facing: Facing };

/** A step of the replay: its target position, and when (seconds after the start) it is reached. */
export type MotionStep = {
    x: number;
    y: number;
    navType: string;
    arrivesAfter: number;
    facing: Facing;
};

/**
 * The replay of one critter from a fast state: from `from` along `path`.
 * Built from a fast state alone, it starts at the sent position; once
 * reconciled with the drawn critter, it starts where the critter is drawn and
 * its path first travels to the sent position (`reconcile`).
 */
export type MotionTrack = {
    instanceId: string;
    from: { x: number; y: number };
    facing: Facing;
    navType: string;
    path: MotionStep[];
    speed: number;
    startedAt: number;
};

// endregion

// region Tracks

function cellPosition(
    cell: number,
    gridWidth: number,
): { x: number; y: number } {
    // Same convention as the static draw: `x` is the body centre, `y` the feet.
    return { x: (cell % gridWidth) + 0.5, y: Math.floor(cell / gridWidth) };
}

function isLooping(
    fromCell: number,
    fromNavType: string,
    toCell: number,
    toNavType: string,
    gridWidth: number,
): boolean {
    const axis = LOOPING_AXES[toNavType];

    if (axis === undefined || fromNavType !== toNavType) {
        return false;
    }

    const dx = Math.abs((toCell % gridWidth) - (fromCell % gridWidth));
    const dy = Math.abs(
        Math.floor(toCell / gridWidth) - Math.floor(fromCell / gridWidth),
    );

    if (dx + dy !== 1) {
        return false;
    }

    return axis === 'any' || (axis === 'horizontal' ? dy === 0 : dx === 0);
}

export function facingOf(critter: ZoneCritterData): Facing {
    return critter.facing === 'left' ? 'left' : 'right';
}

/**
 * The replay of a critter sent at `receivedAt` (ms). A critter that is not
 * moving, has no path or no speed stays where the game saw it. The path
 * stops at the first cell outside the box: the map shows nothing farther.
 */
export function trackFrom(
    critter: ZoneCritterData,
    receivedAt: number,
    gridWidth: number,
    box: ZoneBoxData,
): MotionTrack {
    const track: MotionTrack = {
        instanceId: critter.instanceId,
        from: { x: critter.x, y: critter.y },
        facing: facingOf(critter),
        navType: critter.navType,
        path: [],
        speed: critter.speed,
        startedAt: receivedAt,
    };

    if (!critter.moving || !(critter.speed > 0)) {
        return track;
    }

    let previous = {
        ...track.from,
        cell: critter.cell,
        navType: critter.navType,
    };
    let facing = track.facing;
    let seconds = 0;

    for (const node of critter.path) {
        if (node.cell === previous.cell) {
            continue;
        }

        const target = cellPosition(node.cell, gridWidth);

        if (layerIndex(Math.floor(target.x), target.y, box) === null) {
            break;
        }

        const dx = target.x - previous.x;
        seconds += isLooping(
            previous.cell,
            previous.navType,
            node.cell,
            node.navType,
            gridWidth,
        )
            ? Math.hypot(dx, target.y - previous.y) / critter.speed
            : NON_LOOPING_SECONDS;
        // From the cell columns, not the float x: the sub-cell offset of the sent position must not turn a vertical step.
        const columnStep =
            (node.cell % gridWidth) - (previous.cell % gridWidth);
        facing = columnStep > 0 ? 'right' : columnStep < 0 ? 'left' : facing;
        track.path.push({
            ...target,
            navType: node.navType,
            arrivesAfter: seconds,
            facing,
        });
        previous = { ...target, cell: node.cell, navType: node.navType };
    }

    return track;
}

/** Where the critter is drawn at `now` (ms), and which way it looks. */
export function positionAt(track: MotionTrack, now: number): CritterPose {
    const elapsed = Math.max(0, (now - track.startedAt) / 1000);
    let start = { ...track.from, arrivesAfter: 0 };

    for (const step of track.path) {
        if (elapsed < step.arrivesAfter) {
            const t =
                (elapsed - start.arrivesAfter) /
                (step.arrivesAfter - start.arrivesAfter);

            return {
                x: start.x + (step.x - start.x) * t,
                y: start.y + (step.y - start.y) * t,
                facing: step.facing,
            };
        }

        start = step;
    }

    const last = track.path.at(-1);

    return last
        ? { x: last.x, y: last.y, facing: last.facing }
        : { ...track.from, facing: track.facing };
}

/** Time (ms) after which the track no longer moves: the end of its path. */
export function trackEndsAt(track: MotionTrack): number {
    return track.startedAt + (track.path.at(-1)?.arrivesAfter ?? 0) * 1000;
}

/** Gap (cells) beyond which a critter at `speed` (cells/s) jumps: farther than it could travel in one and a half messages. */
export function teleportCells(speed: number): number {
    return Math.max(
        MIN_TELEPORT_CELLS,
        (speed > 0 ? speed : 0) * CADENCE_SECONDS * 1.5,
    );
}

/**
 * Waypoints from the drawn position to `target`: the nodes the previous
 * track has yet to reach, as long as each one comes closer to `target`, then
 * `target` itself.
 */
function travelWaypoints(
    before: MotionTrack,
    drawn: { x: number; y: number },
    target: { x: number; y: number },
    now: number,
): { x: number; y: number; navType: string }[] {
    const elapsed = (now - before.startedAt) / 1000;
    const distance = (point: { x: number; y: number }): number =>
        Math.hypot(point.x - target.x, point.y - target.y);
    const waypoints: { x: number; y: number; navType: string }[] = [];
    let closest = distance(drawn);

    for (const step of before.path) {
        if (step.arrivesAfter <= elapsed) {
            continue;
        }

        const remaining = distance(step);

        if (remaining >= closest - EPSILON) {
            break;
        }

        waypoints.push({ x: step.x, y: step.y, navType: step.navType });
        closest = remaining;
    }

    if (closest > EPSILON) {
        waypoints.push({ ...target, navType: '' });
    }

    return waypoints;
}

/**
 * The new track `next`, reconciled at `now` with the critter as `before`
 * draws it. The critter travels from where it is drawn to the new game
 * position at its speed, in `EASE_SECONDS` at least and `CADENCE_SECONDS` at
 * most, along the previous path while it leads there and straight
 * otherwise; then it walks the new path. Its facing follows the columns it
 * crosses, so a vertical travel keeps it. Beyond `teleportCells` it jumps to
 * the game position, and with no gap it stays put.
 */
export function reconcile(
    before: MotionTrack,
    next: MotionTrack,
    now: number,
): MotionTrack {
    const drawn = positionAt(before, now);
    const target = next.from;
    const gap = Math.hypot(drawn.x - target.x, drawn.y - target.y);

    if (gap < EPSILON || gap > teleportCells(next.speed)) {
        return next;
    }

    const waypoints = travelWaypoints(before, drawn, target, now);
    let length = 0;
    let previous: { x: number; y: number } = drawn;

    for (const point of waypoints) {
        length += Math.hypot(point.x - previous.x, point.y - previous.y);
        previous = point;
    }

    const seconds = Math.min(
        CADENCE_SECONDS,
        Math.max(EASE_SECONDS, next.speed > 0 ? length / next.speed : Infinity),
    );
    const travel: MotionStep[] = [];
    let facing = drawn.facing;
    let travelled = 0;
    previous = drawn;

    for (const point of waypoints) {
        travelled += Math.hypot(point.x - previous.x, point.y - previous.y);
        const columnStep = Math.floor(point.x) - Math.floor(previous.x);
        facing = columnStep > 0 ? 'right' : columnStep < 0 ? 'left' : facing;
        travel.push({
            x: point.x,
            y: point.y,
            navType: point.navType || next.navType,
            arrivesAfter: length > 0 ? (travelled / length) * seconds : seconds,
            facing,
        });
        previous = point;
    }

    return {
        ...next,
        from: { x: drawn.x, y: drawn.y },
        facing: drawn.facing,
        path: [
            ...travel,
            ...next.path.map((step) => ({
                ...step,
                arrivesAfter: step.arrivesAfter + seconds,
            })),
        ],
    };
}

/** True when a fast state sent at `sentAt` (ISO) is recent enough at `nowMs` (epoch ms) to replay. */
export function isReplayable(sentAt: string | null, nowMs: number): boolean {
    const sentMs = sentAt === null ? NaN : Date.parse(sentAt);

    return !Number.isNaN(sentMs) && nowMs - sentMs <= REPLAY_WINDOW_MS;
}

/**
 * Tracks of a new fast state received at `receivedAt`. A critter already
 * replayed travels from where it is drawn to its new position (`reconcile`);
 * a critter just seen starts where the game saw it. Only the critters of the new state are kept, so a
 * new session (every instance id changed) starts over. Without `replay`
 * (reduced motion, stale state), every critter stands where it was sent.
 */
export function buildTracks(
    previous: ReadonlyMap<string, MotionTrack>,
    critters: readonly ZoneCritterData[],
    receivedAt: number,
    gridWidth: number,
    box: ZoneBoxData,
    replay: boolean,
): Map<string, MotionTrack> {
    const tracks = new Map<string, MotionTrack>();

    for (const critter of critters) {
        const track = trackFrom(critter, receivedAt, gridWidth, box);

        if (!replay) {
            tracks.set(critter.instanceId, { ...track, path: [] });
            continue;
        }

        const before = previous.get(critter.instanceId);
        tracks.set(
            critter.instanceId,
            before ? reconcile(before, track, receivedAt) : track,
        );
    }

    return tracks;
}

/** Writes each track's pose at `now` into `poses`; true while any of them still moves. */
export function advanceTracks(
    tracks: ReadonlyMap<string, MotionTrack>,
    now: number,
    poses: Map<string, CritterPose>,
): boolean {
    let moving = false;

    for (const [instanceId, track] of tracks) {
        poses.set(instanceId, positionAt(track, now));
        moving ||= now < trackEndsAt(track);
    }

    return moving;
}

// endregion
