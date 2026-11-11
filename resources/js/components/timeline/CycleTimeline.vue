<script setup lang="ts" generic="R extends TimelineRow, P">
import { computed, nextTick, useTemplateRef } from 'vue';
import {
    clampPxPerCycle,
    cycleTicks,
    cycleToPx,
    DEFAULT_PX_PER_CYCLE,
    MAX_PX_PER_CYCLE,
    mergeEvents,
    MIN_PX_PER_CYCLE,
    type TimelineEvent,
    type TimelineMark,
    type TimelineRow,
} from './timeline';

// region Props

const props = withDefaults(
    defineProps<{
        rows: R[];
        events: TimelineEvent<P>[];
        startCycle: number;
        /** Last computed cycle: the scroll area reaches it and no further. */
        endCycle: number;
        nowCycle: number;
        minGapPx?: number;
        zoomInLabel: string;
        zoomOutLabel: string;
    }>(),
    { minGapPx: 18 },
);

const pxPerCycleModel = defineModel<number>('pxPerCycle', {
    default: DEFAULT_PX_PER_CYCLE,
});

defineSlots<{
    label?(props: { row: R }): unknown;
    summary?(props: { row: R }): unknown;
    mark?(props: { mark: TimelineMark<P> }): unknown;
}>();

// endregion

// region Geometry

/** Inset at both ends of the track so marks at the edges are not clipped. */
const TRACK_INSET_PX = 24;
const ZOOM_STEP = 1.25;
const WHEEL_ZOOM_STEP = 1.1;

const scale = computed(() => clampPxPerCycle(pxPerCycleModel.value));

const trackWidth = computed(
    () =>
        Math.max(0, cycleToPx(props.endCycle, props.startCycle, scale.value)) +
        TRACK_INSET_PX * 2,
);

function left(cycle: number): number {
    return TRACK_INSET_PX + cycleToPx(cycle, props.startCycle, scale.value);
}

const ticks = computed(() =>
    cycleTicks(props.startCycle, props.endCycle, scale.value).filter(
        (cycle) => cycle >= props.startCycle && cycle <= props.endCycle,
    ),
);

const showNow = computed(
    () =>
        props.nowCycle >= props.startCycle && props.nowCycle <= props.endCycle,
);

const marksByRow = computed(() => {
    const byRow = new Map<string, TimelineMark<P>[]>();

    for (const mark of mergeEvents(props.events, scale.value, props.minGapPx)) {
        const marks = byRow.get(mark.rowId);

        if (marks) {
            marks.push(mark);
        } else {
            byRow.set(mark.rowId, [mark]);
        }
    }

    return byRow;
});

// endregion

// region Scale

const headerTrack = useTemplateRef<HTMLElement>('headerTrack');
const scroller = useTemplateRef<HTMLElement>('scroller');

const canZoomIn = computed(() => scale.value < MAX_PX_PER_CYCLE);
const canZoomOut = computed(() => scale.value > MIN_PX_PER_CYCLE);

/** Applies a new scale while keeping the cycle under `anchorClientX` in place. */
async function setScale(next: number, anchorClientX?: number): Promise<void> {
    const track = headerTrack.value;
    const container = scroller.value;
    const clamped = clampPxPerCycle(next);

    if (!track || !container || clamped === scale.value) {
        pxPerCycleModel.value = clamped;
        return;
    }

    const anchorX =
        anchorClientX ??
        container.getBoundingClientRect().left + container.clientWidth / 2;
    const anchorCycle =
        props.startCycle +
        (anchorX - track.getBoundingClientRect().left - TRACK_INSET_PX) /
            scale.value;

    pxPerCycleModel.value = clamped;
    await nextTick();

    container.scrollLeft +=
        track.getBoundingClientRect().left + left(anchorCycle) - anchorX;
}

function onWheel(event: WheelEvent): void {
    if (!event.ctrlKey || event.deltaY === 0) {
        return;
    }

    event.preventDefault();
    void setScale(
        event.deltaY < 0
            ? scale.value * WHEEL_ZOOM_STEP
            : scale.value / WHEEL_ZOOM_STEP,
        event.clientX,
    );
}

// endregion
</script>

