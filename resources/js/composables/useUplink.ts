import { router } from '@inertiajs/vue3';
import { useEchoPublic } from '@laravel/echo-vue';
import {
    computed,
    type ComputedRef,
    type MaybeRef,
    onBeforeUnmount,
    onMounted,
    ref,
    toValue,
} from 'vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import type { ColonyStatusData } from '@/types/App/Data/data.d';

/** Debounce of the reload after a state event (trailing edge). */
const RELOAD_DEBOUNCE_MS = 400;
/** The link counts as live while the last state is younger than this. */
const LIVE_WINDOW_MS = 20_000;
const CLOCK_TICK_MS = 1_000;

/**
 * Listens to the mod's state events on the public `uplink` channel and
 * reloads the given props (debounced), so every figure still comes from the
 * server. `enabled` false keeps listening but ignores the events.
 */
export function useUplink(
    status: MaybeRef<ColonyStatusData>,
    only: string[],
    enabled: MaybeRef<boolean> = true,
): { live: ComputedRef<boolean>; ageLabel: ComputedRef<string> } {
    const i18n = useT();
    const format = useFormat();

    // region Reload on events
    let pendingReload: ReturnType<typeof setTimeout> | undefined;
    const { channel, event } = toValue(status).echo;

    useEchoPublic(channel, event, () => {
        if (!toValue(enabled)) {
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
        const lastSeenAt = toValue(status).lastSeenAt;
        const seenMs = lastSeenAt === null ? NaN : Date.parse(lastSeenAt);

        return Number.isNaN(seenMs) ? null : now.value - seenMs;
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
