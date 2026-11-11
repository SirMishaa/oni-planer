<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import type { ViabilityScenariosData } from '@/types/App/Data/Viability/data.d';

// region Props

const props = defineProps<{
    scenarios: ViabilityScenariosData;
    nowCycle: number;
}>();

// endregion

// region Geometry

/** Points per scenario: now, then the end of each of the next 20 cycles. */
const POINTS = 21;
const HEIGHT = 230;
const TOP = 14;
const BOTTOM = 28;
const LEFT = 46;
/** Below this width, line labels wrap on two lines to leave room to the plot. */
const COMPACT_WIDTH = 520;
const LABEL_LINE_HEIGHT = 15;
/** Rough advance of a label character (13.5px semi-condensed bold). */
const LABEL_CHAR_PX = 7.2;

const { t } = useT();
const format = useFormat();

const root = useTemplateRef<HTMLElement>('root');
const width = ref(700);
let observer: ResizeObserver | undefined;

onMounted(() => {
    if (!root.value) {
        return;
    }

    observer = new ResizeObserver(([entry]) => {
        if (entry) {
            width.value = Math.max(280, Math.round(entry.contentRect.width));
        }
    });
    observer.observe(root.value);
});

onBeforeUnmount(() => observer?.disconnect());

const compact = computed(() => width.value < COMPACT_WIDTH);
/** Right margin reserved for the line labels, from their longest line. */
const right = computed(() => {
    const longest = Math.max(
        1,
        ...labelLines.value.flatMap((lines) =>
            lines.map((line) => line.length),
        ),
    );

    return Math.ceil(longest * LABEL_CHAR_PX + 16);
});
const plotRight = computed(() => width.value - right.value);
const startCycle = computed(() => Math.floor(props.nowCycle));

function x(index: number): number {
    return LEFT + ((plotRight.value - LEFT) * index) / (POINTS - 1);
}

function y(value: number): number {
    return TOP + (HEIGHT - TOP - BOTTOM) * (1 - value / yMax.value);
}

// endregion

// region Series

type ScenarioKey = keyof ViabilityScenariosData;

const STYLES: Record<
    ScenarioKey,
    { color: string; dash: string; width: number }
> = {
    none: { color: 'var(--bad)', dash: '2 4', width: 2 },
    automatic: { color: 'var(--ration)', dash: '', width: 2.5 },
    all: { color: 'var(--wild)', dash: '7 5', width: 2 },
};

/** Drawn back to front: the automatic scenario (the verdict) stays on top. */
const ORDER: ScenarioKey[] = ['all', 'none', 'automatic'];

/**
 * The stock someone may eat, from now up to the end of the chart or to the
 * shortage: the series is null past it, so the line stops there instead of
 * falling to zero while food nobody may eat is left.
 */
const valuesByKey = computed(() =>
    ORDER.map((key) => {
        const visible = props.scenarios[key].stockByCycle.slice(0, POINTS);
        const end = visible.indexOf(null);
        const values = (end === -1 ? visible : visible.slice(0, end)).map(
            (value) => Math.max(0, value ?? 0),
        );

        const autonomy = props.scenarios[key].autonomy;

        /** The shortage is the last point drawn, inside the chart. */
        return {
            key,
            values,
            short: autonomy !== null && autonomy + 1 < POINTS,
        };
    }),
);

const series = computed(() =>
    valuesByKey.value.map(({ key, values, short }) => {
        const label = t(`colony.viability.chart.${key}`);

        return {
            key,
            label,
            values,
            short,
            ...STYLES[key],
            path: values
                .map(
                    (value, index) =>
                        `${index ? 'L' : 'M'}${x(index).toFixed(1)} ${y(value).toFixed(1)}`,
                )
                .join(' '),
            summary: t(
                short
                    ? 'colony.viability.chart.summary_short'
                    : 'colony.viability.chart.summary',
                {
                    label,
                    start: format.number(values[0] ?? 0),
                    from: startCycle.value,
                    end: format.number(values.at(-1) ?? 0),
                    to: startCycle.value + values.length - 1,
                },
            ),
        };
    }),
);

/** End of each line that stops on a shortage within the chart. */
const shortagePoints = computed(() =>
    series.value
        .filter((line) => line.short && line.values.length > 0)
        .map((line) => {
            const index = line.values.length - 1;

            return {
                key: line.key,
                x: x(index),
                y: y(line.values[index] ?? 0),
            };
        }),
);

// endregion

// region Axes

/** A round step (1, 2, 2.5 or 5 x 10^n) giving about four bands. */
function niceStep(raw: number): number {
    const magnitude = 10 ** Math.floor(Math.log10(raw));

    return (
        ([1, 2, 2.5, 5, 10].find((factor) => factor * magnitude >= raw) ?? 10) *
        magnitude
    );
}

const maxValue = computed(() =>
    Math.max(1, ...valuesByKey.value.flatMap((line) => line.values)),
);

const step = computed(() => niceStep(maxValue.value / 4));

const yMax = computed(
    () => Math.ceil(maxValue.value / step.value) * step.value,
);

const yTicks = computed(() =>
    Array.from(
        { length: Math.round(yMax.value / step.value) + 1 },
        (_, index) => index * step.value,
    ),
);

const xTicks = computed(() =>
    [0, 5, 10, 15, 20].map((index) => ({
        index,
        text:
            index === 0
                ? t('colony.viability.chart.start', { cycle: startCycle.value })
                : String(startCycle.value + index),
    })),
);

// endregion

// region Labels

