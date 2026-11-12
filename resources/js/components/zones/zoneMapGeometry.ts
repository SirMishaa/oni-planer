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

/**
 * SVG path (in cell units, row 0 at the top) of the zone's outer and inner
 * edges: one segment per cell side whose neighbour is not in the zone.
 */
export function zoneOutlinePath(
    cells: readonly [number, number][],
    box: ZoneBoxData,
): string {
    const inZone = new Set(cells.map(([x, y]) => `${x},${y}`));
    const has = (x: number, y: number): boolean => inZone.has(`${x},${y}`);
    const segments: string[] = [];

    for (const [x, y] of cells) {
        const { column, row } = cellToLocal(x, y, box);

        if (!has(x, y + 1)) {
            segments.push(`M${column} ${row}h1`);
        }

        if (!has(x, y - 1)) {
            segments.push(`M${column} ${row + 1}h1`);
        }

        if (!has(x - 1, y)) {
            segments.push(`M${column} ${row}v1`);
        }

        if (!has(x + 1, y)) {
            segments.push(`M${column + 1} ${row}v1`);
        }
    }

    return segments.join('');
}

// endregion
