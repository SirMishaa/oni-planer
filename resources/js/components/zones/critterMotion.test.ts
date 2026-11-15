import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import { useCritterMotion } from '@/composables/useCritterMotion';
import type { ZoneCritterData } from '@/types/App/Data/data.d';
import {
    EASE_SECONDS,
    isReplayable,
    NON_LOOPING_SECONDS,
    positionAt,
    reconcile,
    trackFrom,
} from './critterMotion';

const box = { x: 170, y: 218, width: 12, height: 6 };
const gridWidth = 256;
const RECEIVED_AT = 1_000;

const cell = (x: number, y: number): number => y * gridWidth + x;

function critter(overrides: Partial<ZoneCritterData> = {}): ZoneCritterData {
    return {
        instanceId: '1234',
        prefabId: 'Hatch',
        name: 'Hatch',
        sprite: null,
        cell: cell(176, 221),
        x: 176.5,
        y: 221,
        facing: 'right',
        navType: 'Floor',
        moving: true,
        speed: 1,
        path: [
            { cell: cell(177, 221), navType: 'Floor' },
            { cell: cell(178, 221), navType: 'Floor' },
        ],
        aquatic: false,
        tame: true,
        baby: false,
        ageCycles: 4,
        lifespanCycles: 25,
        caloriesRatio: 0.5,
        fertilityRatio: 0.2,
        breeding: [],
        happiness: 0,
        happinessState: 'neutral',
        stomach: [],
        nextPoop: null,
        hungry: false,
        statuses: [],
        regionId: 'cavity:12',
        ...overrides,
    } as ZoneCritterData;
}

const at = (seconds: number): number => RECEIVED_AT + seconds * 1000;

describe('critter motion', () => {
    it('moves along the path at its speed', () => {
        const track = trackFrom(
            critter({ speed: 2 }),
            RECEIVED_AT,
            gridWidth,
            box,
        );

        expect(positionAt(track, at(0))).toMatchObject({ x: 176.5, y: 221 });
        expect(positionAt(track, at(0.25)).x).toBeCloseTo(177);
        expect(positionAt(track, at(0.5)).x).toBeCloseTo(177.5);
        expect(positionAt(track, at(0.75)).x).toBeCloseTo(178);
    });

    it('takes a fixed time for a jump, whatever the speed', () => {
        const track = trackFrom(
            critter({
                speed: 4,
                path: [{ cell: cell(177, 222), navType: 'Floor' }],
            }),
            RECEIVED_AT,
            gridWidth,
            box,
        );
        const half = positionAt(track, at(NON_LOOPING_SECONDS / 2));

        expect(half.x).toBeCloseTo(177);
        expect(half.y).toBeCloseTo(221.5);
        expect(positionAt(track, at(NON_LOOPING_SECONDS))).toMatchObject({
            x: 177.5,
            y: 222,
        });
    });

    it('stops at the end of the path', () => {
        const track = trackFrom(critter(), RECEIVED_AT, gridWidth, box);

        expect(positionAt(track, at(2))).toMatchObject({ x: 178.5, y: 221 });
        expect(positionAt(track, at(60))).toMatchObject({ x: 178.5, y: 221 });
    });

    it('stops where the path leaves the box', () => {
        const track = trackFrom(
            critter({
                path: [
                    { cell: cell(181, 221), navType: 'Floor' },
                    { cell: cell(182, 221), navType: 'Floor' },
                    { cell: cell(183, 221), navType: 'Floor' },
                ],
                cell: cell(180, 221),
                x: 180.5,
            }),
            RECEIVED_AT,
            gridWidth,
            box,
        );

        expect(positionAt(track, at(60))).toMatchObject({ x: 181.5, y: 221 });
    });

    it('does not move a still or carried critter', () => {
        const still = trackFrom(
            critter({ moving: false }),
            RECEIVED_AT,
            gridWidth,
            box,
        );
        const pathless = trackFrom(
            critter({ path: [] }),
            RECEIVED_AT,
            gridWidth,
            box,
        );
        const carried = trackFrom(
            critter({
                moving: false,
                path: [],
                regionId: null,
                x: 300.2,
                y: 10,
            }),
            RECEIVED_AT,
            gridWidth,
            box,
        );
        const speedless = trackFrom(
            critter({ speed: 0 }),
            RECEIVED_AT,
            gridWidth,
            box,
        );

        expect(positionAt(still, at(3))).toMatchObject({ x: 176.5, y: 221 });
        expect(positionAt(pathless, at(3))).toMatchObject({ x: 176.5, y: 221 });
        expect(positionAt(carried, at(3))).toMatchObject({ x: 300.2, y: 10 });
        expect(positionAt(speedless, at(3))).toMatchObject({
            x: 176.5,
            y: 221,
        });
    });

    it('eases a small correction and jumps a large one', () => {
        const next = trackFrom(
            critter({ moving: false }),
            RECEIVED_AT,
            gridWidth,
            box,
        );

        const eased = reconcile({ x: 175.5, y: 221 }, next, RECEIVED_AT);
        expect(positionAt(eased, at(0)).x).toBeCloseTo(175.5);
        const midway = positionAt(eased, at(EASE_SECONDS / 2)).x;
        expect(midway).toBeGreaterThan(175.5);
        expect(midway).toBeLessThan(176.5);
        expect(positionAt(eased, at(EASE_SECONDS)).x).toBeCloseTo(176.5);

        const jumped = reconcile({ x: 170.5, y: 221 }, next, RECEIVED_AT);
        expect(positionAt(jumped, at(0)).x).toBeCloseTo(176.5);
    });

    it('keeps moving while it eases', () => {
        const next = trackFrom(critter(), RECEIVED_AT, gridWidth, box);
        const eased = reconcile({ x: 176, y: 221 }, next, RECEIVED_AT);

        expect(positionAt(eased, at(EASE_SECONDS)).x).toBeCloseTo(
            176.5 + EASE_SECONDS,
        );
    });

    it('keeps facing on a vertical step', () => {
        const track = trackFrom(
            critter({
                facing: 'left',
                navType: 'Swim',
                x: 176.42,
                path: [
                    { cell: cell(176, 222), navType: 'Swim' },
                    { cell: cell(176, 223), navType: 'Swim' },
                ],
            }),
            RECEIVED_AT,
            gridWidth,
            box,
        );

        expect(track.path.map((step) => step.facing)).toEqual(['left', 'left']);
        expect(positionAt(track, at(0.5)).facing).toBe('left');
    });

    it('turns facing with the direction of travel', () => {
        const track = trackFrom(
            critter({
                facing: 'left',
                path: [
                    { cell: cell(177, 221), navType: 'Floor' },
                    { cell: cell(176, 221), navType: 'Floor' },
                ],
            }),
            RECEIVED_AT,
            gridWidth,
            box,
        );

        expect(positionAt(track, at(0.5)).facing).toBe('right');
        expect(positionAt(track, at(1.5)).facing).toBe('left');
        expect(positionAt(track, at(60)).facing).toBe('left');
        expect(
            positionAt(
                trackFrom(
                    critter({ moving: false }),
                    RECEIVED_AT,
                    gridWidth,
                    box,
                ),
                at(1),
            ).facing,
        ).toBe('right');
    });

    it('does not replay a state the game sent long ago', () => {
        const sentAt = Date.parse('2026-11-15T10:00:00Z');

        expect(isReplayable('2026-11-15T10:00:00+00:00', sentAt + 3_000)).toBe(
            true,
        );
        expect(isReplayable('2026-11-15T10:00:00+00:00', sentAt + 60_000)).toBe(
            false,
        );
        expect(isReplayable(null, sentAt)).toBe(false);
    });
});

