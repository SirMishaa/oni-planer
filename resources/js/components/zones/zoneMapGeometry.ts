import type { ZoneBoxData } from '@/types/App/Data/data.d';

/** An sRGB colour, 0 to 255 per channel. */
export type Rgb = [number, number, number];

const KELVIN_OFFSET = 273.15;

// region Colours

export function hexToRgb(hex: string | null): Rgb | null {
    const match = /^#?([0-9a-f]{6})$/i.exec(hex ?? '');

    if (!match) {
        return null;
    }

    const value = Number.parseInt(match[1] ?? '', 16);

    return [(value >> 16) & 255, (value >> 8) & 255, value & 255];
}

/** `amount` of `colour` over `base`, mixed in sRGB like `color-mix(in srgb, …)`. */
export function mixSrgb(colour: Rgb, base: Rgb, amount: number): Rgb {
    const channel = (index: 0 | 1 | 2): number =>
        Math.round(colour[index] * amount + base[index] * (1 - amount));

    return [channel(0), channel(1), channel(2)];
}

export function rgbCss([red, green, blue]: Rgb): string {
    return `rgb(${red} ${green} ${blue})`;
}

export function kelvinToCelsius(kelvin: number): number {
    return kelvin - KELVIN_OFFSET;
}

// endregion

// region Cells

/** Column and row of a game cell in the box, row 0 at the top (game y grows up). */
export function cellToLocal(
    x: number,
    y: number,
    box: ZoneBoxData,
): { column: number; row: number } {
    return { column: x - box.x, row: box.y + box.height - 1 - y };
}

/** Game cell under a local column and row of the box. */
export function localToCell(
    column: number,
    row: number,
    box: ZoneBoxData,
): { x: number; y: number } {
    return { x: box.x + column, y: box.y + box.height - 1 - row };
}

/** Index of a cell in a row-major layer that starts at the box's bottom-left cell; null outside it. */
export function layerIndex(
    x: number,
    y: number,
    box: ZoneBoxData,
): number | null {
    const column = x - box.x;
    const row = y - box.y;

    return column < 0 || row < 0 || column >= box.width || row >= box.height
        ? null
        : row * box.width + column;
}

/** Radius of the outline's rounded corners, in cells (tunable). */
export const OUTLINE_CORNER_CELLS = 0.3;

/**
 * SVG path (in cell units, row 0 at the top) of the zone's outer and inner
 * edges: the cell sides with no zone neighbour, chained into closed loops
 * (one per outline or hole) with rounded corners.
 */
export function zoneOutlinePath(
    cells: readonly [number, number][],
    box: ZoneBoxData,
): string {
    const inZone = new Set(cells.map(([x, y]) => `${x},${y}`));
    const has = (x: number, y: number): boolean => inZone.has(`${x},${y}`);
    /** Directed edges, clockwise around each cell, keyed by their start point. */
    const edges = new Map<string, [number, number][]>();
    const add = (from: [number, number], to: [number, number]): void => {
        const key = `${from[0]},${from[1]}`;
        edges.set(key, [...(edges.get(key) ?? []), to]);
    };

    for (const [x, y] of cells) {
        const { column: c, row: r } = cellToLocal(x, y, box);

        if (!has(x, y + 1)) {
            add([c, r], [c + 1, r]);
        }

        if (!has(x + 1, y)) {
            add([c + 1, r], [c + 1, r + 1]);
        }

        if (!has(x, y - 1)) {
            add([c + 1, r + 1], [c, r + 1]);
        }

        if (!has(x - 1, y)) {
            add([c, r + 1], [c, r]);
        }
    }

    const paths: string[] = [];

    for (const [startKey] of edges) {
        while ((edges.get(startKey)?.length ?? 0) > 0) {
            const loop = traceLoop(startKey, edges);

            if (loop.length >= 3) {
                paths.push(roundedLoop(loop));
            }
        }
    }

    return paths.join('');
}

/** Follows edges from a point until back to it; at a pinch point it turns clockwise first. */
function traceLoop(
    startKey: string,
    edges: Map<string, [number, number][]>,
): [number, number][] {
    const [sx, sy] = startKey.split(',').map(Number) as [number, number];
    const points: [number, number][] = [[sx, sy]];
    let current: [number, number] = [sx, sy];
    let heading: [number, number] | null = null;

    for (let guard = 0; guard < 100_000; guard++) {
        const key = `${current[0]},${current[1]}`;
        const outgoing = edges.get(key) ?? [];

        if (outgoing.length === 0) {
            break;
        }

        let pick = 0;

        if (outgoing.length > 1 && heading !== null) {
            // Clockwise on screen (y down): right turn is (-dy, dx).
            const right: [number, number] = [-heading[1], heading[0]];
            const index = outgoing.findIndex(
                ([x, y]) =>
                    x - current[0] === right[0] && y - current[1] === right[1],
            );
            pick = index >= 0 ? index : 0;
        }

        const next = outgoing[pick] as [number, number];
        outgoing.splice(pick, 1);
        heading = [next[0] - current[0], next[1] - current[1]];
        current = next;

        if (`${current[0]},${current[1]}` === startKey) {
            break;
        }

        points.push(current);
    }

    return corners(points);
}

/** Drops the points where the loop goes straight on, keeping its corners. */
function corners(points: [number, number][]): [number, number][] {
    return points.filter((point, index) => {
        const previous = points[
            (index - 1 + points.length) % points.length
        ] as [number, number];
        const next = points[(index + 1) % points.length] as [number, number];

        return (
            (point[0] - previous[0]) * (next[1] - point[1]) -
                (point[1] - previous[1]) * (next[0] - point[0]) !==
            0
        );
    });
}

/** A closed loop through its corners, each rounded by a quadratic curve. */
function roundedLoop(points: [number, number][]): string {
    const round = (value: number): number => Math.round(value * 1000) / 1000;
    const toward = (
        from: [number, number],
        to: [number, number],
        distance: number,
    ): [number, number] => {
        const length = Math.hypot(to[0] - from[0], to[1] - from[1]);

        return [
            round(from[0] + ((to[0] - from[0]) / length) * distance),
            round(from[1] + ((to[1] - from[1]) / length) * distance),
        ];
    };
    const radius = (a: [number, number], b: [number, number]): number =>
        Math.min(
            OUTLINE_CORNER_CELLS,
            Math.hypot(b[0] - a[0], b[1] - a[1]) / 2,
        );

    let path = '';

    points.forEach((corner, index) => {
        const previous = points[
            (index - 1 + points.length) % points.length
        ] as [number, number];
        const next = points[(index + 1) % points.length] as [number, number];
        const entry = toward(corner, previous, radius(previous, corner));
        const exit = toward(corner, next, radius(corner, next));

        path += `${index === 0 ? 'M' : 'L'}${entry[0]} ${entry[1]}Q${corner[0]} ${corner[1]} ${exit[0]} ${exit[1]}`;
    });

    return `${path}Z`;
}

// endregion

/** Whether a footprint anchored at its bottom-left cell (x, y) has at least one cell in the box. */
export function footprintTouchesBox(
    x: number,
    y: number,
    width: number,
    height: number,
    box: ZoneBoxData,
): boolean {
    return (
        x + width > box.x &&
        x < box.x + box.width &&
        y + height > box.y &&
        y < box.y + box.height
    );
}
