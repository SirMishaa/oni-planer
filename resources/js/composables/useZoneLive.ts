import { router } from '@inertiajs/vue3';
import { useEchoPublic } from '@laravel/echo-vue';
import {
    computed,
    type ComputedRef,
    type MaybeRefOrGetter,
    onBeforeUnmount,
    onMounted,
    ref,
    toValue,
} from 'vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';

/** Debounce of the reload after a zone event (trailing edge), as in `useUplink`. */
const RELOAD_DEBOUNCE_MS = 400;
/** The zone counts as live while its latest state is younger than this (fast state every 5 s). */
const LIVE_WINDOW_MS = 20_000;
const CLOCK_TICK_MS = 1_000;

/** Payload of `App\Events\ZoneUpdated`. */
type ZoneUpdatedPayload = {
    zoneGuid: string;
    colonyGuid: string;
    kind: 'list' | 'layout' | 'slow' | 'fast';
};

/**
 * Listens to `.zone.updated` on the public `uplink` channel and reloads the
 * given props (debounced) when the event is about `zoneGuid`, or about any
 * zone when it is null (the zones list). `updatedAt` is the latest time the
 * page's data was received; it drives the live dot and the age label.
 */
export function useZoneLive(
    zoneGuid: MaybeRefOrGetter<string | null>,
    only: string[],
    updatedAt: MaybeRefOrGetter<string | null>,
): { live: ComputedRef<boolean>; ageLabel: ComputedRef<string> } {
    const i18n = useT();
    const format = useFormat();

    // region Reload on events
    let pendingReload: ReturnType<typeof setTimeout> | undefined;

    useEchoPublic<ZoneUpdatedPayload>('uplink', '.zone.updated', (payload) => {
        const guid = toValue(zoneGuid);

        if (guid !== null && payload.zoneGuid !== guid) {
            return;
        }

        clearTimeout(pendingReload);
        pendingReload = setTimeout(
            () => router.reload({ only }),
            RELOAD_DEBOUNCE_MS,
        );
    });
    // endregion

    // region Age
    const now = ref(Date.now());
    let clock: ReturnType<typeof setInterval> | undefined;

    onMounted(() => {
        clock = setInterval(() => {
            now.value = Date.now();
        }, CLOCK_TICK_MS);
    });

    onBeforeUnmount(() => {
        clearInterval(clock);
        clearTimeout(pendingReload);
    });

    const elapsedMs = computed(() => {
        const at = toValue(updatedAt);
        const seenMs = at === null ? NaN : Date.parse(at);

        return Number.isNaN(seenMs) ? null : Math.max(0, now.value - seenMs);
    });

    const live = computed(
        () => elapsedMs.value !== null && elapsedMs.value < LIVE_WINDOW_MS,
    );

    const ageLabel = computed(() => {
        if (elapsedMs.value === null) {
            return i18n.t('colony.status.never');
        }

        return live.value
            ? i18n.t('colony.status.live')
            : i18n.t('colony.status.seen', {
                  age: format.age(elapsedMs.value),
              });
    });
    // endregion

    return { live, ageLabel };
}

/** Latest of several ISO times, null when none is set. */
export function latestTime(times: readonly (string | null)[]): string | null {
    return times.reduce<string | null>((latest, time) => {
        if (time === null) {
            return latest;
        }

        return latest === null || Date.parse(time) > Date.parse(latest)
            ? time
            : latest;
    }, null);
}
