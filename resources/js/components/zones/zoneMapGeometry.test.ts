import { describe, expect, it } from 'vitest';
import {
    cellToLocal,
    hexToRgb,
    footprintTouchesBox,
    layerIndex,
    localToCell,
    mixSrgb,
    zoneOutlinePath,
} from './zoneMapGeometry';

const box = { x: 10, y: 20, width: 4, height: 3 };

describe('cells', () => {
    it('flips the game y so the top row is row 0', () => {
        expect(cellToLocal(10, 22, box)).toEqual({ column: 0, row: 0 });
        expect(cellToLocal(13, 20, box)).toEqual({ column: 3, row: 2 });
        expect(localToCell(3, 2, box)).toEqual({ x: 13, y: 20 });
    });

    it('indexes layers row-major from the bottom-left cell', () => {
        expect(layerIndex(10, 20, box)).toBe(0);
        expect(layerIndex(11, 21, box)).toBe(5);
        expect(layerIndex(14, 20, box)).toBeNull();
        expect(layerIndex(10, 19, box)).toBeNull();
    });
});

describe('zoneOutlinePath', () => {
    const loops = (path: string) => path.match(/Z/g)?.length ?? 0;
    const corners = (path: string) => path.match(/Q/g)?.length ?? 0;

    it('draws a lone cell as one closed loop with four rounded corners', () => {
        const path = zoneOutlinePath([[10, 22]], box);

        expect(loops(path)).toBe(1);
        expect(corners(path)).toBe(4);
    });

    it('merges the sides of neighbouring cells into one outline', () => {
        const path = zoneOutlinePath(
            [
                [10, 20],
                [11, 20],
                [12, 20],
            ],
            box,
        );

        expect(loops(path)).toBe(1);
        expect(corners(path)).toBe(4);
    });

    it('outlines a hole in the zone as its own loop', () => {
        const ring: [number, number][] = [];
        for (let x = 10; x <= 12; x++) {
            for (let y = 20; y <= 22; y++) {
                if (x !== 11 || y !== 21) {
                    ring.push([x, y]);
                }
            }
        }

        const path = zoneOutlinePath(ring, box);

        expect(loops(path)).toBe(2);
        expect(corners(path)).toBe(8);
    });

    it('follows an L shape with its inner corner', () => {
        const path = zoneOutlinePath(
            [
                [10, 20],
                [11, 20],
                [10, 21],
            ],
            box,
        );

        expect(loops(path)).toBe(1);
        expect(corners(path)).toBe(6);
    });

    it('draws nothing for no cell', () => {
        expect(zoneOutlinePath([], box)).toBe('');
    });
});

describe('colours', () => {
    it('reads hex colours and rejects anything else', () => {
        expect(hexToRgb('#8a5a2b')).toEqual([138, 90, 43]);
        expect(hexToRgb(null)).toBeNull();
        expect(hexToRgb('red')).toBeNull();
    });

    it('mixes in sRGB by the share of the first colour', () => {
        expect(mixSrgb([200, 100, 0], [0, 0, 100], 0.45)).toEqual([90, 45, 55]);
    });
});

describe('footprintTouchesBox', () => {
    const box = { x: 10, y: 10, width: 5, height: 5 };

    it('keeps a building whose anchor is left of and below the box but whose footprint reaches in', () => {
        expect(footprintTouchesBox(7, 8, 4, 3, box)).toBe(true);
    });

    it('drops a building entirely outside the box', () => {
        expect(footprintTouchesBox(4, 10, 3, 1, box)).toBe(false);
        expect(footprintTouchesBox(10, 15, 1, 2, box)).toBe(false);
    });
});
