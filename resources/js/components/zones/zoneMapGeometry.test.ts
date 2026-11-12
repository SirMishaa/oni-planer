import { describe, expect, it } from 'vitest';
import {
    cellToLocal,
    hexToRgb,
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
    it('draws the four sides of a lone cell', () => {
        expect(zoneOutlinePath([[10, 22]], box)).toBe(
            'M0 0h1M0 1h1M0 0v1M1 0v1',
        );
    });

    it('skips the side shared by two zone cells', () => {
        const path = zoneOutlinePath(
            [
                [10, 20],
                [11, 20],
            ],
            box,
        );

        expect(path).not.toContain('M1 2v1');
        expect(path.match(/v1/g)).toHaveLength(2);
        expect(path.match(/h1/g)).toHaveLength(4);
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
