import type { ZoneBoxData, ZoneCritterData } from '@/types/App/Data/data.d';
import { layerIndex } from './zoneMapGeometry';

// region Constants

/** Seconds a non-looping step (jump, climb, drop, change of nav type) takes: the game plays an animation, it has no speed. Tune after measuring in game. */
export const NON_LOOPING_SECONDS = 0.6;
/** A new game position at most this many cells from the drawn one is eased into; farther, the critter jumps. */
export const SNAP_CELLS = 2;
/** Seconds the drawn position takes to catch up with a corrected game position. */
export const EASE_SECONDS = 0.4;
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
 * The replay of one critter from a fast state: from the sent position along
 * the path the game sent. `correction` is the gap to the previously drawn
 * position, eased out from `since` (ms).
 */
export type MotionTrack = {
    instanceId: string;
    from: { x: number; y: number };
    facing: Facing;
    path: MotionStep[];
    speed: number;
    startedAt: number;
    correction: { x: number; y: number; since: number } | null;
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
        path: [],
        speed: critter.speed,
        startedAt: receivedAt,
        correction: null,
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
        facing = dx > EPSILON ? 'right' : dx < -EPSILON ? 'left' : facing;
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

/** Fast at first, slow at the end: the correction settles in. */
function easeOutCubic(t: number): number {
    return 1 - (1 - t) ** 3;
}

/** Where the critter is drawn at `now` (ms), and which way it looks. */
export function positionAt(track: MotionTrack, now: number): CritterPose {
    const elapsed = Math.max(0, (now - track.startedAt) / 1000);
    let pose: CritterPose = { ...track.from, facing: track.facing };
    let start = { ...track.from, arrivesAfter: 0 };

    for (const step of track.path) {
        if (elapsed < step.arrivesAfter) {
            const t =
                (elapsed - start.arrivesAfter) /
                (step.arrivesAfter - start.arrivesAfter);
            pose = {
                x: start.x + (step.x - start.x) * t,
                y: start.y + (step.y - start.y) * t,
                facing: step.facing,
            };
            start = step;
            break;
        }

        pose = { x: step.x, y: step.y, facing: step.facing };
        start = step;
    }

    const correction = track.correction;

    if (correction) {
        const t = (now - correction.since) / (EASE_SECONDS * 1000);
        const remaining = t >= 1 ? 0 : 1 - easeOutCubic(Math.max(0, t));
        pose.x += correction.x * remaining;
        pose.y += correction.y * remaining;
    }

    return pose;
}

/** Time (ms) after which the track no longer moves: end of its path and of its easing. */
export function trackEndsAt(track: MotionTrack): number {
    const walkEnd =
        track.startedAt + (track.path.at(-1)?.arrivesAfter ?? 0) * 1000;
    const easeEnd = track.correction
        ? track.correction.since + EASE_SECONDS * 1000
        : 0;

    return Math.max(walkEnd, easeEnd);
}

/**
 * The new track, starting from the drawn position `previous` when the game
 * position is close (eased out over `EASE_SECONDS` while the track moves on),
 * or jumping to the game position when it is farther than `SNAP_CELLS`.
 */
export function reconcile(
    previous: { x: number; y: number },
    next: MotionTrack,
    now: number,
): MotionTrack {
    const target = positionAt({ ...next, correction: null }, now);
    const gap = Math.hypot(previous.x - target.x, previous.y - target.y);

    if (gap > SNAP_CELLS || gap < EPSILON) {
        return { ...next, correction: null };
    }

    return {
        ...next,
        correction: {
            x: previous.x - target.x,
            y: previous.y - target.y,
            since: now,
        },
    };
}

/** True when a fast state sent at `sentAt` (ISO) is recent enough at `nowMs` (epoch ms) to replay. */
export function isReplayable(sentAt: string | null, nowMs: number): boolean {
    const sentMs = sentAt === null ? NaN : Date.parse(sentAt);

    return !Number.isNaN(sentMs) && nowMs - sentMs <= REPLAY_WINDOW_MS;
}

/**
 * Tracks of a new fast state received at `receivedAt`. A critter already
 * replayed starts from where it is drawn (`reconcile`); the others start
 * where the game saw them. Only the critters of the new state are kept, so a
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
            before
                ? reconcile(positionAt(before, receivedAt), track, receivedAt)
                : track,
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
