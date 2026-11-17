<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import GameSprite from '@/components/viability/GameSprite.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import type {
    ZoneCritterData,
    ZoneFeedingData,
    ZoneFeedingLineData,
    ZonePopulationData,
} from '@/types/App/Data/data.d';
import ZoneCritterSheet from './ZoneCritterSheet.vue';
import {
    FLOCK_METRICS,
    amountDigits,
    bandId,
    ageRange,
    defaultMetric,
    feedingChip,
    feedingFoods,
    fertilityFrozen,
    groupFlock,
    happinessCounts,
    individualAlerts,
    median,
    metricValue,
    sortByMetric,
    untickedNames,
} from './zoneFlock';
import type { FlockGroup, FlockMetric } from './zoneFlock';

// region Props

const props = defineProps<{
    critters: ZoneCritterData[];
    population: ZonePopulationData[] | null;
    /** What each band eats and how long its stock lasts (derived), by group key. */
    feeding: ZoneFeedingData[] | null;
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

// region Feeding

const feedingByKey = computed(
    () =>
        new Map(
            (props.feeding ?? []).map((feeding) => [feeding.groupKey, feeding]),
        ),
);

/** The band's feeding as a list of zero or one, for a `v-for` that names it. */
function feedingOf(group: FlockGroup): ZoneFeedingData[] {
    const feeding = feedingByKey.value.get(group.key);

    return feeding === undefined ? [] : [feeding];
}

function amount(value: number): string {
    return format.number(value, amountDigits(value));
}

/** The value as shown, so the plural agrees with it (1,96 shows as 2). */
function shown(value: number, digits: number): number {
    return Number(value.toFixed(digits));
}

/** "Épicier (12 dans la zone)", the count left out when the zone's plants were not read. */
function grazedLabel(food: ZoneFeedingLineData): string {
    return food.inZone === null
        ? food.name
        : `${food.name} (${t('zones.flock.feeding.in_zone', { count: format.number(food.inZone) })})`;
}

/** "Varech, Grès, +74 autres": the other item foods, then the count of foods not sent; null when there are none. */
function alternativesLabel(feeding: ZoneFeedingData): string | null {
    const names = feedingFoods(feeding)
        .items.slice(1)
        .map((food) => food.name);

    if (feeding.otherFoods > 0) {
        names.push(tc('zones.flock.feeding.other_foods', feeding.otherFoods));
    }

    return names.length === 0 ? null : names.join(', ');
}

/** The plants grazed and the prey hunted; alternatives to the item foods when the band also eats items. */
function grazing(
    feeding: ZoneFeedingData,
): { verb: string; names: string; alternative: boolean }[] {
    const foods = feedingFoods(feeding);
    const alternative = foods.items.length > 0;
    const prefix = alternative ? 'or_' : '';

    const parts = [
        { verb: `${prefix}grazes`, lines: foods.plants },
        { verb: `${prefix}hunts`, lines: foods.prey },
    ]
        .filter((part) => part.lines.length > 0)
        .map((part) => ({
            verb: part.verb,
            names: part.lines.map(grazedLabel).join(', '),
            alternative,
        }));
    const last = parts[parts.length - 1];

    // Without an item line, the foods not sent are counted here.
    if (!alternative && last !== undefined && feeding.otherFoods > 0) {
        last.names += `, ${tc('zones.flock.feeding.other_foods', feeding.otherFoods)}`;
    }

    return parts;
}

function producedLabel(line: ZoneFeedingLineData): string {
    return line.kind === 'egg'
        ? tc(
              'zones.flock.feeding.eggs_per_cycle',
              shown(line.perCycle, amountDigits(line.perCycle)),
              {
                  value: amount(line.perCycle),
              },
          )
        : t('zones.flock.feeding.kg_per_cycle', {
              value: amount(line.perCycle),
          });
}

function chipLabel(feeding: ZoneFeedingData): string | null {
    const chip = feedingChip(feeding);

    switch (chip?.kind) {
        case undefined:
            return null;
        case 'unknown':
            return t('zones.flock.feeding.stock_unknown');
        case 'missing':
            return t('zones.flock.feeding.missing', {
                value: amount(chip.kgPerCycle),
            });
        case 'stock':
            return tc('zones.flock.feeding.stock', shown(chip.cycles, 1), {
                value: format.number(chip.cycles, 1),
            });
    }
}

/** Unknown stock stays neutral; a missing need is always bad. */
function chipTone(feeding: ZoneFeedingData): string {
    const chip = feedingChip(feeding);

    if (chip?.kind === 'stock') {
        return `zone-chip--${chip.tone}`;
    }

    return chip?.kind === 'unknown' ? '' : 'zone-chip--bad';
}

// endregion
</script>

<template>
    <div class="zone-flock">
        <section
            v-for="group in groups"
            :id="bandId(group.key)"
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
                    <span
                        v-if="metricValue(critter, metricOf(group)) === null"
                        class="zone-mon__gauge zone-mon__gauge--unknown"
                        aria-hidden="true"
                    />
                    <span v-else class="zone-mon__gauge" aria-hidden="true"
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

            <!-- region Feeding -->
            <p
                v-for="feeding in feedingOf(group)"
                :key="feeding.groupKey"
                class="zone-band__facts zone-band__feeding"
                data-test="zone-feeding"
            >
                <span
                    v-for="food in feedingFoods(feeding).items.slice(0, 1)"
                    :key="food.resource"
                    >{{ t('zones.flock.feeding.eats') }}
                    <b>{{ food.name }}</b>
                    {{
                        t('zones.flock.feeding.kg_per_cycle', {
                            value: amount(food.perCycle),
                        })
                    }}<span
                        v-if="alternativesLabel(feeding)"
                        class="zone-band__alt"
                    >
                        ({{ t('zones.flock.feeding.or') }}
                        {{ alternativesLabel(feeding) }})</span
                    ></span
                >
                <span
                    v-for="part in grazing(feeding)"
                    :key="part.verb"
                    :class="{ 'zone-band__alt': part.alternative }"
                    >{{ t(`zones.flock.feeding.${part.verb}`) }}
                    <b>{{ part.names }}</b></span
                >
                <span v-if="feeding.produces.length > 0"
                    >{{ t('zones.flock.feeding.produces') }}
                    <template
                        v-for="(line, index) in feeding.produces"
                        :key="line.resource"
                        ><template v-if="index > 0">, </template
                        ><b>{{ line.name }}</b>
                        {{ producedLabel(line) }}</template
                    ></span
                >
                <span
                    v-if="untickedNames(feeding)"
                    :class="
                        feeding.blockedByFilter
                            ? 'zone-band__blocked'
                            : 'zone-band__alt'
                    "
                    data-test="zone-feeding-unticked"
                    ><template v-if="feeding.blockedByFilter"
                        >{{ t('zones.flock.feeding.blocked') }}.
                    </template>
                    {{ t('zones.flock.feeding.unticked') }}
                    <b>{{ untickedNames(feeding) }}</b></span
                >
                <span v-if="chipLabel(feeding)" class="zone-band__balance"
                    ><span
                        class="zone-chip"
                        :class="chipTone(feeding)"
                        data-test="zone-feeding-chip"
                        >{{ chipLabel(feeding) }}</span
                    ><small
                        v-if="feedingChip(feeding)?.kind !== 'unknown'"
                        class="zone-band__derived"
                        :title="t('zones.flock.feeding.derived_hint')"
                        >{{ t('zones.flock.feeding.derived') }}</small
                    ></span
                >
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
    background: var(--bad-bg);
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

/* An unknown value is hatched, never drawn as an empty (0) gauge. */
.zone-mon__gauge--unknown {
    background: repeating-linear-gradient(
        135deg,
        var(--ink-3) 0 3px,
        transparent 3px 6px
    );
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

/* region Feeding */
.zone-band__feeding {
    margin-top: 6px;
}

.zone-band__alt {
    color: var(--text-3);
}

.zone-band__blocked {
    color: var(--bad);
}

.zone-band__balance {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.zone-chip--ok {
    background: var(--ok-bg);
    color: var(--ok);
}

.zone-chip--warn {
    background: var(--warn-bg);
    color: var(--warn);
}

.zone-band__derived {
    padding: 1px 4px;
    border: 1px solid var(--ink-3);
    border-radius: 3px;
    color: var(--text-3);
    font-family: var(--font-figure);
    font-size: 11px;
    font-weight: 500;
}
/* endregion */
</style>
