<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { echo, useConnectionStatus, useSocketId } from '@laravel/echo-vue';
import {
    PopoverArrow,
    PopoverContent,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, ref, watch } from 'vue';
import ColonyController from '@/actions/App/Http/Controllers/ColonyController';
import {
    ACTIVITY_BUCKET_MS,
    activityBars,
    type LiveSource,
    type LiveStats,
    perMinute,
    type RelayCounters,
} from '@/composables/liveStats';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import type { ZoneUpdateKind } from '@/composables/useZoneLive';

// region Props

const props = defineProps<{
    live: boolean;
    ageLabel: string;
    stats: LiveStats;
    /** The page clock (ms), ticking every second. */
    now: number;
    /** The game's colony heartbeat is fresh. */
    linked: boolean;
}>();

// endregion

// region Text

const { t, tc } = useT();
const format = useFormat();

const ago = (at: number | null): string =>
    at === null
        ? t('zones.live.never')
        : format.age(Math.max(0, props.now - at));

const ms = (value: number | null): string =>
    value === null ? t('zones.live.none') : `${format.number(value)} ms`;

const bytes = (value: number): string =>
    value < 1024
        ? `${format.number(value)} o`
        : value < 1024 * 1024
          ? `${format.number(value / 1024, 1)} ko`
          : `${format.number(value / 1024 / 1024, 1)} Mo`;

// endregion

// region Activity

const SOURCES: LiveSource[] = ['zone', 'trips', 'fast'];

const bars = computed(() => activityBars(props.stats.recent, props.now));
const barTotals = computed(() =>
    bars.value.map((bar) => bar.zone + bar.trips + bar.fast),
);
const barMax = computed(() => Math.max(1, ...barTotals.value));
const rate = computed(() => perMinute(props.stats.recent, props.now));

/** Bumped on each message: restarts the dot's pulse. */
const pulse = computed(() => props.stats.recent.length + rate.value);

// endregion

// region Socket

const status = useConnectionStatus();
const socketId = useSocketId();
const connectedSince = ref<number | null>(
    status.value === 'connected' ? Date.now() : null,
);
const reconnects = ref(0);
let wasConnected = status.value === 'connected';

watch(status, (state) => {
    if (state === 'connected') {
        if (wasConnected) {
            reconnects.value++;
        }

        wasConnected = true;
        connectedSince.value = Date.now();
    } else {
        connectedSince.value = null;
    }
});

/** `host:port` of the socket server, from the Echo options; null when unknown. */
const host = computed((): string | null => {
    try {
        const options = (
            echo() as unknown as {
                options?: {
                    wsHost?: string;
                    wsPort?: number;
                    forceTLS?: boolean;
                };
            }
        ).options;

        if (!options?.wsHost) {
            return null;
        }

        const scheme = options.forceTLS ? 'wss' : 'ws';

        return `${scheme}://${options.wsHost}${options.wsPort ? `:${options.wsPort}` : ''}`;
    } catch {
        return null;
    }
});

const statusTone = computed(() =>
    status.value === 'connected'
        ? 'ok'
        : status.value === 'failed' || status.value === 'disconnected'
          ? 'bad'
          : 'warn',
);

// endregion

// region Server updates

const ZONE_KINDS: ZoneUpdateKind[] = [
    'fast',
    'slow',
    'layout',
    'capture',
    'plan',
];

const reloadAverage = computed(() =>
    props.stats.reloads.count === 0
        ? null
        : props.stats.reloads.totalMs / props.stats.reloads.count,
);

// endregion

// region Relay

const relays = computed(
    (): { source: 'trips' | 'fast'; counters: RelayCounters }[] => [
        { source: 'trips', counters: props.stats.trips },
        { source: 'fast', counters: props.stats.fast },
    ],
);

const relayBytes = computed(
    () => props.stats.trips.bytes + props.stats.fast.bytes,
);

// endregion
</script>

