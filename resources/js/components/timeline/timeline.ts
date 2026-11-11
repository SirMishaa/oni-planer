// region Types

export interface TimelineRow {
    id: string;
    /** Accessible name of the row, e.g. the text shown by the label slot. */
    ariaLabel?: string;
}

export interface TimelineEvent<P = unknown> {
    rowId: string;
    cycle: number;
    kind: string;
    payload: P;
}

export interface TimelineMark<P = unknown> {
    rowId: string;
    cycle: number;
    kind: string;
    events: TimelineEvent<P>[];
}

// endregion

// region Constants

export const MIN_PX_PER_CYCLE = 16;
export const MAX_PX_PER_CYCLE = 160;
export const DEFAULT_PX_PER_CYCLE = 56;
export const MIN_TICK_GAP_PX = 48;

// endregion

// region Geometry

export function cycleToPx(
    cycle: number,
    startCycle: number,
    pxPerCycle: number,
): number {
    return (cycle - startCycle) * pxPerCycle;
}

export function clampPxPerCycle(pxPerCycle: number): number {
    if (!Number.isFinite(pxPerCycle)) {
        return DEFAULT_PX_PER_CYCLE;
    }

    return Math.min(MAX_PX_PER_CYCLE, Math.max(MIN_PX_PER_CYCLE, pxPerCycle));
}

/** Integer cycle ticks from floor(start) to ceil(end), thinned so labels are >= 48 px apart. */
export function cycleTicks(
    startCycle: number,
    endCycle: number,
    pxPerCycle: number,
): number[] {
    if (pxPerCycle <= 0 || endCycle < startCycle) {
        return [];
    }

    const step = Math.max(1, Math.ceil(MIN_TICK_GAP_PX / pxPerCycle));
    const last = Math.ceil(endCycle);
    const ticks: number[] = [];

    for (let cycle = Math.floor(startCycle); cycle <= last; cycle += step) {
        ticks.push(cycle);
    }

    return ticks;
}

// endregion

// region Wheel zoom

/** Zoom change per wheel pixel: one classic notch (100 px) zooms by about 18 %. */
const WHEEL_ZOOM_PER_PX = 0.002;
const WHEEL_PX_PER_LINE = 16;
const WHEEL_PX_PER_PAGE = 400;

/**
 * Scale multiplier for one wheel event, proportional to its delta so that
 * trackpad pinches and free-spin wheels (many small events) zoom smoothly.
 * `deltaMode` follows `WheelEvent`: 0 pixels, 1 lines, 2 pages.
 */
export function wheelZoomFactor(deltaY: number, deltaMode: number): number {
    if (!Number.isFinite(deltaY)) {
        return 1;
    }

    const unitPx =
        deltaMode === 1
            ? WHEEL_PX_PER_LINE
            : deltaMode === 2
              ? WHEEL_PX_PER_PAGE
              : 1;

    return Math.exp(-deltaY * unitPx * WHEEL_ZOOM_PER_PX);
}

// endregion

// region Merging

/**
 * Merges events of the same row and kind closer than `minGapPx` on screen.
 * A merged mark sits at the cycle of its first event; later events join it
 * while they stay within `minGapPx` of that position.
 */
export function mergeEvents<P>(
    events: TimelineEvent<P>[],
    pxPerCycle: number,
    minGapPx: number,
): TimelineMark<P>[] {
    const groups = new Map<string, TimelineEvent<P>[]>();

    for (const event of events) {
        const key = JSON.stringify([event.rowId, event.kind]);
        const group = groups.get(key);

        if (group) {
            group.push(event);
        } else {
            groups.set(key, [event]);
        }
    }

    const marks: TimelineMark<P>[] = [];

    for (const group of groups.values()) {
        const sorted = [...group].sort((a, b) => a.cycle - b.cycle);
        let current: TimelineMark<P> | null = null;

        for (const event of sorted) {
            if (
                current &&
                (event.cycle - current.cycle) * pxPerCycle < minGapPx
            ) {
                current.events.push(event);
                continue;
            }

            current = {
                rowId: event.rowId,
                cycle: event.cycle,
                kind: event.kind,
                events: [event],
            };
            marks.push(current);
        }
    }

    return marks.sort((a, b) => a.cycle - b.cycle);
}

// endregion
