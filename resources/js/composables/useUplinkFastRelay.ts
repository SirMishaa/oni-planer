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
    triggerRef,
    watch,
} from 'vue';
import {
    applyFastFrame,
    applyReload,
    applyTripFrame,
    decodeUplinkPayload,
    frameIsAhead,
    overlayBook,
    readFastFrame,
    readTripFrame,
    type RelayBook,
    sampleOverridesTrip,
    type StoredFastState,
    type TripCheck,
    tripsFrameConcerns,
} from '@/components/zones/critterRelay';
import type {
    ZoneCritterData,
    ZoneData,
    ZoneMapData,
    ZoneShowData,
} from '@/types/App/Data/data.d';

/**
 * Moves the critters on what the mod sends straight to the browser, the
 * moment Reverb relays it, instead of waiting for the server to store the
 * state and the page to reload it:
 *
 * - its critter trips (`client-zone.trips`): a trip walks its whole path, a
 *   stop rests at the final cell;
 * - its raw fast frames (`client-zone.fast`): the sampled position and path.
 *
 * Only the motion of critters already on the page changes; new critters,
 * statuses and everything else wait for the reload. The relay holds the
 * latest motion of each critter ordered by session and game time
 * (`critterRelay.ts`), so neither a late frame nor a reload rewinds it.
 *
 * `crittersAt` replaces the props' `crittersAt` for the map: it changes on
 * every motion the relay applies and on every reload, and the map keeps the
 * track of each critter whose motion did not change. Without relayed
 * messages, both are the props'.
 */
export function useUplinkFastRelay(options: {
    relay: MaybeRefOrGetter<ZoneShowData['fastRelay']>;
    zone: MaybeRefOrGetter<ZoneData>;
    map: MaybeRefOrGetter<ZoneMapData | null>;
    critters: MaybeRefOrGetter<ZoneCritterData[] | null>;
    crittersAt: MaybeRefOrGetter<string | null>;
}): {
    critters: ComputedRef<ZoneCritterData[] | null>;
    crittersAt: Ref<string | null>;
} {
    const book = shallowRef<RelayBook>(new Map());
    const crittersAt = ref<string | null>(toValue(options.crittersAt));
    let disposed = false;

    const stored = (): StoredFastState => {
        const zone = toValue(options.zone);

        return {
            sessionId: zone.fastSessionId,
            gameSeconds: zone.fastGameSeconds,
        };
    };

    const target = (schema: number) => {
        const zone = toValue(options.zone);

        return { colonyGuid: zone.colonyGuid, zoneGuid: zone.guid, schema };
    };

    const overrides: TripCheck = (sample, trip, now) => {
        const map = toValue(options.map);

        return (
            map === null ||
            sampleOverridesTrip(sample, trip, now, map.gridWidth, map.box)
        );
    };

    /** A motion changed: the map rebuilds its tracks. */
    function applied(): void {
        triggerRef(book);
        crittersAt.value = new Date().toISOString();
    }

    /** The relayed message, or null when the page is gone or has no critters yet. */
    async function decode(
        data: unknown,
    ): Promise<Record<string, unknown> | null> {
        const message = await decodeUplinkPayload(data);

        return disposed || toValue(options.critters) === null ? null : message;
    }

    // region Messages
    const { channel, event, schema, tripsEvent, tripsSchema } = toValue(
        options.relay,
    );

    useEchoPublic<unknown>(channel, tripsEvent, async (data) => {
        if (!tripsFrameConcerns(data, toValue(options.zone).guid)) {
            return;
        }

        const message = await decode(data);
        const frame = message && readTripFrame(message, target(tripsSchema));

        if (
            frame &&
            applyTripFrame(book.value, frame, stored(), performance.now())
        ) {
            applied();
        }
    });

    useEchoPublic<unknown>(channel, event, async (data) => {
        const message = await decode(data);
        const frame = message && readFastFrame(message, target(schema));

        if (
            frame &&
            frameIsAhead(frame, stored()) &&
            applyFastFrame(book.value, frame, performance.now(), overrides)
        ) {
            applied();
        }
    });
    // endregion

    // region Reloads
    watch(
        () => toValue(options.crittersAt),
        (at) => {
            applyReload(
                book.value,
                toValue(options.critters) ?? [],
                stored(),
                performance.now(),
                overrides,
            );
            triggerRef(book);
            crittersAt.value =
                book.value.size > 0 ? new Date().toISOString() : at;
        },
    );
    // endregion

    onScopeDispose(() => {
        disposed = true;
    });

    return {
        critters: computed(() => {
            const critters = toValue(options.critters);

            return critters === null ? null : overlayBook(critters, book.value);
        }),
        crittersAt,
    };
}
