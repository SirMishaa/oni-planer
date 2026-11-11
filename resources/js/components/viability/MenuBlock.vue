<script setup lang="ts">
import { computed } from 'vue';
import GameSprite from '@/components/viability/GameSprite.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import type { ViabilityMenuItemData } from '@/types/App/Data/Viability/data.d';

// region Props

const props = defineProps<{
    menu: ViabilityMenuItemData[];
    /** Stock at least one duplicant may eat. */
    stockKcal: number;
    /** Stock every duplicant is forbidden. */
    uneatenStockKcal: number;
    /** The game could not read its food stock this time. */
    unavailable: boolean;
}>();

// endregion

const { t, tc } = useT();
const format = useFormat();

const dishes = computed(() => [...props.menu].sort((a, b) => b.kcal - a.kcal));

/** Units rounded to one decimal, so `66.94` reads `66,9 unités`. */
function unitsLabel(item: ViabilityMenuItemData): string {
    return tc('colony.viability.menu.units', Math.round(item.units * 10) / 10);
}
</script>

<template>
    <section class="min-w-0">
        <!-- region Heading -->
        <h2 class="block-title">{{ t('colony.viability.menu.title') }}</h2>
        <p class="block-intro">
            {{
                t(
                    uneatenStockKcal > 0
                        ? 'colony.viability.menu.intro_uneaten'
                        : 'colony.viability.menu.intro',
                    {
                        kcal: format.number(stockKcal),
                        uneaten: format.number(uneatenStockKcal),
                    },
                )
            }}
        </p>
        <p v-if="unavailable" class="block-note" role="status">
            {{ t('colony.viability.unavailable.food') }}
        </p>
        <!-- endregion -->

        <!-- region Dishes -->
        <p v-if="dishes.length === 0 && !unavailable" class="block-empty">
            {{ t('colony.viability.menu.empty') }}
        </p>
        <ul v-else class="menu">
            <li v-for="item in dishes" :key="item.foodId" class="menu__row">
                <GameSprite :src="item.icon" kind="food" :size="40" />
                <div class="min-w-0">
                    <b class="menu__name">{{ item.name }}</b>
                    <small class="menu__meta">{{ unitsLabel(item) }}</small>
                    <small
                        v-if="item.forbiddenFor.length > 0"
                        class="menu__forbidden"
                    >
                        {{
                            t('colony.viability.menu.forbidden', {
                                names: format.list(item.forbiddenFor),
                            })
                        }}
                    </small>
                </div>
                <span class="menu__leader" aria-hidden="true" />
                <span class="figure menu__kcal">{{
                    t('colony.viability.menu.kcal', {
                        kcal: format.number(item.kcal),
                    })
                }}</span>
            </li>
        </ul>
        <!-- endregion -->
    </section>
</template>

<style scoped>
.menu__row {
    display: grid;
    grid-template-columns: 40px minmax(0, auto) minmax(16px, 1fr) auto;
    gap: 12px;
    align-items: center;
    padding-block: 7px;
}

.menu__name {
    display: block;
    font-weight: 600;
    line-height: 1.25;
}

.menu__meta,
.menu__forbidden {
    display: block;
    color: var(--text-3);
    font-size: 12.5px;
}

.menu__forbidden {
    color: var(--warn);
}

.menu__leader {
    border-bottom: 2px dotted var(--line);
    transform: translateY(5px);
}

.menu__kcal {
    color: var(--ration);
    font-size: 17px;
    font-weight: 700;
    white-space: nowrap;
}
</style>
