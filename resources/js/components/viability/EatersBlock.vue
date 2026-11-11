<script setup lang="ts">
import { computed } from 'vue';
import GameSprite from '@/components/viability/GameSprite.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import type {
    ViabilityEaterData,
    ViabilityMenuItemData,
    ViabilityNonEaterData,
} from '@/types/App/Data/Viability/data.d';

// region Props

const props = defineProps<{
    eaters: ViabilityEaterData[];
    nonEaters: ViabilityNonEaterData[];
    /** Gives the name and icon of each food id. */
    menu: ViabilityMenuItemData[];
    /** The game could not read its duplicants this time. */
    unavailable: boolean;
}>();

// endregion

// region Helpers

const { t, tc } = useT();
const format = useFormat();

const foods = computed(
    () => new Map(props.menu.map((item) => [item.foodId, item])),
);

function initials(name: string): string {
    return name.trim().charAt(0).toLocaleUpperCase();
}

function fill(eater: ViabilityEaterData): number {
    if (eater.kcal === null || !eater.maxKcal) {
        return 0;
    }

    return Math.min(100, Math.max(0, (eater.kcal / eater.maxKcal) * 100));
}

const nonEatersLine = computed(() =>
    tc('colony.viability.eaters.non_eaters', props.nonEaters.length, {
        names: format.list(props.nonEaters.map((other) => other.name)),
    }),
);

// endregion
</script>

<template>
    <section class="min-w-0">
        <!-- region Heading -->
        <h2 class="block-title">{{ t('colony.viability.eaters.title') }}</h2>
        <p class="block-intro">{{ t('colony.viability.eaters.intro') }}</p>
        <p v-if="unavailable" class="block-note" role="status">
            {{ t('colony.viability.unavailable.duplicants') }}
        </p>
        <!-- endregion -->

        <!-- region Duplicants -->
        <p v-if="eaters.length === 0 && !unavailable" class="block-empty">
            {{ t('colony.viability.eaters.empty') }}
        </p>
        <ul v-else>
            <li v-for="eater in eaters" :key="eater.id" class="guest">
                <span class="figure guest__face" aria-hidden="true">{{
                    initials(eater.name)
                }}</span>

                <div class="min-w-0">
                    <b class="guest__name">{{ eater.name }}</b>
                    <small class="guest__meta">{{
                        t('colony.viability.eaters.need', {
                            kcal: format.number(eater.needKcal),
                        })
                    }}</small>
                </div>

                <div class="guest__reserve">
                    <template v-if="eater.kcal !== null && eater.maxKcal">
                        <span class="figure guest__kcal">{{
                            t('colony.viability.eaters.reserve', {
                                kcal: format.number(eater.kcal),
                                max: format.number(eater.maxKcal),
                            })
                        }}</span>
                        <span
                            class="guest__meter"
                            role="meter"
                            :aria-label="
                                t('colony.viability.eaters.reserve_label', {
                                    name: eater.name,
                                })
                            "
                            :aria-valuenow="Math.round(eater.kcal)"
                            aria-valuemin="0"
                            :aria-valuemax="Math.round(eater.maxKcal)"
                        >
                            <i :style="{ width: `${fill(eater)}%` }" />
                        </span>
                    </template>
                    <small v-else class="guest__meta">{{
                        t('colony.viability.eaters.reserve_unknown')
                    }}</small>
                </div>

                <div class="guest__plate">
                    <GameSprite
                        v-for="foodId in eater.allowed"
                        :key="foodId"
                        :src="foods.get(foodId)?.icon ?? null"
                        kind="food"
                        :size="26"
                        :alt="foods.get(foodId)?.name ?? foodId"
                    />
                    <template v-if="eater.forbidden.length > 0">
                        <small class="guest__forbidden" aria-hidden="true">{{
                            t('colony.viability.eaters.forbidden')
                        }}</small>
                        <GameSprite
                            v-for="foodId in eater.forbidden"
                            :key="foodId"
                            :src="foods.get(foodId)?.icon ?? null"
                            kind="food"
                            :size="26"
                            dimmed
                            :alt="
                                t('colony.viability.eaters.forbidden_food', {
                                    name: foods.get(foodId)?.name ?? foodId,
                                })
                            "
                        />
                    </template>
                </div>
            </li>
        </ul>
        <p v-if="nonEaters.length > 0" class="guest__others">
            {{ nonEatersLine }}
        </p>
        <!-- endregion -->
    </section>
</template>

<style scoped>
.guest {
    display: grid;
    grid-template-columns: 40px minmax(0, 1fr) auto;
    gap: 6px 12px;
    align-items: center;
    padding-block: 9px;
    border-bottom: 1px solid var(--ink-3);
}

.guest__face {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    grid-row: span 2;
    border-radius: 50%;
    background: var(--ink-3);
    color: var(--text);
    font-size: 16px;
    font-weight: 700;
}

.guest__name {
    display: block;
    font-weight: 600;
    line-height: 1.25;
}

.guest__meta {
    display: block;
    color: var(--text-3);
    font-size: 12.5px;
}

.guest__reserve {
    min-width: 0;
    text-align: right;
}

.guest__kcal {
    font-size: 14px;
    font-weight: 600;
}

.guest__meter {
    display: block;
    width: 140px;
    max-width: 100%;
    height: 8px;
    margin-top: 5px;
    margin-left: auto;
    overflow: hidden;
    border-radius: 4px;
    background: var(--ink-3);
}

.guest__meter i {
    display: block;
    height: 100%;
    background: var(--ration);
}

.guest__plate {
    display: flex;
    flex-wrap: wrap;
    grid-column: 2 / -1;
    gap: 3px;
    align-items: center;
}

.guest__forbidden {
    margin-inline: 10px 2px;
    color: var(--text-3);
    font-size: 12px;
}

.guest__others {
    margin-top: 10px;
    color: var(--text-2);
    font-size: 14px;
}
</style>
