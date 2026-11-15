<script setup lang="ts">
import { useT } from '@/composables/useT';
import type { ZonePopulationData } from '@/types/App/Data/data.d';
import { populationGauge } from './zonePopulation';

// region Props

const props = defineProps<{
    /** Null until the game sent the critters (unknown, never "none"). */
    population: ZonePopulationData[] | null;
}>();

// endregion

// region Rows

const { t, tc } = useT();

/** `-toRemove` of one species of a region, 0 when nothing is to be removed. */
function removal(region: ZonePopulationData, prefabId: string) {
    return region.perType.find((type) => type.prefabId === prefabId) ?? null;
}

// endregion
</script>

<template>
    <section class="zone-pop">
        <h2 class="zone-pop__heading">{{ t('zones.population.title') }}</h2>

        <!-- region Empty states -->
        <p v-if="props.population === null" class="zone-pop__muted">
            {{ t('zones.population.unknown') }}
        </p>
        <p v-else-if="props.population.length === 0" class="zone-pop__muted">
            {{ t('zones.population.none') }}
        </p>
        <!-- endregion -->

        <!-- region Regions -->
        <div
            v-for="region in props.population ?? []"
            :key="region.regionId"
            class="zone-pop__region"
        >
            <div class="zone-pop__head">
                <span class="zone-pop__kind">{{
                    t(`zones.population.kind.${region.kind}`)
                }}</span>
                <span>{{ tc('zones.index.cells', region.cellCount) }}</span>
            </div>

            <ul v-if="region.states.length > 0" class="zone-pop__states">
                <li
                    v-for="state in region.states"
                    :key="state.id"
                    class="zone-pop__state"
                    :class="`zone-pop__state--${state.id}`"
                >
                    {{ state.name }}
                </li>
            </ul>

            <div
                class="zone-pop__gauge"
                role="img"
                :aria-label="`${region.occupied} + ${region.eggOccupied} / ${region.cellCount}`"
            >
                <i
                    class="zone-pop__critters"
                    :style="{
                        width: `${populationGauge(region).critters}%`,
                    }"
                />
                <i
                    class="zone-pop__eggs"
                    :style="{ width: `${populationGauge(region).eggs}%` }"
                />
                <span
                    class="zone-pop__limit"
                    :style="{ left: `${populationGauge(region).limit}%` }"
                />
            </div>
            <p class="zone-pop__legend">
                <span
                    ><i class="zone-pop__swatch zone-pop__swatch--critters" />{{
                        t('zones.population.critters', {
                            count: region.occupied,
                        })
                    }}</span
                >
                <span
                    ><i class="zone-pop__swatch zone-pop__swatch--eggs" />{{
                        t('zones.population.eggs', {
                            count: region.eggOccupied,
                        })
                    }}</span
                >
                <span>{{
                    t('zones.population.limit', { count: region.cellCount })
                }}</span>
            </p>

            <ul class="zone-pop__types">
                <li
                    v-for="type in region.types"
                    :key="type.prefabId"
                    class="zone-pop__type"
                >
                    <span
                        >{{ type.name }} × {{ type.count }}
                        <small v-if="type.personalSpace !== null"
                            >·
                            {{
                                t('zones.population.each', {
                                    count: type.personalSpace,
                                })
                            }}</small
                        ></span
                    >
                    <span
                        v-if="
                            removal(region, type.prefabId) !== null &&
                            removal(region, type.prefabId)!.toRemove > 0
                        "
                        class="zone-pop__remove"
                    >
                        −{{ removal(region, type.prefabId)!.toRemove }}
                        <small
                            v-if="removal(region, type.prefabId)!.derived"
                            class="zone-pop__derived"
                            :title="t('zones.population.derived_hint')"
                            >{{ t('zones.population.derived') }}</small
                        >
                    </span>
                </li>
            </ul>
        </div>
        <!-- endregion -->
    </section>
</template>

<style scoped>
.zone-pop {
    display: grid;
    gap: 10px;
}

.zone-pop__heading {
    color: var(--text-3);
    font-size: 11.5px;
    font-weight: 600;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}

.zone-pop__muted {
    color: var(--text-3);
    font-size: 13px;
}

.zone-pop__region {
    display: grid;
    gap: 6px;
    padding: 10px 12px;
    border-radius: 8px;
    background: var(--ink-1);
}

.zone-pop__head {
    display: flex;
    justify-content: space-between;
    gap: 10px;
}

.zone-pop__kind {
    color: var(--text-2);
}

.zone-pop__states {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.zone-pop__state {
    padding: 1px 8px;
    border-radius: 99px;
    background: oklch(28% 0.07 62);
    color: var(--warn);
    font-size: 12px;
}

.zone-pop__state--confined,
.zone-pop__state--overcrowded {
    background: oklch(28% 0.07 25);
    color: var(--bad);
}

.zone-pop__gauge {
    position: relative;
    display: flex;
    height: 16px;
    margin-top: 2px;
    overflow: hidden;
    border-radius: 4px;
    background: var(--ink-3);
}

.zone-pop__gauge > i {
    display: block;
    height: 100%;
}

.zone-pop__critters,
.zone-pop__swatch--critters {
    background: var(--wild);
}

.zone-pop__eggs,
.zone-pop__swatch--eggs {
    background: repeating-linear-gradient(
        45deg,
        var(--warn) 0 4px,
        oklch(55% 0.1 62) 4px 8px
    );
}

.zone-pop__limit {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 2px;
    margin-left: -2px;
    background: var(--text);
}

.zone-pop__legend {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 12px;
    color: var(--text-3);
    font-size: 12px;
}

.zone-pop__swatch {
    display: inline-block;
    width: 9px;
    height: 9px;
    margin-right: 5px;
    border-radius: 2px;
    vertical-align: -1px;
}

.zone-pop__types {
    display: grid;
    font-size: 13px;
}

.zone-pop__type {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 6px 0;
    border-top: 1px solid var(--ink-2);
}

.zone-pop__type small {
    color: var(--text-3);
}

.zone-pop__remove {
    color: var(--warn);
    font-weight: 600;
    white-space: nowrap;
}

.zone-pop__remove .zone-pop__derived {
    margin-left: 4px;
    padding: 1px 4px;
    border: 1px solid var(--ink-3);
    border-radius: 3px;
    color: var(--text-3);
    font-family: var(--font-figure);
    font-size: 11px;
    font-weight: 500;
}
</style>
