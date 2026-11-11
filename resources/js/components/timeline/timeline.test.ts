import { describe, expect, it } from 'vitest';
import { cycleTicks, cycleToPx, mergeEvents } from './timeline';

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
});