<template>
    <PopoverRoot>
        <!-- region Trigger -->
        <PopoverTrigger
            class="live-pill"
            :class="{ 'live-pill--on': live }"
            :aria-label="t('zones.live.open')"
        >
            <span
                :key="pulse"
                class="live-pill__dot"
                :class="{ 'live-pill__dot--beat': rate > 0 }"
                aria-hidden="true"
            />
            <span role="status">{{ ageLabel }}</span>
            <span class="live-pill__bars" aria-hidden="true">
                <span
                    v-for="(total, index) in barTotals"
                    :key="index"
                    class="live-pill__bar"
                    :style="{
                        height: `${Math.max(2, (total / barMax) * 14)}px`,
                    }"
                    :class="{ 'live-pill__bar--empty': total === 0 }"
                />
            </span>
            <span class="live-pill__rate">{{
                t('zones.live.per_minute', { count: format.number(rate) })
            }}</span>
        </PopoverTrigger>
        <!-- endregion -->

        <PopoverContent
            class="live-panel"
            side="bottom"
            align="end"
            :side-offset="8"
            :collision-padding="16"
        >
            <!-- region Head -->
            <header class="live-panel__head">
                <span
                    class="live-panel__state"
                    :class="`live-panel__state--${live ? 'ok' : 'idle'}`"
                >
                    <span class="live-pill__dot" aria-hidden="true" />
                    {{ t('zones.live.title') }}
                </span>
                <span class="live-panel__age">{{ ageLabel }}</span>
            </header>
            <!-- endregion -->

            <!-- region Activity -->
            <section class="live-panel__activity">
                <div class="live-panel__activity-head">
                    <span class="live-panel__label">{{
                        t('zones.live.activity')
                    }}</span>
                    <span class="live-panel__big">{{
                        t('zones.live.per_minute', {
                            count: format.number(rate),
                        })
                    }}</span>
                </div>
                <div
                    class="live-chart"
                    role="img"
                    :aria-label="t('zones.live.activity')"
                >
                    <div
                        v-for="(bar, index) in bars"
                        :key="index"
                        class="live-chart__col"
                        :title="`${format.number(barTotals[index] ?? 0)} · ${(bars.length - index) * (ACTIVITY_BUCKET_MS / 1000)} s`"
                    >
                        <span
                            v-for="source in SOURCES"
                            :key="source"
                            class="live-chart__seg"
                            :class="`live-chart__seg--${source}`"
                            :style="{
                                height: `${(bar[source] / barMax) * 100}%`,
                            }"
                        />
                    </div>
                </div>
                <ul class="live-legend">
                    <li v-for="source in SOURCES" :key="source">
                        <span
                            class="live-legend__swatch"
                            :class="`live-chart__seg--${source}`"
                        />
                        {{ t(`zones.live.legend.${source}`) }}
                    </li>
                </ul>
            </section>
            <!-- endregion -->

            <!-- region Socket -->
            <section class="live-panel__section">
                <h3 class="live-panel__heading">
                    {{ t('zones.live.socket.heading') }}
                </h3>
                <dl class="live-rows">
                    <dt>{{ t('zones.live.socket.state') }}</dt>
                    <dd>
                        <span
                            class="live-chip"
                            :class="`live-chip--${statusTone}`"
                            >{{ t(`zones.live.socket.states.${status}`) }}</span
                        >
                    </dd>
                    <dt>{{ t('zones.live.socket.since') }}</dt>
                    <dd>{{ ago(connectedSince) }}</dd>
                    <dt>{{ t('zones.live.socket.reconnects') }}</dt>
                    <dd class="live-num">
                        {{ format.number(reconnects) }}
                    </dd>
                    <template v-if="host">
                        <dt>{{ t('zones.live.socket.host') }}</dt>
                        <dd class="live-mono">{{ host }}</dd>
                    </template>
                    <template v-if="socketId">
                        <dt>{{ t('zones.live.socket.socket_id') }}</dt>
                        <dd class="live-mono">{{ socketId }}</dd>
                    </template>
                    <dt>{{ t('zones.live.socket.game') }}</dt>
                    <dd>
                        <span
                            class="live-chip"
                            :class="
                                linked ? 'live-chip--ok' : 'live-chip--idle'
                            "
                            >{{
                                linked
                                    ? t('zones.live.socket.game_linked')
                                    : t('zones.live.socket.game_quiet')
                            }}</span
                        >
                    </dd>
                </dl>
            </section>
            <!-- endregion -->

            <!-- region Relay -->
            <section class="live-panel__section">
                <h3 class="live-panel__heading">
                    <span class="live-legend__swatch live-chart__seg--trips" />
                    {{ t('zones.live.relay.heading') }}
                </h3>
                <p class="live-panel__hint">
                    {{ t('zones.live.relay.hint') }}
                </p>
                <dl class="live-rows">
                    <template
                        v-for="{ source, counters } in relays"
                        :key="source"
                    >
                        <dt>{{ t(`zones.live.relay.${source}`) }}</dt>
                        <dd>
                            <span class="live-num live-num--strong">{{
                                format.number(counters.received)
                            }}</span>
                            <span class="live-sub">
                                {{
                                    t('zones.live.relay.applied', {
                                        count: format.number(counters.applied),
                                    })
                                }}
                                ·
                                {{
                                    t('zones.live.relay.skipped', {
                                        count: format.number(counters.skipped),
                                    })
                                }}
                                · {{ ago(counters.lastAt) }}
                            </span>
                        </dd>
                    </template>
                    <dt>{{ t('zones.live.relay.volume') }}</dt>
                    <dd class="live-num">{{ bytes(relayBytes) }}</dd>
                    <dt>{{ t('zones.live.relay.delay') }}</dt>
                    <dd class="live-num">
                        {{ ms(stats.lastMoveDelayMs) }}
                    </dd>
                </dl>
            </section>
            <!-- endregion -->

            <!-- region Server updates -->
            <section class="live-panel__section">
                <h3 class="live-panel__heading">
                    <span class="live-legend__swatch live-chart__seg--zone" />
                    {{ t('zones.live.zone.heading') }}
                </h3>
                <p class="live-panel__hint">
                    {{ t('zones.live.zone.hint') }}
                </p>
                <dl class="live-rows">
                    <template v-for="kind in ZONE_KINDS" :key="kind">
                        <dt>{{ t(`zones.live.zone.kinds.${kind}`) }}</dt>
                        <dd>
                            <span class="live-num live-num--strong">{{
                                format.number(stats.zone[kind].count)
                            }}</span>
                            <span class="live-sub">{{
                                ago(stats.zone[kind].lastAt)
                            }}</span>
                        </dd>
                    </template>
                    <dt>{{ t('zones.live.zone.reloads') }}</dt>
                    <dd>
                        <span class="live-num live-num--strong">{{
                            format.number(stats.reloads.count)
                        }}</span>
                        <span
                            v-if="stats.reloads.failed > 0"
                            class="live-chip live-chip--bad"
                            >{{
                                tc(
                                    'zones.live.zone.failed',
                                    stats.reloads.failed,
                                )
                            }}</span
                        >
                    </dd>
                    <dt>{{ t('zones.live.zone.reload_time') }}</dt>
                    <dd class="live-num">
                        {{ ms(stats.reloads.lastMs) }} /
                        {{ ms(reloadAverage) }}
                    </dd>
                </dl>
            </section>
            <!-- endregion -->

            <!-- region Foot -->
            <footer class="live-panel__foot">
                <span>{{
                    t('zones.live.opened', { age: ago(stats.openedAt) })
                }}</span>
                <Link :href="ColonyController.uplink.url()">{{
                    t('colony.status.inspector')
                }}</Link>
            </footer>
            <!-- endregion -->

            <PopoverArrow class="live-panel__arrow" />
        </PopoverContent>
    </PopoverRoot>
