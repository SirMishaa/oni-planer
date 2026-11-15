import { useEchoPublic } from '@laravel/echo-vue';
import {
    computed,
    type ComputedRef,
    type MaybeRefOrGetter,
    onScopeDispose,
    type Ref,
    ref,
    shallowRef,
    toValue,
    watch,
} from 'vue';
import {
    decodeUplinkPayload,
    frameIsAhead,
    overlayRelay,
    readFastFrame,
    type RelayFrame,
    reloadMovesCritters,
    type StoredFastState,
} from '@/components/zones/critterRelay';
import type {
    ZoneCritterData,
    ZoneData,
    ZoneShowData,
} from '@/types/App/Data/data.d';

/**
 * Moves the critters on the mod's raw fast frames, the moment Reverb relays
 * them, instead of waiting for the server to store the state and the page
 * to reload it. Only the motion of critters already on the page changes;
 * new critters, statuses and everything else wait for the reload.
 *
 * `crittersAt` replaces the props' `crittersAt` for the map: it changes when
 * a frame is applied, and on a reload only when the stored state is newer
 * than that frame, so a reload of a state already shown never moves a
 * critter backward. Without frames (relay silent), both are the props'.
 */
export function useUplinkFastRelay(options: {
    relay: MaybeRefOrGetter<ZoneShowData['fastRelay']>;
    zone: MaybeRefOrGetter<ZoneData>;
    critters: MaybeRefOrGetter<ZoneCritterData[] | null>;
    crittersAt: MaybeRefOrGetter<string | null>;
}): {
    critters: ComputedRef<ZoneCritterData[] | null>;
    crittersAt: Ref<string | null>;
} {
    /** The latest frame applied, until the stored state passes it. */
    const applied = shallowRef<RelayFrame | null>(null);
    const crittersAt = ref<string | null>(toValue(options.crittersAt));
    let disposed = false;

    const stored = (): StoredFastState => {
        const zone = toValue(options.zone);

        return {
            sessionId: zone.fastSessionId,
            gameSeconds: zone.fastGameSeconds,
        };
    };

    // region Frames
    const { channel, event } = toValue(options.relay);

    useEchoPublic<unknown>(channel, event, async (data) => {
        const message = await decodeUplinkPayload(data);

        if (
            disposed ||
            message === null ||
            toValue(options.critters) === null
        ) {
            return;
        }

        const zone = toValue(options.zone);
        const frame = readFastFrame(message, {
            colonyGuid: zone.colonyGuid,
            zoneGuid: zone.guid,
            schema: toValue(options.relay).schema,
        });

        // Frames decode asynchronously: one that lands after a newer one is dropped here.
        if (
            frame === null ||
            !frameIsAhead(frame, stored()) ||
            (applied.value !== null && !frameIsAhead(frame, applied.value))
        ) {
            return;
        }

        applied.value = frame;
        crittersAt.value = new Date().toISOString();
    });
    // endregion

    // region Reloads
    watch(
        () => toValue(options.crittersAt),
        (at) => {
            if (reloadMovesCritters(stored(), applied.value)) {
                applied.value = null;
                crittersAt.value = at;
            }
        },
    );
    // endregion

    onScopeDispose(() => {
        disposed = true;
    });

    return {
        critters: computed(() => {
            const critters = toValue(options.critters);

            return critters === null
                ? null
                : overlayRelay(critters, applied.value, stored());
        }),
        crittersAt,
    };
}
