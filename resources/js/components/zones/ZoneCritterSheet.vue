<script setup lang="ts">
import { computed } from 'vue';
import GameSprite from '@/components/viability/GameSprite.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import { useZoneText } from '@/composables/useZoneText';
import type { ZoneCritterData } from '@/types/App/Data/data.d';
import { eggOdds, flockStage } from './zoneFlock';

// region Props

const props = defineProps<{
    critter: ZoneCritterData;
    /** Its region is cramped or confined: the fertility no longer counts. */
    frozen: boolean;
}>();

// endregion

// region Rows

const { t } = useT();
const text = useZoneText();
const format = useFormat();

const stage = computed(() => flockStage(props.critter));
const odds = computed(() => eggOdds(props.critter));

const MOOD_TONES: Record<string, 'ok' | 'warn' | 'bad'> = {
    happy: 'ok',
    glum: 'warn',
    miserable: 'bad',
};

const moodTone = computed(
    () => MOOD_TONES[props.critter.happinessState ?? ''] ?? null,
);

const mood = computed(() => {
    if (props.critter.happinessState === null) {
        return null;
    }

    const value = props.critter.happiness;
    const signed =
        value === null ? '' : ` ${value > 0 ? '+' : ''}${format.number(value)}`;

    return `${t(`zones.critter.mood.${props.critter.happinessState}`)}${signed}`;
});

// endregion
</script>

<template>
    <div class="zone-sheet">
        <!-- region Who -->
        <div class="zone-sheet__who">
            <GameSprite :src="critter.sprite" kind="critter" :size="40" />
            <div>
                <h4 class="zone-sheet__name">{{ critter.name }}</h4>
                <small class="zone-sheet__muted"
                    >w({{ Math.floor(critter.x) }},
                    {{ Math.floor(critter.y) }})</small
                >
            </div>
        </div>

        <ul class="zone-sheet__pills">
            <li
                class="zone-pill"
                :class="{ 'zone-pill--ok': stage === 'adult' }"
            >
                {{
                    t(
                        stage === 'baby'
                            ? 'zones.critter.baby'
                            : critter.tame
                              ? 'zones.critter.tame'
                              : 'zones.critter.wild',
                    )
                }}
            </li>
            <li
                v-if="mood"
                class="zone-pill"
                :class="moodTone === null ? '' : `zone-pill--${moodTone}`"
            >
                {{ mood }}
            </li>
            <li
                v-for="status in critter.statuses"
                :key="status.id"
                class="zone-pill"
                :class="{ 'zone-pill--bad': status.bad }"
            >
                {{ status.name }}
            </li>
        </ul>
        <!-- endregion -->

        <!-- region Gauges -->
        <div class="zone-sheet__row">
            <span class="zone-sheet__key">{{
                t('zones.critter.fertility')
            }}</span>
            <span v-if="critter.fertilityRatio !== null">
                <span class="zone-sheet__bar"
                    ><i
                        :class="{ 'zone-sheet__bar--frozen': frozen }"
                        :style="{ width: `${critter.fertilityRatio * 100}%` }"
                /></span>
                {{ text.percent(critter.fertilityRatio) }}
                <abbr
                    v-if="frozen"
                    class="zone-sheet__frozen"
                    :title="t('zones.critter.frozen_hint')"
                    >· {{ t('zones.critter.frozen') }}</abbr
                >
            </span>
            <span v-else class="zone-sheet__muted">{{
                t('zones.panel.unknown')
            }}</span>
        </div>
        <div class="zone-sheet__row">
            <span class="zone-sheet__key">{{
                t('zones.critter.stomach')
            }}</span>
            <span v-if="critter.caloriesRatio !== null">
                <span class="zone-sheet__bar"
                    ><i :style="{ width: `${critter.caloriesRatio * 100}%` }"
                /></span>
                {{ text.percent(critter.caloriesRatio) }}
            </span>
            <span v-else class="zone-sheet__muted">{{
                t('zones.panel.unknown')
            }}</span>
        </div>
        <div class="zone-sheet__row">
            <span class="zone-sheet__key">{{ t('zones.critter.age') }}</span>
            <span
                v-if="
                    critter.ageCycles !== null &&
                    critter.lifespanCycles !== null
                "
                >{{
                    t('zones.critter.age_value', {
                        age: format.number(critter.ageCycles, 1),
                        max: format.number(critter.lifespanCycles),
                    })
                }}</span
            >
            <span v-else class="zone-sheet__muted">{{
                t('zones.panel.unknown')
            }}</span>
        </div>
        <!-- endregion -->

        <!-- region Next egg -->
        <template v-if="odds.length > 0">
            <p class="zone-sheet__label">{{ t('zones.critter.next_egg') }}</p>
            <p class="zone-sheet__odds">
                <span v-for="odd in odds" :key="odd.egg"
                    ><b>{{ text.percent(odd.share) }}</b> {{ odd.name }}</span
                >
            </p>
        </template>
        <!-- endregion -->
    </div>
</template>

<style scoped>
.zone-sheet {
    width: min(420px, 100%);
    padding: 12px 14px;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--ink-0);
    font-size: 13px;
}

/* region Who */
.zone-sheet__who {
    display: flex;
    align-items: center;
    gap: 12px;
}

.zone-sheet__name {
    font: 700 17px/1.1 var(--font-figure);
}

.zone-sheet__muted {
    color: var(--text-3);
    font-size: 12.5px;
}

.zone-sheet__pills {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin: 10px 0 6px;
}

.zone-pill {
    padding: 2px 8px;
    border-radius: 99px;
    background: var(--ink-2);
    color: var(--text-2);
    font-size: 12px;
}

.zone-pill--ok {
    background: oklch(28% 0.05 158);
    color: var(--ok);
}

.zone-pill--warn {
    background: oklch(28% 0.07 62);
    color: var(--warn);
}

.zone-pill--bad {
    background: oklch(28% 0.07 25);
    color: var(--bad);
}
/* endregion */

/* region Gauges */
.zone-sheet__row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 3px 0;
}

.zone-sheet__key {
    color: var(--text-2);
}

.zone-sheet__bar {
    display: inline-block;
    width: 90px;
    height: 5px;
    margin-right: 8px;
    overflow: hidden;
    border-radius: 3px;
    background: var(--ink-3);
    vertical-align: middle;
}

.zone-sheet__bar i {
    display: block;
    height: 100%;
    background: var(--ok);
}

.zone-sheet__bar .zone-sheet__bar--frozen {
    background: var(--warn);
}

.zone-sheet__frozen {
    color: var(--warn);
    text-decoration: none;
    cursor: help;
}
/* endregion */

/* region Next egg */
.zone-sheet__label {
    margin: 12px 0 6px;
    color: var(--text-3);
    font: 600 11px/1 var(--font-figure);
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.zone-sheet__odds {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 12px;
    color: var(--text-2);
}

.zone-sheet__odds b {
    color: var(--text);
    font-weight: 600;
}
/* endregion */
</style>