</template>

<style>
/* Not scoped: reka renders the panel in a positioning wrapper outside this component's root. Every class is prefixed `live-`. No portal: the colour tokens live on `.colony`. */
/* region Trigger */
.live-pill {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    margin-left: auto;
    padding: 5px 12px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: transparent;
    color: var(--text-2);
    font: inherit;
    font-size: 13.5px;
    cursor: pointer;
    transition:
        border-color 160ms ease-out,
        background-color 160ms ease-out,
        color 160ms ease-out;
}

.live-pill:hover,
.live-pill[data-state='open'] {
    border-color: var(--text-3);
    background: var(--ink-1);
    color: var(--text);
}

.live-pill:focus-visible {
    outline: 2px solid var(--live);
    outline-offset: 2px;
}

.live-pill--on {
    color: var(--text);
}

.live-pill__dot {
    width: 8px;
    height: 8px;
    flex: none;
    border: 1.5px solid var(--text-3);
    border-radius: 50%;
}

.live-pill--on .live-pill__dot,
.live-panel__state--ok .live-pill__dot {
    border-color: var(--live);
    background: var(--live);
    box-shadow: 0 0 0 3px oklch(78% 0.15 150 / 0.2);
}

.live-pill--on .live-pill__dot--beat {
    animation: live-beat 700ms ease-out;
}

