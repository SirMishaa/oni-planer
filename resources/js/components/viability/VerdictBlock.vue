<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ColonyController from '@/actions/App/Http/Controllers/ColonyController';
import ProjectionChart from '@/components/viability/ProjectionChart.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import type { ViabilityData } from '@/types/App/Data/Viability/data.d';

// region Props

const props = defineProps<{
    viability: ViabilityData;
    /** Cycles simulated; an autonomy beyond it shows as `50+`. */
    horizon: number;
    /** The headline autonomy, already formatted (`12` or `50+`). */
    autonomyText: string;
}>();

// endregion

// region Copy

const { t, tc } = useT();
const format = useFormat();

const automatic = computed(() => props.viability.scenarios.automatic);

/** No autonomy without the duplicants or the food: say which one is missing. */
const unknown = computed(() => props.viability.verdict === 'unknown');

const sentence = computed(() => {
    if (unknown.value) {
        return (['duplicants', 'food'] as const)
            .filter((section) => props.viability.unavailable.includes(section))
            .map((section) => t(`colony.viability.verdict.unknown.${section}`))
            .join(' ');
    }

    if (props.viability.eaters.length === 0) {
        return t('colony.viability.verdict.nobody');
    }

    const autonomy = automatic.value.autonomy;

    if (autonomy === null) {
        return tc(
            'colony.viability.verdict.holds',
            props.viability.eaters.length,
            { horizon: props.horizon },
        );
    }

    const cycle = Math.floor(props.viability.nowCycle) + autonomy + 1;

    return automatic.value.firstShort
        ? t('colony.viability.verdict.short', {
              name: automatic.value.firstShort,
              cycle,
          })
        : t('colony.viability.verdict.short_anonymous', { cycle });
});

/** The figure stands, but without any harvest. */
const plantsMissing = computed(() =>
    props.viability.unavailable.includes('plants'),
);

const noHarvest = computed(() => {
    const autonomy = props.viability.scenarios.none.autonomy;

    return autonomy === null
        ? t('colony.viability.verdict.no_harvest_beyond', {
              count: props.horizon,
          })
        : tc('colony.viability.verdict.no_harvest', autonomy);
});

/** The stock someone may eat, then aside what every duplicant is forbidden. */
const consumption = computed(() => {
    const uneaten = props.viability.uneatenStockKcal;

    return t(
        uneaten > 0
            ? 'colony.viability.verdict.consumption_uneaten'
            : 'colony.viability.verdict.consumption',
        {
            kcal: format.number(props.viability.consumptionKcalPerCycle),
            stock: format.number(props.viability.stockKcal),
            uneaten: format.number(uneaten),
        },
    );
});

const unit = computed(() =>
    tc(
        'colony.viability.verdict.unit',
        automatic.value.autonomy ?? props.horizon,
    ),
);

// endregion
</script>

<template>
    <section class="verdict" :class="`verdict--${viability.verdict}`">
        <!-- region Figure -->
        <div class="min-w-0">
            <p class="verdict__eyebrow">
                {{ t('colony.viability.verdict.heading') }}
            </p>
            <p v-if="!unknown" class="figure verdict__big">
                {{ autonomyText }}<small>{{ unit }}</small>
            </p>
            <p class="verdict__sentence">
                <strong class="verdict__state">{{
                    t(`colony.viability.verdict.state.${viability.verdict}`)
                }}</strong>
                {{ sentence }}
            </p>
            <p v-if="plantsMissing && !unknown" class="verdict__qualifier">
                {{ t('colony.viability.verdict.no_plants') }}
            </p>
            <p v-if="unknown" class="verdict__aside">
                <Link
                    :href="ColonyController.uplink.url()"
                    class="verdict__link"
                >
                    {{ t('colony.viability.verdict.inspector') }}
                </Link>
            </p>
            <p v-else class="verdict__aside">
                {{ noHarvest }} {{ consumption }}
            </p>
        </div>
        <!-- endregion -->

        <!-- region Projection -->
        <ProjectionChart
            v-if="!unknown"
            :scenarios="viability.scenarios"
            :now-cycle="viability.nowCycle"
        />
        <!-- endregion -->
    </section>
</template>

<style scoped>
.verdict {
    --state: var(--ok);
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 28px 40px;
    align-items: center;
    margin-top: 20px;
    padding-block: 22px 26px;
    border-block: 1px solid var(--line);
}

@media (min-width: 1024px) {
    .verdict {
        grid-template-columns: minmax(300px, 1fr) minmax(0, 1.35fr);
    }
}

.verdict--tight {
    --state: var(--warn);
}

.verdict--critical {
    --state: var(--bad);
}

.verdict--unknown {
    --state: var(--text);
}

.verdict__eyebrow {
    color: var(--text-3);
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.verdict__big {
    margin-top: 6px;
    color: var(--state);
    font-size: clamp(64px, 18vw, 92px);
    font-weight: 700;
    line-height: 0.86;
}

.verdict__big small {
    margin-left: 8px;
    color: var(--text-2);
    font-size: 26px;
    font-weight: 600;
}

.verdict__sentence {
    max-width: 44ch;
    margin-top: 14px;
    font-size: 17px;
}

.verdict__state {
    color: var(--state);
    font-weight: 700;
}

.verdict__qualifier {
    max-width: 52ch;
    margin-top: 6px;
    color: var(--text);
    font-size: 14px;
    font-weight: 600;
}

.verdict__link {
    color: var(--text);
    text-decoration: underline;
    text-underline-offset: 3px;
}

.verdict__aside {
    max-width: 52ch;
    margin-top: 6px;
    color: var(--text-2);
    font-size: 14px;
}
</style>