describe('useCritterMotion', () => {
    let frames: FrameRequestCallback[] = [];

    beforeEach(() => {
        frames = [];
        vi.stubGlobal(
            'requestAnimationFrame',
            (callback: FrameRequestCallback) => {
                frames.push(callback);

                return frames.length;
            },
        );
        vi.stubGlobal('cancelAnimationFrame', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    function runFrame(now: number): void {
        const pending = frames;
        frames = [];
        pending.forEach((callback) => callback(now));
    }

    it('moves critters frame by frame and rests at the end of their path', () => {
        const scope = effectScope();
        const critters = ref<ZoneCritterData[] | null>([critter()]);
        const receivedAt = ref(RECEIVED_AT);
        const positions = scope.run(() =>
            useCritterMotion(critters, receivedAt, { gridWidth, box }),
        )!;

        runFrame(at(1));
        expect(positions.value.get('1234')?.x).toBeCloseTo(177.5);
        runFrame(at(2));
        expect(positions.value.get('1234')?.x).toBeCloseTo(178.5);
        expect(frames).toHaveLength(0);

        scope.stop();
    });

    // No reset branch is needed: each state builds its tracks from its own critters only. This
    // checks the outcome: 9001 stands 0.5 cell from where 1234 was drawn (177.5), yet starts
    // where the game saw it, and nothing of the previous session is kept.
    it('starts a new session from the positions the game sent', async () => {
        const scope = effectScope();
        const critters = ref<ZoneCritterData[] | null>([
            critter(),
            critter({ instanceId: '1235', x: 172.5, cell: cell(172, 221) }),
        ]);
        const receivedAt = ref(RECEIVED_AT);
        const positions = scope.run(() =>
            useCritterMotion(critters, receivedAt, { gridWidth, box }),
        )!;

        critters.value = [
            critter({ instanceId: '9001', x: 177, moving: false }),
            critter({
                instanceId: '9002',
                x: 172.5,
                cell: cell(172, 221),
                moving: false,
            }),
        ];
        receivedAt.value = at(1);
        await nextTick();

        expect([...positions.value.keys()].sort()).toEqual(['9001', '9002']);
        expect(positions.value.get('9001')?.x).toBeCloseTo(177);

        scope.stop();
    });

    it('eases a critter seen again from where it was drawn', async () => {
        const scope = effectScope();
        const critters = ref<ZoneCritterData[] | null>([critter()]);
        const receivedAt = ref(RECEIVED_AT);
        const positions = scope.run(() =>
            useCritterMotion(critters, receivedAt, { gridWidth, box }),
        )!;

        // Drawn at 177.5 after one second; the game reports 177.0.
        critters.value = [critter({ x: 177, moving: false })];
        receivedAt.value = at(1);
        await nextTick();

        expect(positions.value.get('1234')?.x).toBeCloseTo(177.5);

        scope.stop();
    });
});