@keyframes live-beat {
    from {
        box-shadow: 0 0 0 0 oklch(78% 0.15 150 / 0.6);
    }

    to {
        box-shadow: 0 0 0 7px oklch(78% 0.15 150 / 0);
    }
}

.live-pill__bars {
    display: inline-flex;
    align-items: flex-end;
    gap: 2px;
    height: 14px;
}

.live-pill__bar {
    width: 3px;
    border-radius: 1px;
    background: var(--live);
    opacity: 0.85;
    transition: height 300ms ease-out;
}

.live-pill__bar--empty {
    background: var(--line);
    opacity: 1;
}

.live-pill__rate {
    min-width: 4ch;
    color: var(--text-3);
    font-family: var(--font-figure);
    font-variant-numeric: tabular-nums;
}

@media (prefers-reduced-motion: reduce) {
    .live-pill--on .live-pill__dot--beat {
        animation: none;
    }
}
/* endregion */

/* region Panel */
.live-panel {
    z-index: 50;
    width: min(380px, calc(100vw - 32px));
    max-height: min(640px, calc(100vh - 96px));
    overflow-y: auto;
    padding: 14px 16px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--ink-1);
    box-shadow: 0 18px 48px oklch(10% 0.02 222 / 0.55);
    color: var(--text);
    font-size: 13.5px;
}

.live-panel__arrow {
    fill: var(--ink-1);
    stroke: var(--line);
}

.live-panel__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
}

.live-panel__state {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    font-size: 15px;
}

.live-panel__age {
    color: var(--text-3);
}

.live-panel__label {
    color: var(--text-3);
    font-size: 12.5px;
}

.live-panel__big {
    font-family: var(--font-figure);
    font-size: 20px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

.live-panel__activity {
    margin-top: 12px;
    padding: 10px 12px;
    border-radius: 9px;
    background: var(--ink-0);
}

.live-panel__activity-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
}

.live-chart {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    gap: 4px;
    height: 56px;
    margin-top: 8px;
}

.live-chart__col {
    display: flex;
    flex-direction: column-reverse;
    gap: 1px;
    border-bottom: 1px solid var(--line);
}

.live-chart__seg {
    display: block;
    min-height: 0;
    border-radius: 2px;
    transition: height 300ms ease-out;
}

.live-chart__seg--zone {
    background: oklch(72% 0.11 240);
}

.live-chart__seg--trips {
    background: var(--live);
}

.live-chart__seg--fast {
    background: var(--ration);
}

.live-legend {
    display: flex;
    gap: 14px;
    margin-top: 8px;
    color: var(--text-2);
    font-size: 12.5px;
}

.live-legend li {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.live-legend__swatch {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 2px;
}

.live-panel__section {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid var(--line);
}

.live-panel__heading {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.01em;
}

.live-panel__hint {
    margin-top: 2px;
    color: var(--text-3);
    font-size: 12.5px;
    line-height: 1.4;
}

.live-rows {
    display: grid;
    grid-template-columns: minmax(110px, auto) 1fr;
    gap: 6px 14px;
    margin-top: 8px;
}

.live-rows dt {
    color: var(--text-2);
}

.live-rows dd {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 4px 8px;
    min-width: 0;
}

.live-num {
    font-family: var(--font-figure);
    font-variant-numeric: tabular-nums;
}

.live-num--strong {
    font-size: 15px;
    font-weight: 600;
}

.live-sub {
    color: var(--text-3);
    font-size: 12.5px;
}

.live-mono {
    overflow-wrap: anywhere;
    color: var(--text-2);
    font-family: ui-monospace, monospace;
    font-size: 12px;
}

.live-chip {
    padding: 1px 8px;
    border-radius: 999px;
    font-size: 12.5px;
}

.live-chip--ok {
    background: var(--ok-bg);
    color: var(--ok);
}

.live-chip--warn {
    background: var(--warn-bg);
    color: var(--warn);
}

.live-chip--bad {
    background: var(--bad-bg);
    color: var(--bad);
}

.live-chip--idle {
    background: var(--ink-2);
    color: var(--text-2);
}

.live-panel__foot {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 6px 12px;
    margin-top: 14px;
    padding-top: 10px;
    border-top: 1px solid var(--line);
    color: var(--text-3);
    font-size: 12.5px;
}

.live-panel__foot a {
    color: var(--text-2);
    text-decoration: underline;
    text-underline-offset: 3px;
}
/* endregion */
</style>
