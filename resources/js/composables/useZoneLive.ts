import { router } from '@inertiajs/vue3';
import { useEchoPublic } from '@laravel/echo-vue';
import {
    computed,
    type ComputedRef,
    type MaybeRefOrGetter,
    onBeforeUnmount,
    onMounted,
    type Ref,
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

/** Options of `router.reload`, typed through `@inertiajs/vue3` (core is not a direct dependency). */
type ReloadOptions = NonNullable<Parameters<typeof router.reload>[0]>;

/** Statuses that mean the reloaded page no longer exists (the zone was archived or deleted). */
const GONE_STATUSES = [404, 410];

/** The channel and event the page was given by the server (`echo` prop). */
export type ZoneEcho = { channel: string; event: string };

/**
 * Reload options for a live refresh: a 404 or 410 calls `onGone` and
 * returns false, so Inertia shows no error dialog; any other error keeps
 * Inertia's default handling.
 */
export function liveReloadOptions(
    only: string[],
    onGone: () => void,
): ReloadOptions {
    return {
        only,
        onHttpException: (response) => {
            if (!GONE_STATUSES.includes(response.status)) {
                return;
            }

            onGone();

            return false;
        },
    };
}

/**
 * Listens to the zone event of `echo` and reloads the given props
 * (debounced) when the event is about `zoneGuid`, or about any zone when it
 * is null (the zones list). `updatedAt` is the latest time the page's data
 * was received; it drives the live dot and the age label. When a reload
 * finds the page gone, `gone` turns true, `onGone` runs and the events are
 * ignored from then on.
 */
export function useZoneLive(options: {
    echo: MaybeRefOrGetter<ZoneEcho>;
    zoneGuid: MaybeRefOrGetter<string | null>;
    only: string[];
    updatedAt: MaybeRefOrGetter<string | null>;
    onGone?: () => void;
}): {
    live: ComputedRef<boolean>;
    ageLabel: ComputedRef<string>;
    gone: Ref<boolean>;
    now: Ref<number>;
} {
    const { zoneGuid, only, updatedAt } = options;
    const i18n = useT();
    const format = useFormat();

    // region Reload on events
    let pendingReload: ReturnType<typeof setTimeout> | undefined;
    const gone = ref(false);
    const reloadOptions = liveReloadOptions(only, () => {
        gone.value = true;
        options.onGone?.();
    });
    const { channel, event } = toValue(options.echo);

    useEchoPublic<ZoneUpdatedPayload>(channel, event, (payload) => {
        const guid = toValue(zoneGuid);

        if (gone.value || (guid !== null && payload.zoneGuid !== guid)) {
            return;
        }

        clearTimeout(pendingReload);
        pendingReload = setTimeout(
            () => router.reload(reloadOptions),
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

    return { live, ageLabel, gone, now };
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
