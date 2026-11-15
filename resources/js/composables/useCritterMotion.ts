import {
    type MaybeRefOrGetter,
    onScopeDispose,
    type Ref,
    type ShallowRef,
    shallowRef,
    toValue,
    triggerRef,
    watch,
} from 'vue';
import {
    advanceTracks,
    buildTracks,
    type CritterPose,
    type MotionTrack,
} from '@/components/zones/critterMotion';
import type { ZoneBoxData, ZoneCritterData } from '@/types/App/Data/data.d';

/**
 * Drawn pose of each critter, replayed between two fast states: it travels
 * from where it is drawn to its new game position, then walks the path the
 * game sent. The tracks
 * are rebuilt when `receivedAt` (ms, on the `requestAnimationFrame` clock)
 * changes: the caller bumps it once per new fast state, never for a reload
 * that resends the same critters. Each frame the poses are updated in place
 * and the ref is triggered; the loop stops once every critter rests and while
 * the tab is hidden.
 */
export function useCritterMotion(
    critters: Ref<ZoneCritterData[] | null>,
    receivedAt: Ref<number>,
    options: {
        gridWidth: MaybeRefOrGetter<number>;
        box: MaybeRefOrGetter<ZoneBoxData>;
        /** False to draw every critter where the game sent it (reduced motion, stale state). */
        replay?: MaybeRefOrGetter<boolean>;
    },
): ShallowRef<Map<string, CritterPose>> {
    const poses = shallowRef(new Map<string, CritterPose>());
    let tracks = new Map<string, MotionTrack>();
    let moving = false;
    let frame = 0;
    const hasDocument = typeof document !== 'undefined';

    // region Loop
    function schedule(): void {
        if (frame === 0 && moving && !(hasDocument && document.hidden)) {
            frame = requestAnimationFrame(step);
        }
    }

    function step(now: number): void {
        frame = 0;
        moving = advanceTracks(tracks, now, poses.value);
        triggerRef(poses);
        schedule();
    }

    function onVisibilityChange(): void {
        if (document.hidden) {
            cancelAnimationFrame(frame);
            frame = 0;
        } else {
            schedule();
        }
    }
    // endregion

    // region Tracks
    function rebuild(): void {
        const at = receivedAt.value;
        tracks = buildTracks(
            tracks,
            critters.value ?? [],
            at,
            toValue(options.gridWidth),
            toValue(options.box),
            toValue(options.replay) ?? true,
        );
        const next = new Map<string, CritterPose>();
        moving = advanceTracks(tracks, at, next);
        poses.value = next;
        schedule();
    }

    rebuild();
    watch(receivedAt, rebuild);
    // endregion

    if (hasDocument) {
        document.addEventListener('visibilitychange', onVisibilityChange);
    }

    onScopeDispose(() => {
        cancelAnimationFrame(frame);
        frame = 0;

        if (hasDocument) {
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
        }
    });

    return poses;
}
