import { describe, expect, it } from 'vitest';
import type { ZonePlanChangeData, ZonePlanData } from '@/types/App/Data/data.d';
import {
    planChangeId,
    planChangeStatus,
    removedFootprints,
    snapToCell,
} from './zonePlan';

function change(overrides: Partial<ZonePlanChangeData>): ZonePlanChangeData {
    return {
        id: 1,
        kind: 'add',
        state: 'pending',
        checked: true,
        movedByPlayer: false,
        note: '',
        prefabId: 'Tile',
        name: 'Tile',
        x: 10,
        y: 20,
        width: 1,
        height: 1,
        orientation: 'Neutral',
        target: null,
        movable: true,
        ...overrides,
    };
}

function plan(changes: ZonePlanChangeData[]): ZonePlanData {
    return { updatedAt: '2026-11-22T10:00:00Z', changes };
}

describe('planChangeId', () => {
    it('reads the change id of a planned instance', () => {
        expect(planChangeId('plan:12')).toBe(12);
    });

    it('is null for a real instance or no instance', () => {
        expect(planChangeId('1234')).toBeNull();
        expect(planChangeId(null)).toBeNull();
        expect(planChangeId('plan:')).toBeNull();
        expect(planChangeId('plan:abc')).toBeNull();
    });
});

describe('snapToCell', () => {
    const box = { x: 100, y: 50, width: 10, height: 8 };

    it('maps the top row of the stage to the top row of the box', () => {
        expect(snapToCell(0, 0, 16, box)).toEqual({ x: 100, y: 57 });
        expect(snapToCell(15.9, 15.9, 16, box)).toEqual({ x: 100, y: 57 });
    });

    it('grows y upward: the bottom row of the stage is the box bottom', () => {
        expect(snapToCell(16 * 3 + 2, 16 * 7 + 5, 16, box)).toEqual({
            x: 103,
            y: 50,
        });
    });

    it('floors pointers between cells', () => {
        expect(snapToCell(16.5, 16.5, 16, box)).toEqual({ x: 101, y: 56 });
    });
});

describe('removedFootprints', () => {
    const target = { x: 5, y: 6, width: 2, height: 1, label: 'Mesh Tile' };

    it('lists the targets of pending and conflicted removes and replaces', () => {
        expect(
            removedFootprints(
                plan([
                    change({ id: 1, kind: 'add' }),
                    change({ id: 2, kind: 'remove', target }),
                    change({
                        id: 3,
                        kind: 'replace',
                        state: 'conflicted',
                        target: { ...target, x: 9 },
                    }),
                ]),
            ),
        ).toEqual([
            { x: 5, y: 6, width: 2, height: 1, changeId: 2, overlapped: false },
            { x: 9, y: 6, width: 2, height: 1, changeId: 3, overlapped: false },
        ]);
    });

    it('skips applied replaces, whose target is gone from the game', () => {
        expect(
            removedFootprints(
                plan([change({ kind: 'replace', state: 'applied', target })]),
            ),
        ).toEqual([]);
    });

    it('skips an in-place replace: its new building covers the whole target', () => {
        expect(
            removedFootprints(
                plan([
                    change({
                        kind: 'replace',
                        x: 5,
                        y: 6,
                        width: 2,
                        height: 1,
                        target,
                    }),
                    change({
                        id: 2,
                        kind: 'replace',
                        x: 4,
                        y: 6,
                        width: 4,
                        height: 2,
                        target,
                    }),
                ]),
            ),
        ).toEqual([]);
    });

    it('marks a target the replacement only partly covers as overlapped', () => {
        expect(
            removedFootprints(
                plan([
                    change({
                        kind: 'replace',
                        x: 6,
                        y: 6,
                        width: 1,
                        height: 1,
                        target,
                    }),
                ]),
            ),
        ).toEqual([
            { x: 5, y: 6, width: 2, height: 1, changeId: 1, overlapped: true },
        ]);
    });

    it('skips changes without a target', () => {
        expect(
            removedFootprints(plan([change({ kind: 'remove', target: null })])),
        ).toEqual([]);
    });
});

describe('planChangeStatus', () => {
    it('shows conflicts and applied changes before the checked flag', () => {
        expect(
            planChangeStatus(change({ state: 'conflicted', checked: false })),
        ).toBe('conflicted');
        expect(planChangeStatus(change({ state: 'applied' }))).toBe('applied');
    });

    it('tells a pending checked change from an unchecked one', () => {
        expect(planChangeStatus(change({}))).toBe('pending');
        expect(planChangeStatus(change({ checked: false }))).toBe('unchecked');
    });
});