<template>
    <div class="cycle-timeline flex flex-col gap-2">
        <!-- region Scale control -->
        <div class="flex justify-end gap-1">
            <button
                type="button"
                class="tl-zoom"
                :aria-label="zoomOutLabel"
                :title="zoomOutLabel"
                :disabled="!canZoomOut"
                @click="setScale(scale / ZOOM_STEP)"
            >
                <span aria-hidden="true">−</span>
            </button>
            <button
                type="button"
                class="tl-zoom"
                :aria-label="zoomInLabel"
                :title="zoomInLabel"
                :disabled="!canZoomIn"
                @click="setScale(scale * ZOOM_STEP)"
            >
                <span aria-hidden="true">+</span>
            </button>
        </div>
        <!-- endregion -->

        <!-- region Scroll area -->
        <div
            ref="scroller"
            class="tl-scroller overflow-x-auto overscroll-x-contain"
            tabindex="0"
            :style="{ '--tl-track-width': `${trackWidth}px` }"
            @wheel="onWheel"
        >
            <div class="w-max min-w-full">
                <!-- region Header -->
                <div class="tl-row tl-head">
                    <div class="tl-label" />
                    <div ref="headerTrack" class="tl-track h-8">
                        <div
                            v-for="cycle in ticks"
                            :key="cycle"
                            class="tl-tick-label absolute top-1.5 -translate-x-1/2 text-[13px] font-semibold whitespace-nowrap tabular-nums"
                            :style="{ left: `${left(cycle)}px` }"
                        >
                            {{ cycle }}
                        </div>
                        <div
                            v-if="showNow"
                            class="tl-now"
                            :style="{ left: `${left(nowCycle)}px` }"
                        />
                    </div>
                    <div class="tl-summary" />
                </div>
                <!-- endregion -->

                <!-- region Rows -->
                <div
                    v-for="row in rows"
                    :key="row.id"
                    class="tl-row"
                    role="group"
                    :aria-label="row.ariaLabel"
                >
                    <div class="tl-label py-2 pr-3">
                        <slot name="label" :row="row">{{ row.id }}</slot>
                    </div>
                    <div class="tl-track min-h-12">
                        <div
                            v-for="cycle in ticks"
                            :key="cycle"
                            class="tl-grid-line"
                            :style="{ left: `${left(cycle)}px` }"
                        />
                        <div
                            v-if="showNow"
                            class="tl-now"
                            :style="{ left: `${left(nowCycle)}px` }"
                        />
                        <div
                            v-for="mark in marksByRow.get(row.id) ?? []"
                            :key="`${mark.kind}:${mark.cycle}`"
                            class="absolute top-1/2 -translate-x-1/2 -translate-y-1/2"
                            :style="{ left: `${left(mark.cycle)}px` }"
                        >
                            <slot name="mark" :mark="mark">
                                <span class="tl-dot">
                                    <span
                                        v-if="mark.events.length > 1"
                                        class="tabular-nums"
                                    >
                                        {{ mark.events.length }}
                                    </span>
                                </span>
                            </slot>
                        </div>
                    </div>
                    <div class="tl-summary py-2 pl-3 text-right">
                        <slot name="summary" :row="row" />
                    </div>
                </div>
                <!-- endregion -->
            </div>
        </div>
        <!-- endregion -->
    </div>
</template>

<style scoped>
.cycle-timeline {
    --tl-label-width-resolved: var(--tl-label-width, 250px);
    color: var(--tl-text, oklch(93% 0.014 90));
    --tl-summary-width-resolved: var(--tl-summary-width, 130px);
}

.tl-scroller:focus-visible {
    outline: 2px solid var(--tl-now, oklch(82% 0.13 82));
    outline-offset: 2px;
}

.tl-row {
    display: grid;
    grid-template-columns:
        var(--tl-label-width-resolved) minmax(var(--tl-track-width), 1fr)
        var(--tl-summary-width-resolved);
    align-items: stretch;
    border-bottom: 1px solid var(--tl-line, oklch(36% 0.03 222));
}

.tl-label,
.tl-summary {
    position: sticky;
    z-index: 2;
    display: flex;
    flex-direction: column;
    justify-content: center;
    background: var(--tl-surface, oklch(16% 0.022 222));
}

.tl-label {
    left: 0;
}

.tl-summary {
    right: 0;
}

.tl-track {
    position: relative;
}

.tl-tick-label {
    color: var(--tl-muted, oklch(62% 0.022 210));
}

.tl-grid-line {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 1px;
    background: var(--tl-line, oklch(36% 0.03 222));
    opacity: 0.5;
}

.tl-now {
    position: absolute;
    top: 0;
    bottom: 0;
    z-index: 1;
    width: 2px;
    margin-left: -1px;
    background: var(--tl-now, oklch(82% 0.13 82));
    pointer-events: none;
}

.tl-dot {
    display: grid;
    place-items: center;
    min-width: 14px;
    height: 14px;
    padding: 0 3px;
    border: 1.5px solid var(--tl-now, oklch(82% 0.13 82));
    border-radius: 999px;
    background: var(--tl-surface, oklch(16% 0.022 222));
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
}

.tl-dot:has(span) {
    min-width: 22px;
    height: 22px;
}

.tl-zoom {
    display: grid;
    place-items: center;
    width: 28px;
    height: 28px;
    border: 1px solid var(--tl-line, oklch(36% 0.03 222));
    border-radius: 6px;
    background: var(--tl-surface, oklch(16% 0.022 222));
    font-size: 16px;
    font-weight: 600;
    line-height: 1;
    cursor: pointer;
}

.tl-zoom:disabled {
    color: var(--tl-muted, oklch(62% 0.022 210));
    cursor: default;
    opacity: 0.6;
}

.tl-zoom:focus-visible {
    outline: 2px solid var(--tl-now, oklch(82% 0.13 82));
    outline-offset: 2px;
}
</style>
