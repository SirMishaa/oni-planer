import { describe, expect, it } from 'vitest';
import {
    cycleTicks,
    cycleToPx,
    mergeEvents,
    wheelZoomFactor,
} from './timeline';

describe('timeline', () => {
    it('merges close events of the same row and kind', () => {
        const e = (cycle: number, kind = 'a', rowId = 'r') => ({
            rowId,
            cycle,
            kind,
            payload: null,
        });
        const marks = mergeEvents(
            [e(60), e(60.1), e(61), e(60.05, 'b'), e(60, 'a', 'r2')],
            50,
            18,
        );
        expect(marks).toHaveLength(4);
        expect(
            marks.find(
                (m) =>
                    m.rowId === 'r' && m.kind === 'a' && m.events.length === 2,
            )?.cycle,
        ).toBeCloseTo(60, 5);
        expect(marks.filter((m) => m.kind === 'b')).toHaveLength(1);
        expect(marks.filter((m) => m.rowId === 'r2')).toHaveLength(1);
    });

    it('thins ticks to stay readable', () => {
        expect(cycleTicks(59.5, 64, 56)).toEqual([59, 60, 61, 62, 63, 64]);
        expect(cycleTicks(59.5, 110, 16)).toEqual([
            59, 62, 65, 68, 71, 74, 77, 80, 83, 86, 89, 92, 95, 98, 101, 104,
            107, 110,
        ]);
    });

    it('places a cycle in pixels', () => {
        expect(cycleToPx(61, 59.5, 56)).toBe(84);
    });

    it('scales wheel zoom by the size of the wheel delta', () => {
        expect(wheelZoomFactor(100, 0)).toBeCloseTo(0.819, 3);
        expect(wheelZoomFactor(-100, 0)).toBeCloseTo(1.221, 3);

        let pinch = 1;
        for (let i = 0; i < 30; i++) {
            pinch *= wheelZoomFactor(1, 0);
        }
        expect(pinch).toBeCloseTo(0.942, 3);

        expect(wheelZoomFactor(3, 1)).toBeCloseTo(wheelZoomFactor(48, 0), 10);
        expect(wheelZoomFactor(1, 2)).toBeCloseTo(wheelZoomFactor(400, 0), 10);
        expect(wheelZoomFactor(Number.NaN, 0)).toBe(1);
    });
});
