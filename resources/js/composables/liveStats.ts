import { reactive } from 'vue';
import type { ZoneUpdateKind } from '@/composables/useZoneLive';

/** Messages kept for the activity bars: the last minute. */
export const ACTIVITY_WINDOW_MS = 60_000;
/** Width of one activity bar. */
export const ACTIVITY_BUCKET_MS = 5_000;

/** The source of a message the page received. */
export type LiveSource = 'zone' | 'trips' | 'fast';

/** One relayed stream of the mod (`client-zone.trips` or `client-zone.fast`). */
export type RelayCounters = {
    /** Messages of the zone that reached the page. */
    received: number;
    /** Messages that moved at least one critter. */
    applied: number;
    /** Messages dropped: another zone, older than the stored state, or unreadable. */
    skipped: number;
    /** Payload size of the received messages, as sent over the socket. */
    bytes: number;
    lastAt: number | null;
};

/** What the page saw of the live link since it opened. */
export type LiveStats = {
    openedAt: number;
    /** `ZoneUpdated` events about this zone, by stream. */
    zone: Record<ZoneUpdateKind, { count: number; lastAt: number | null }>;
    reloads: {
        count: number;
        failed: number;
        /** Duration of the last finished reload. */
        lastMs: number | null;
        totalMs: number;
    };
    trips: RelayCounters;
    fast: RelayCounters;
    /** Time from receipt to the critters moving, for the last applied relayed message. */
    lastMoveDelayMs: number | null;
    /** Receipt times of the last minute, with their source, oldest first. */
    recent: { at: number; source: LiveSource }[];
};

function relayCounters(): RelayCounters {
    return { received: 0, applied: 0, skipped: 0, bytes: 0, lastAt: null };
}

export function createLiveStats(now: number = Date.now()): LiveStats {
    return reactive({
        openedAt: now,
        zone: {
            list: { count: 0, lastAt: null },
            layout: { count: 0, lastAt: null },
            slow: { count: 0, lastAt: null },
            fast: { count: 0, lastAt: null },
            capture: { count: 0, lastAt: null },
            plan: { count: 0, lastAt: null },
        },
        reloads: { count: 0, failed: 0, lastMs: null, totalMs: 0 },
        trips: relayCounters(),
        fast: relayCounters(),
        lastMoveDelayMs: null,
        recent: [],
    });
}

function remember(stats: LiveStats, source: LiveSource, now: number): void {
    stats.recent.push({ at: now, source });

    while (
        stats.recent.length > 0 &&
        stats.recent[0]!.at <= now - ACTIVITY_WINDOW_MS
    ) {
        stats.recent.shift();
    }
}

/** Size of a socket payload: its JSON text, or the length of a string payload. */
export function payloadBytes(data: unknown): number {
    if (typeof data === 'string') {
        return new Blob([data]).size;
    }

    try {
        return new Blob([JSON.stringify(data) ?? '']).size;
    } catch {
        return 0;
    }
}

export function recordZoneEvent(
    stats: LiveStats,
    kind: ZoneUpdateKind,
    now: number = Date.now(),
): void {
    stats.zone[kind].count++;
    stats.zone[kind].lastAt = now;
    remember(stats, 'zone', now);
}

export function recordReload(
    stats: LiveStats,
    durationMs: number,
    failed: boolean,
): void {
    stats.reloads.count++;
    stats.reloads.lastMs = durationMs;
    stats.reloads.totalMs += durationMs;

    if (failed) {
        stats.reloads.failed++;
    }
}

/**
 * A relayed message reached the page. `concerns` is false for another zone's
 * message (counted as skipped, not as received); `applied` is whether it moved
 * a critter, `delayMs` the time from receipt to that move.
 */
export function recordRelay(
    stats: LiveStats,
    source: 'trips' | 'fast',
    message: {
        bytes: number;
        concerns: boolean;
        applied: boolean;
        delayMs?: number;
    },
    now: number = Date.now(),
): void {
    const counters = stats[source];

    if (!message.concerns) {
        counters.skipped++;

        return;
    }

    counters.received++;
    counters.bytes += message.bytes;
    counters.lastAt = now;
    remember(stats, source, now);

    if (message.applied) {
        counters.applied++;
        stats.lastMoveDelayMs = message.delayMs ?? null;
    } else {
        counters.skipped++;
    }
}

/**
 * Messages per bar over the last minute, oldest bar first: one count per
 * source for each `ACTIVITY_BUCKET_MS` slice ending at `now`.
 */
export function activityBars(
    recent: LiveStats['recent'],
    now: number,
): Record<LiveSource, number>[] {
    const count = ACTIVITY_WINDOW_MS / ACTIVITY_BUCKET_MS;
    const bars = Array.from({ length: count }, () => ({
        zone: 0,
        trips: 0,
        fast: 0,
    }));

    for (const { at, source } of recent) {
        const age = now - at;

        if (age < 0 || age >= ACTIVITY_WINDOW_MS) {
            continue;
        }

        bars[count - 1 - Math.floor(age / ACTIVITY_BUCKET_MS)]![source]++;
    }

    return bars;
}

/** Messages received in the last minute. */
export function perMinute(recent: LiveStats['recent'], now: number): number {
    return recent.filter(({ at }) => now - at < ACTIVITY_WINDOW_MS).length;
}