/** Splits a label in two at the space nearest its middle. */
function wrap(text: string): string[] {
    const spaces = [...text.matchAll(/ /g)].map((match) => match.index);

    if (spaces.length === 0) {
        return [text];
    }

    const cut = spaces.reduce((best, index) =>
        Math.abs(index - text.length / 2) < Math.abs(best - text.length / 2)
            ? index
            : best,
    );

    return [text.slice(0, cut), text.slice(cut + 1)];
}

/** Label lines per scenario, in `ORDER`: one line, or two on narrow charts. */
const labelLines = computed(() =>
    ORDER.map((key) => {
        const text = t(`colony.viability.chart.${key}`);

        return compact.value ? wrap(text) : [text];
    }),
);

/** Line-end labels in the right margin, pushed apart so they never overlap. */
const labels = computed(() => {
    const placed = series.value
        .map((line, index) => {
            const lines = labelLines.value[index] ?? [line.label];

            return {
                key: line.key,
                lines,
                color: line.color,
                y:
                    y(line.values.at(-1) ?? 0) +
                    4 -
                    ((lines.length - 1) * LABEL_LINE_HEIGHT) / 2,
            };
        })
        .sort((a, b) => a.y - b.y);
    const floor = HEIGHT - BOTTOM - 4;

    for (let index = 1; index < placed.length; index++) {
        const previous = placed[index - 1];
        const current = placed[index];
        const minimum = previous
            ? previous.y + previous.lines.length * LABEL_LINE_HEIGHT + 2
            : 0;

        if (current && current.y < minimum) {
            current.y = minimum;
        }
    }

    const last = placed.at(-1);
    const overflow = last
        ? last.y + (last.lines.length - 1) * LABEL_LINE_HEIGHT - floor
        : 0;

    if (overflow > 0) {
        placed.forEach((label) => {
            label.y -= overflow;
        });
    }

    return placed;
});

// endregion
</script>

<template>
    <div ref="root" class="projection min-w-0">
        <svg
            :viewBox="`0 0 ${width} ${HEIGHT}`"
            :width="width"
            :height="HEIGHT"
            role="group"
            :aria-label="
                t('colony.viability.chart.label', { count: POINTS - 1 })
            "
        >
            <!-- region Grid and axes -->
            <g aria-hidden="true">
                <template v-for="tick in yTicks" :key="`y${tick}`">
                    <line
                        :x1="LEFT"
                        :x2="plotRight"
                        :y1="y(tick)"
                        :y2="y(tick)"
                        :class="
                            tick === 0 ? 'projection__zero' : 'projection__grid'
                        "
                    />
                    <text
                        :x="LEFT - 8"
                        :y="y(tick) + 4"
                        text-anchor="end"
                        class="projection__axis"
                    >
                        {{
                            tick === 0 ? format.number(0) : format.compact(tick)
                        }}
                    </text>
                </template>
                <text
                    v-for="tick in xTicks"
                    :key="`x${tick.index}`"
                    :x="x(tick.index)"
                    :y="HEIGHT - 8"
                    :text-anchor="tick.index === 0 ? 'start' : 'middle'"
                    class="projection__axis"
                >
                    {{ tick.text }}
                </text>
            </g>
            <!-- endregion -->

            <!-- region Lines -->
            <g
                v-for="line in series"
                :key="line.key"
                role="img"
                :aria-label="line.summary"
            >
                <path
                    :d="line.path"
                    fill="none"
                    :stroke="line.color"
                    :stroke-width="line.width"
                    :stroke-dasharray="line.dash || undefined"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                />
                <circle
                    v-for="(value, index) in line.values"
                    :key="index"
                    :cx="x(index)"
                    :cy="y(value)"
                    r="7"
                    class="projection__hit"
                >
                    <title>
                        {{
                            t('colony.viability.chart.point', {
                                label: line.label,
                                cycle: startCycle + index,
                                kcal: format.number(value),
                            })
                        }}
                    </title>
                </circle>
            </g>
            <circle
                v-for="point in shortagePoints"
                :key="`short-${point.key}`"
                :cx="point.x"
                :cy="point.y"
                r="4"
                fill="var(--bad)"
                aria-hidden="true"
            />
            <!-- endregion -->

            <!-- region Line labels -->
            <g aria-hidden="true">
                <text
                    v-for="label in labels"
                    :key="label.key"
                    :x="plotRight + 8"
                    :y="label.y"
                    :fill="label.color"
                    class="projection__label"
                >
                    <tspan
                        v-for="(line, index) in label.lines"
                        :key="index"
                        :x="plotRight + 8"
                        :dy="index === 0 ? 0 : LABEL_LINE_HEIGHT"
                    >
                        {{ line }}
                    </tspan>
                </text>
            </g>
            <!-- endregion -->
        </svg>
    </div>
</template>

<style scoped>
.projection svg {
    display: block;
    max-width: 100%;
    height: auto;
    overflow: visible;
}

.projection__grid {
    stroke: var(--ink-3);
}

.projection__zero {
    stroke: var(--line);
    stroke-width: 1.5;
}

.projection__axis {
    fill: var(--text-3);
    font-family: var(--font-figure);
    font-size: 12px;
    font-weight: 600;
}

.projection__label {
    font-family: var(--font-figure);
    font-size: 13.5px;
    font-weight: 700;
    paint-order: stroke;
    stroke: var(--ink-0);
    stroke-width: 4px;
    stroke-linejoin: round;
}

.projection__hit {
    fill: transparent;
}
</style>
