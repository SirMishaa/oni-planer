import { describe, expect, it, vi } from 'vitest';
import { latestTime, liveReloadOptions } from './useZoneLive';

vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn() } }));
vi.mock('@laravel/echo-vue', () => ({ useEchoPublic: vi.fn() }));

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
