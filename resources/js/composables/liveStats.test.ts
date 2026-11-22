import { describe, expect, it } from 'vitest';
import {
    activityBars,
    createLiveStats,
    perMinute,
    recordRelay,
    recordReload,
    recordZoneEvent,
} from '@/composables/liveStats';

describe('liveStats', () => {
    it('counts zone events by stream', () => {
        const stats = createLiveStats(0);

        recordZoneEvent(stats, 'fast', 1_000);
        recordZoneEvent(stats, 'fast', 6_000);
        recordZoneEvent(stats, 'slow', 7_000);

        expect(stats.zone.fast).toEqual({ count: 2, lastAt: 6_000 });
        expect(stats.zone.slow).toEqual({ count: 1, lastAt: 7_000 });
        expect(stats.zone.layout.count).toBe(0);
    });

    it('counts the plan events Claude and the player send', () => {
        const stats = createLiveStats(0);

        recordZoneEvent(stats, 'plan', 2_000);

        expect(stats.zone.plan).toEqual({ count: 1, lastAt: 2_000 });
    });

    it('counts another zone relayed message as skipped, not received', () => {
        const stats = createLiveStats(0);

        recordRelay(
            stats,
            'trips',
            { bytes: 300, concerns: false, applied: false },
            1_000,
        );

        expect(stats.trips).toMatchObject({
            received: 0,
            skipped: 1,
            bytes: 0,
        });
        expect(stats.recent).toEqual([]);
    });

    it('keeps the move delay of the last applied relayed message', () => {
        const stats = createLiveStats(0);

        recordRelay(
            stats,
            'fast',
            { bytes: 120, concerns: true, applied: true, delayMs: 31 },
            1_000,
        );
        recordRelay(
            stats,
            'fast',
            { bytes: 80, concerns: true, applied: false, delayMs: 5 },
            2_000,
        );

        expect(stats.fast).toMatchObject({
            received: 2,
            applied: 1,
            skipped: 1,
            bytes: 200,
            lastAt: 2_000,
        });
        expect(stats.lastMoveDelayMs).toBe(31);
    });

    it('times reloads and counts failures', () => {
        const stats = createLiveStats(0);

        recordReload(stats, 40, false);
        recordReload(stats, 60, true);

        expect(stats.reloads).toEqual({
            count: 2,
            failed: 1,
            lastMs: 60,
            totalMs: 100,
        });
    });

    it('buckets the last minute in twelve bars, newest last', () => {
        const stats = createLiveStats(0);

        recordZoneEvent(stats, 'fast', 1_000);
        recordRelay(
            stats,
            'trips',
            { bytes: 1, concerns: true, applied: true },
            58_000,
        );
        recordRelay(
            stats,
            'trips',
            { bytes: 1, concerns: true, applied: true },
            59_500,
        );

        const bars = activityBars(stats.recent, 60_000);

        expect(bars).toHaveLength(12);
        expect(bars[0]).toEqual({ zone: 1, trips: 0, fast: 0 });
        expect(bars[11]).toEqual({ zone: 0, trips: 2, fast: 0 });
        expect(perMinute(stats.recent, 60_000)).toBe(3);
    });

    it('forgets messages older than a minute', () => {
        const stats = createLiveStats(0);

        recordZoneEvent(stats, 'fast', 1_000);
        recordZoneEvent(stats, 'fast', 70_000);

        expect(stats.recent).toEqual([{ at: 70_000, source: 'zone' }]);
        expect(perMinute(stats.recent, 70_000)).toBe(1);
    });
});
