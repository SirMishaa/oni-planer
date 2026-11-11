<script setup lang="ts">
import { computed, toRef } from 'vue';
import ArrivalsBlock from '@/components/viability/ArrivalsBlock.vue';
import ColonyEmptyState from '@/components/viability/ColonyEmptyState.vue';
import EatersBlock from '@/components/viability/EatersBlock.vue';
import MenuBlock from '@/components/viability/MenuBlock.vue';
import ReservesBlock from '@/components/viability/ReservesBlock.vue';
import UplinkStatus from '@/components/viability/UplinkStatus.vue';
import VerdictBlock from '@/components/viability/VerdictBlock.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import { useUplink } from '@/composables/useUplink';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ColonyStatusData } from '@/types/App/Data/data.d';
import type { ViabilityData } from '@/types/App/Data/Viability/data.d';

defineOptions({ layout: AppLayout });

// region Props

const props = defineProps<{
    status: ColonyStatusData;
    viability: ViabilityData | null;
}>();

// endregion

// region Live link

const { live, ageLabel } = useUplink(toRef(props, 'status'), [
    'status',
    'viability',
]);

// endregion

// region Headline

const { t } = useT();
const format = useFormat();

/** Simulated cycles: the stock series holds now plus one value per cycle. */
const horizon = computed(() =>
    Math.max(
        1,
        (props.viability?.scenarios.automatic.stockByCycle.length ?? 51) - 1,
    ),
);

const autonomyText = computed(() => {
    const autonomy = props.viability?.scenarios.automatic.autonomy ?? null;

    return autonomy === null
        ? t('colony.viability.verdict.beyond', {
              count: format.number(horizon.value),
          })
        : format.number(autonomy);
});

const LIMITS = [
    'spoilage',
    'raw',
    'picking',
    'asteroids',
    'body_reserves',
] as const;

// endregion
</script>

<template>
    <div class="colony min-h-full pb-16 md:pb-0">
        <div class="mx-auto max-w-[1240px] px-4 pt-6 pb-20 md:px-9">
            <!-- region Header -->
            <header
                class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3"
            >
                <div class="min-w-0">
                    <p class="page-crumb">{{ t('colony.nav.group') }}</p>
                    <h1 class="page-title">
                        {{ t('colony.viability.title') }}
                    </h1>
                </div>
                <UplinkStatus
                    :status="status"
                    :live="live"
                    :age-label="ageLabel"
                />
            </header>

            <p
                v-if="status.state === 'newer_schema'"
                class="page-banner"
                role="status"
            >
                {{ t('colony.viability.newer_schema') }}
            </p>
            <!-- endregion -->

            <!-- region Empty states -->
            <ColonyEmptyState
                v-if="!viability"
                :state="status.state === 'never' ? 'never' : 'no_colony'"
            />
            <!-- endregion -->

            <!-- region Blocks -->
            <template v-else>
                <VerdictBlock
                    :viability="viability"
                    :horizon="horizon"
                    :autonomy-text="autonomyText"
                />

                <ArrivalsBlock
                    :viability="viability"
                    :unavailable="viability.unavailable.includes('plants')"
                />

                <div class="grid grid-cols-1 gap-x-14 lg:grid-cols-2">
                    <MenuBlock
                        :menu="viability.menu"
                        :stock-kcal="viability.stockKcal"
                        :unavailable="viability.unavailable.includes('food')"
                    />
                    <EatersBlock
                        :eaters="viability.eaters"
                        :non-eaters="viability.nonEaters"
                        :menu="viability.menu"
                        :unavailable="
                            viability.unavailable.includes('duplicants')
                        "
                    />
                </div>

                <ReservesBlock
                    :reserves="viability.reserves"
                    :autonomy-text="autonomyText"
                    :unavailable="viability.unavailable"
                />

                <!-- region Limits -->
                <footer class="page-limits">
                    <h2 class="page-limits__title">
                        {{ t('colony.viability.limits.title') }}
                    </h2>
                    <ul>
                        <li v-for="limit in LIMITS" :key="limit">
                            {{ t(`colony.viability.limits.${limit}`) }}
                        </li>
                    </ul>
                </footer>
                <!-- endregion -->
            </template>
            <!-- endregion -->
        </div>
    </div>
</template>

<style scoped>
.page-crumb {
    color: var(--text-3);
    font-size: 13px;
}

.page-title {
    font-family: var(--font-figure);
    font-size: 27px;
    font-weight: 700;
    line-height: 1.15;
}

.page-banner {
    max-width: 80ch;
    margin-top: 18px;
    padding: 10px 14px;
    border: 1px solid oklch(80% 0.14 62 / 0.55);
    border-radius: 8px;
    background: oklch(80% 0.14 62 / 0.1);
    color: var(--text);
    font-size: 14px;
}

.page-limits {
    margin-top: 48px;
    padding-top: 16px;
    border-top: 1px solid var(--line);
    color: var(--text-3);
    font-size: 13px;
}

.page-limits__title {
    margin-bottom: 6px;
    color: var(--text-2);
    font-size: 13px;
    font-weight: 600;
}

.page-limits ul {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 20px;
}
</style>
