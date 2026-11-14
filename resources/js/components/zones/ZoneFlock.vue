<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import GameSprite from '@/components/viability/GameSprite.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import type {
    ZoneCritterData,
    ZonePopulationData,
} from '@/types/App/Data/data.d';
import ZoneCritterSheet from './ZoneCritterSheet.vue';
import {
    FLOCK_METRICS,
    ageRange,
    defaultMetric,
    fertilityFrozen,
    groupFlock,
    happinessCounts,
    individualAlerts,
    median,
    metricValue,
    sortByMetric,
} from './zoneFlock';
import type { FlockGroup, FlockMetric } from './zoneFlock';

// region Props

const props = defineProps<{
    critters: ZoneCritterData[];
    population: ZonePopulationData[] | null;
    selectedId: string | null;
}>();

const emit = defineEmits<{
    select: [instanceId: string];
}>();

// endregion

// region Bands

const { t, tc } = useT();
const format = useFormat();

const groups = computed(() => groupFlock(props.critters));

/** The gauge chosen on each band, by group key; it also sorts the band. */
const metrics = reactive<Record<string, FlockMetric>>({});

function metricOf(group: FlockGroup): FlockMetric {
    return metrics[group.key] ?? defaultMetric(group.stage);
}

function sorted(group: FlockGroup): ZoneCritterData[] {
    return sortByMetric(group.critters, metricOf(group));
}

function frozen(critter: ZoneCritterData): boolean {
    return fertilityFrozen(critter, props.population);
}

function gaugeTone(critter: ZoneCritterData, metric: FlockMetric): string {
    if (metric === 'fertility' && frozen(critter)) {
        return 'zone-mon__gauge--warn';
    }

    return metric === 'stomach' && critter.hungry ? 'zone-mon__gauge--bad' : '';
}

function vignetteLabel(group: FlockGroup, critter: ZoneCritterData): string {
    const alerts = individualAlerts(group, critter);
    const label = t('zones.flock.critter_label', {
        name: critter.name,
        position: `w(${Math.floor(critter.x)}, ${Math.floor(critter.y)})`,
    });

    return alerts.length === 0
        ? label
        : `${label}, ${t('zones.flock.alerts_label', { statuses: format.list(alerts) })}`;
}

// endregion

// region Sheet

const hoveredId = ref<string | null>(null);

/** The hovered critter of the band, else its selected one: the band shows its sheet. */
function active(group: FlockGroup): ZoneCritterData | null {
    return (
        group.critters.find(
            (critter) => critter.instanceId === hoveredId.value,
        ) ??
        group.critters.find(
            (critter) => critter.instanceId === props.selectedId,
        ) ??
        null
    );
}

function leave(critter: ZoneCritterData): void {
    if (hoveredId.value === critter.instanceId) {
        hoveredId.value = null;
    }
}

// endregion

// region Facts

function stomachMedian(group: FlockGroup): number | null {
    return median(group.critters.map((critter) => critter.caloriesRatio));
}

// endregion
</script>

<template>
    <div class="zone-flock">
        <section
            v-for="group in groups"
            :key="group.key"
            class="zone-band"
            :aria-label="`${group.critters.length} ${group.name}, ${t(`zones.flock.stage.${group.stage}`)}`"
        >
            <!-- region Band head -->
            <div class="zone-band__head">
                <GameSprite :src="group.sprite" kind="critter" :size="36" />
                <span class="zone-band__count">{{
                    group.critters.length
                }}</span>
                <span class="zone-band__name"
                    >{{ group.name }}
                    <span class="zone-band__stage">{{
                        t(`zones.flock.stage.${group.stage}`)
                    }}</span></span
                >
                <span
                    v-for="alert in group.groupAlerts"
                    :key="alert.name"
                    class="zone-chip zone-chip--bad"
                    >{{ alert.name }}<b>{{ alert.count }}</b></span
                >
                <label class="zone-band__metric">
                    {{ t('zones.flock.metric_label') }}
                    <select
                        :value="metricOf(group)"
                        @change="
                            metrics[group.key] = (
                                $event.target as HTMLSelectElement
                            ).value as FlockMetric
                        "
                    >
                        <option
                            v-for="metric in FLOCK_METRICS"
                            :key="metric"
                            :value="metric"
                        >
                            {{ t(`zones.flock.metric.${metric}`) }}
                        </option>
                    </select>
                </label>
            </div>
            <!-- endregion -->

            <!-- region Vignettes -->
            <div class="zone-band__flock">
                <button
                    v-for="critter in sorted(group)"
                    :key="critter.instanceId"
                    type="button"
                    class="zone-mon"
                    :class="{
                        'zone-mon--flip': critter.facing === 'right',
                        'zone-mon--alert':
                            individualAlerts(group, critter).length > 0,
                        'zone-mon--selected': critter.instanceId === selectedId,
                    }"
                    :aria-label="vignetteLabel(group, critter)"
                    :aria-pressed="critter.instanceId === selectedId"
                    @mouseenter="hoveredId = critter.instanceId"
                    @mouseleave="leave(critter)"
                    @focus="hoveredId = critter.instanceId"
                    @blur="leave(critter)"
                    @click="emit('select', critter.instanceId)"
                >
                    <img
                        v-if="critter.sprite"
                        :src="critter.sprite"
                        alt=""
                        class="zone-mon__image"
                        loading="lazy"
                    />
                    <span class="zone-mon__gauge" aria-hidden="true"
                        ><i
                            :class="gaugeTone(critter, metricOf(group))"
                            :style="{
                                width: `${(metricValue(critter, metricOf(group)) ?? 0) * 100}%`,
                            }"
                    /></span>
                </button>
            </div>
            <!-- endregion -->

            <!-- region Facts -->
            <p class="zone-band__facts">
                <span v-if="happinessCounts(group.critters).length > 0"
                    >{{ t('zones.flock.mood') }}
                    <template
                        v-for="(entry, index) in happinessCounts(
                            group.critters,
                        )"
                        :key="entry.state"
                        ><template v-if="index > 0"> · </template
                        ><b>{{ format.number(entry.count) }}</b>
                        {{
                            tc(
                                `zones.flock.happiness.${entry.state}`,
                                entry.count,
                            )
                        }}</template
                    ></span
                >
                <span v-if="stomachMedian(group) !== null"
                    >{{ t('zones.flock.stomach_median') }}
                    <b
                        >{{ format.number(stomachMedian(group)! * 100) }} %</b
                    ></span
                >
                <span v-if="ageRange(group.critters)">{{
                    t('zones.flock.age_range', {
                        min: format.number(ageRange(group.critters)!.min),
                        max: format.number(ageRange(group.critters)!.max),
                    })
                }}</span>
            </p>
            <!-- endregion -->

            <ZoneCritterSheet
                v-if="active(group)"
                :critter="active(group)!"
                :frozen="frozen(active(group)!)"
                class="zone-band__sheet"
            />
        </section>
    </div>
