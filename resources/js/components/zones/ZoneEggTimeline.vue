<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import type { EggGroup } from './zoneFlock';
import { eggTimeline } from './zoneFlock';

// region Props

const props = defineProps<{
    group: EggGroup;
}>();

// endregion

// region Timeline

const { t, tc } = useT();
const format = useFormat();

const first = computed(() => props.group.eggs[0]);
const timeline = computed(() => eggTimeline(props.group.eggs));
const dropping = computed(
    () =>
        props.group.eggs.filter(
            (egg) => egg.viability !== null && egg.viability < 100,
        ).length,
);

function offset(cycles: number): string {
    return `${(cycles / timeline.value.maxCycles) * 100}%`;
}

// endregion
</script>

<template>
    <section class="zone-eggs">
        <!-- region Head -->
        <div class="zone-eggs__head">
            <img
                v-if="first.sprite"
                :src="first.sprite"
                alt=""
                class="zone-eggs__sprite"
            />
            <span class="zone-eggs__count">{{ group.eggs.length }}</span>
            <span class="zone-eggs__name"
                >{{ first.name }}
                <span class="zone-eggs__where">{{
                    t(`zones.eggs.location.${group.location}`)
                }}</span></span
            >
            <span v-if="dropping > 0" class="zone-eggs__chip">{{
                t('zones.eggs.viability_dropping', { count: dropping })
            }}</span>
        </div>
        <!-- endregion -->

        <!-- region Axis -->
        <template v-if="timeline.placed.length > 0">
            <ol class="zone-eggs__line">
                <li
                    v-for="item in timeline.placed"
                    :key="item.egg.instanceId"
                    class="zone-eggs__egg"
                    :class="{
                        'zone-eggs__egg--dropping':
                            item.egg.viability !== null &&
                            item.egg.viability < 100,
                    }"
                    :style="{ left: offset(item.cycles) }"
                    :title="
                        t('zones.eggs.egg_label', {
                            cycles: format.number(item.cycles, 1),
                            viability: format.number(item.egg.viability ?? 0),
                        })
                    "
                >
                    <img v-if="item.egg.sprite" :src="item.egg.sprite" alt="" />
                    <small>{{
                        t('zones.eggs.hatch_in', {
                            cycles: format.number(item.cycles, 1),
                        })
                    }}</small>
                </li>
            </ol>
            <p class="zone-eggs__ticks">
                <span>{{ t('zones.eggs.now') }}</span>
                <span
                    >{{ tc('zones.eggs.horizon', timeline.maxCycles) }}
                    <small
                        class="zone-eggs__derived"
                        :title="t('zones.eggs.derived_hint')"
                        >{{ t('zones.population.derived') }}</small
                    ></span
                >
            </p>
        </template>
        <p v-if="timeline.unplaced.length > 0" class="zone-eggs__unplaced">
            {{ t('zones.eggs.unplaced', { count: timeline.unplaced.length }) }}
        </p>
        <!-- endregion -->
    </section>
</template>

<style scoped>
.zone-eggs {
    padding: 16px 0 18px;
    border-top: 1px solid var(--ink-2);
}

/* region Head */
.zone-eggs__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 14px;
    margin-bottom: 12px;
}

.zone-eggs__sprite {
    width: 30px;
    height: 30px;
    object-fit: contain;
}

.zone-eggs__count {
    font: 700 24px/1 var(--font-figure);
}

.zone-eggs__name {
    font-size: 16px;
    font-weight: 600;
}

.zone-eggs__where {
    color: var(--text-3);
    font-size: 14px;
    font-weight: 400;
}

.zone-eggs__chip {
    padding: 2px 8px;
    border-radius: 99px;
    background: var(--ink-2);
    color: var(--warn);
    font-size: 12px;
    white-space: nowrap;
}
/* endregion */

/* region Axis */
.zone-eggs__line {
    position: relative;
    height: 54px;
    margin: 4px 14px 0;
}

.zone-eggs__line::before {
    position: absolute;
    top: 34px;
    right: -14px;
    left: -14px;
    height: 2px;
    background: var(--ink-3);
    content: '';
}

.zone-eggs__egg {
    position: absolute;
    top: 4px;
    text-align: center;
    transform: translateX(-50%);
}

.zone-eggs__egg img {
    display: block;
    width: 26px;
    height: 26px;
    margin: 0 auto;
    object-fit: contain;
}

.zone-eggs__egg small {
    display: block;
    margin-top: 6px;
    color: var(--text-3);
    font-size: 11px;
    white-space: nowrap;
}

.zone-eggs__egg--dropping small {
    color: var(--warn);
}

.zone-eggs__ticks {
    display: flex;
    justify-content: space-between;
    color: var(--text-3);
    font-size: 11px;
}

.zone-eggs__derived {
    margin-left: 4px;
    padding: 1px 4px;
    border: 1px solid var(--ink-3);
    border-radius: 3px;
    font-family: var(--font-figure);
    cursor: help;
}

.zone-eggs__unplaced {
    margin-top: 6px;
    color: var(--text-3);
    font-size: 12px;
}
/* endregion */
</style>
