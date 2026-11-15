import { router } from '@inertiajs/vue3';
import { useEchoPublic } from '@laravel/echo-vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { latestTime, liveReloadOptions, useZoneLive } from './useZoneLive';

vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn() } }));
vi.mock('@laravel/echo-vue', () => ({ useEchoPublic: vi.fn() }));
vi.mock('@/composables/useT', () => ({
    useT: () => ({ t: (key: string) => key }),
}));
vi.mock('@/composables/useFormat', () => ({
    useFormat: () => ({ age: () => '' }),
}));

const response = (status: number) => ({ status, data: '', headers: {} });

describe('liveReloadOptions', () => {
    it('reloads only the given props', () => {
        expect(liveReloadOptions(['zone', 'map'], vi.fn()).only).toEqual([
            'zone',
            'map',
        ]);
    });

    it('treats a missing zone as gone, without the error dialog', () => {
        const onGone = vi.fn();
        const { onHttpException } = liveReloadOptions(['zone'], onGone);

        expect(onHttpException?.(response(404))).toBe(false);
        expect(onHttpException?.(response(410))).toBe(false);
        expect(onGone).toHaveBeenCalledTimes(2);
    });

    it('leaves other errors to Inertia', () => {
        const onGone = vi.fn();
        const { onHttpException } = liveReloadOptions(['zone'], onGone);

        expect(onHttpException?.(response(500))).toBeUndefined();
        expect(onGone).not.toHaveBeenCalled();
    });
});

describe('latestTime', () => {
    it('picks the latest known time', () => {
        expect(
            latestTime([
                null,
                '2026-11-12T10:00:00+00:00',
                '2026-11-12T11:00:00+00:00',
            ]),
        ).toBe('2026-11-12T11:00:00+00:00');
        expect(latestTime([null])).toBeNull();
    });
});

describe('useZoneLive', () => {
    const PROPS_BY_KIND: Record<string, string[]> = {
        layout: ['zone', 'map', 'mapTerrain', 'mapPlants', 'critters'],
        slow: ['zone', 'mapTerrain'],
        fast: ['zone', 'mapPlants', 'critters'],
    };
    let emit: (payload: {
        zoneGuid: string;
        colonyGuid: string;
        kind: string;
    }) => void;

    beforeEach(() => {
        vi.useFakeTimers();
        vi.mocked(router.reload).mockClear();
        vi.mocked(useEchoPublic).mockImplementation(((
            _channel: string,
            _event: string,
            callback: typeof emit,
        ) => {
            emit = callback;
        }) as never);
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        effectScope().run(() =>
            useZoneLive({
                echo: { channel: 'uplink', event: '.zone.updated' },
                zoneGuid: 'zone-1',
                only: (kind) => PROPS_BY_KIND[kind] ?? PROPS_BY_KIND.layout,
                updatedAt: null,
            }),
        );
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    const event = (kind: string) =>
        emit({ zoneGuid: 'zone-1', colonyGuid: 'c', kind });
    const reloaded = () =>
        vi.mocked(router.reload).mock.calls.map(([options]) => options?.only);

    it('reloads only the fast props on a fast event', () => {
        event('fast');
        vi.advanceTimersByTime(400);

        expect(reloaded()).toEqual([['zone', 'mapPlants', 'critters']]);
    });

    it('reloads everything on a layout event', () => {
        event('layout');
        vi.advanceTimersByTime(400);

        expect(reloaded()).toEqual([PROPS_BY_KIND.layout]);
    });

    it('keeps the props of every event of the debounce window', () => {
        event('slow');
        event('fast');
        vi.advanceTimersByTime(400);
        event('fast');
        vi.advanceTimersByTime(400);

        expect(reloaded()).toEqual([
            ['zone', 'mapTerrain', 'mapPlants', 'critters'],
            ['zone', 'mapPlants', 'critters'],
        ]);
    });

    it('ignores the events of another zone', () => {
        emit({ zoneGuid: 'zone-2', colonyGuid: 'c', kind: 'fast' });
        vi.advanceTimersByTime(400);

        expect(reloaded()).toEqual([]);
    });
});