</template>

<style scoped>
/* region Band */
.zone-band {
    padding: 16px 0 18px;
    border-top: 1px solid var(--ink-2);
}

.zone-band:first-child {
    padding-top: 4px;
    border-top: 0;
}

.zone-band__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 14px;
    margin-bottom: 12px;
}

.zone-band__count {
    font: 700 24px/1 var(--font-figure);
}

.zone-band__name {
    font-size: 16px;
    font-weight: 600;
}

.zone-band__stage {
    color: var(--text-3);
    font-size: 14px;
    font-weight: 400;
}

.zone-chip {
    padding: 2px 8px;
    border-radius: 99px;
    background: var(--ink-2);
    color: var(--text-2);
    font-size: 12px;
    white-space: nowrap;
}

.zone-chip b {
    margin-left: 3px;
    font-weight: 700;
}

.zone-chip--bad {
    background: oklch(28% 0.07 25);
    color: var(--bad);
}

.zone-chip--bad::before {
    font-weight: 700;
    content: '! ';
}

.zone-band__metric {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-left: auto;
    color: var(--text-3);
    font-size: 12px;
}

.zone-band__metric select {
    padding: 2px 6px;
    border: 1px solid var(--ink-3);
    border-radius: 6px;
    background: var(--ink-1);
    color: var(--text-2);
    font-size: 12px;
    font-weight: 600;
}
/* endregion */

/* region Vignettes */
.zone-band__flock {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.zone-mon {
    position: relative;
    display: grid;
    width: 52px;
    height: 58px;
    place-items: center;
    border-radius: 8px;
    background: var(--ink-1);
    cursor: pointer;
}

.zone-mon:hover {
    background: var(--ink-2);
}

.zone-mon:focus-visible {
    outline: 2px solid var(--color-oni-amber);
    outline-offset: 2px;
}

.zone-mon__image {
    width: 34px;
    height: 34px;
    margin-top: -8px;
    object-fit: contain;
}

.zone-mon--flip .zone-mon__image {
    transform: scaleX(-1);
}

.zone-mon__gauge {
    position: absolute;
    right: 6px;
    bottom: 6px;
    left: 6px;
    height: 4px;
    overflow: hidden;
    border-radius: 2px;
    background: var(--ink-3);
}

.zone-mon__gauge i {
    display: block;
    height: 100%;
    background: var(--ok);
}

.zone-mon__gauge .zone-mon__gauge--warn {
    background: var(--warn);
}

.zone-mon__gauge .zone-mon__gauge--bad {
    background: var(--bad);
}

.zone-mon--alert {
    background: oklch(20% 0.04 25);
    box-shadow: inset 0 0 0 1.5px var(--bad);
}

.zone-mon--alert::after {
    position: absolute;
    top: 3px;
    right: 6px;
    color: var(--bad);
    font: 700 12px/1 var(--font-figure);
    content: '!';
}

.zone-mon--selected {
    box-shadow: 0 0 0 2px var(--text);
}

.zone-mon--alert.zone-mon--selected {
    box-shadow:
        inset 0 0 0 1.5px var(--bad),
        0 0 0 2px var(--text);
}
/* endregion */

/* region Facts */
.zone-band__facts {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 22px;
    margin-top: 12px;
    color: var(--text-2);
    font-size: 13px;
}

.zone-band__facts b {
    color: var(--text);
    font-weight: 600;
}

.zone-band__sheet {
    margin-top: 12px;
}
/* endregion */
</style>
