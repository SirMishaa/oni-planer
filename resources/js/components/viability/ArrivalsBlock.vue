<script setup lang="ts">
import { computed } from 'vue';
import CycleTimeline from '@/components/timeline/CycleTimeline.vue';
import type {
    TimelineEvent,
    TimelineMark,
} from '@/components/timeline/timeline';
import GameSprite from '@/components/viability/GameSprite.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import type {
    ViabilityData,
    ViabilitySourceData,
} from '@/types/App/Data/Viability/data.d';

// region Props

const props = defineProps<{
    viability: ViabilityData;
    /** The game could not read its plants this time. */
    unavailable: boolean;
}>();

// endregion

// region Timeline data

type SourceKind = ViabilitySourceData['kind'];
type HarvestPayload = { kcal: number };

const KINDS: SourceKind[] = ['planted', 'wild_auto', 'wild_manual'];

const { t, tc } = useT();
const format = useFormat();

const rows = computed(() =>
    props.viability.sources.map((source) => ({
        id: source.key,
        ariaLabel: t('colony.viability.arrivals.row', {
            name: source.plantName,
            detail: countLabel(source),
        }),
        source,
    })),
);

const events = computed<TimelineEvent<HarvestPayload>[]>(() =>
    props.viability.harvests.map((harvest) => ({
        rowId: harvest.sourceKey,
        cycle: harvest.cycle,
        kind: harvest.kind,
        payload: { kcal: harvest.kcal },
    })),
);

function countLabel(source: ViabilitySourceData): string {
    return tc(`colony.viability.arrivals.count.${source.kind}`, source.count);
}

function markTitle(mark: TimelineMark<HarvestPayload>): string {
    const kcal = mark.events.reduce(
        (sum, event) => sum + event.payload.kcal,
        0,
    );

    return tc('colony.viability.arrivals.mark', mark.events.length, {
        cycle: Math.floor(mark.cycle),
        kcal: format.number(kcal),
    });
}

// endregion
</script>

<template>
    <section class="arrivals">
        <!-- region Heading -->
        <h2 class="figure block-title">
            {{ t('colony.viability.arrivals.title') }}
        </h2>
        <p class="block-intro">{{ t('colony.viability.arrivals.intro') }}</p>
        <p v-if="unavailable" class="block-note" role="status">
            {{ t('colony.viability.unavailable.plants') }}
        </p>
        <p v-if="!viability.autoHarvestKnown" class="block-note">
            {{ t('colony.viability.arrivals.auto_unknown') }}
        </p>
        <!-- endregion -->

        <!-- region Timeline -->
        <p v-if="rows.length === 0 && !unavailable" class="block-empty">
            {{ t('colony.viability.arrivals.empty') }}
        </p>
        <template v-else-if="rows.length > 0">
            <CycleTimeline
                :rows="rows"
                :events="events"
                :start-cycle="Math.floor(viability.nowCycle)"
                :end-cycle="viability.lastComputedCycle"
                :now-cycle="viability.nowCycle"
                :min-gap-px="26"
                :zoom-in-label="t('colony.viability.arrivals.zoom_in')"
                :zoom-out-label="t('colony.viability.arrivals.zoom_out')"
            >
                <template #label="{ row }">
                    <div class="source">
                        <GameSprite
                            :src="row.source.plantIcon"
                            kind="plant"
                            :size="40"
                        />
                        <div class="min-w-0">
                            <b class="source__name">{{
                                row.source.plantName
                            }}</b>
                            <span class="source__meta">{{
                                countLabel(row.source)
                            }}</span>
                            <span
                                v-if="row.source.stopped > 0"
                                class="source__flag"
                            >
                                {{
                                    tc(
                                        'colony.viability.arrivals.stopped',
                                        row.source.stopped,
                                    )
                                }}
                            </span>
                        </div>
                    </div>
                </template>

                <template #summary="{ row }">
                    <b
                        class="figure source__sum"
                        :class="{
                            'source__sum--wild': row.source.kind !== 'planted',
                        }"
                    >
                        {{
                            t('colony.viability.arrivals.per_cycle', {
                                kcal: format.number(row.source.kcalPerCycle),
                            })
                        }}
                    </b>
                    <span class="source__meta">{{
                        t('colony.viability.arrivals.per_cycle_unit')
                    }}</span>
                </template>

                <template #mark="{ mark }">
                    <span
                        class="harvest"
                        :class="[
                            `harvest--${mark.kind}`,
                            { 'harvest--many': mark.events.length > 1 },
                        ]"
                        :title="markTitle(mark)"
                    >
                        <template v-if="mark.events.length > 1">{{
                            format.number(mark.events.length)
                        }}</template>
                    </span>
                </template>
            </CycleTimeline>

            <!-- region Legend -->
            <ul class="legend">
                <li>
                    <i class="legend__now" aria-hidden="true" />
                    {{ t('colony.viability.arrivals.legend.now') }}
                </li>
                <li v-for="kind in KINDS" :key="kind">
                    <i
                        class="harvest"
                        :class="`harvest--${kind}`"
                        aria-hidden="true"
                    />
                    {{ t(`colony.viability.arrivals.legend.${kind}`) }}
                </li>
            </ul>
            <!-- endregion -->
        </template>
        <!-- endregion -->
    </section>
</template>

<style scoped>
.source {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 10px;
    align-items: center;
}

.source__name {
    display: block;
    overflow-wrap: anywhere;
    font-weight: 600;
    line-height: 1.25;
}

.source__meta {
    display: block;
    color: var(--text-2);
    font-size: 13px;
}

.source__flag {
    display: block;
    color: var(--warn);
    font-size: 13px;
}

.source__sum {
    display: block;
    color: var(--ration);
    font-size: 18px;
    font-weight: 700;
    line-height: 1.1;
}

.source__sum--wild {
    color: var(--wild);
}

/* Harvest marks: shape and colour both carry the kind. */
.harvest {
    display: grid;
    place-items: center;
    min-width: 14px;
    height: 14px;
    border-radius: 999px;
    font-family: var(--font-figure);
    font-size: 12px;
    font-style: normal;
    font-weight: 700;
    line-height: 1;
}

.harvest--many {
    min-width: 24px;
    height: 24px;
    padding-inline: 5px;
}

.harvest--planted {
    background: var(--ration);
    color: var(--ration-ink);
}

.harvest--wild_auto {
    border: 2px solid var(--wild);
    background: var(--ink-2);
    color: var(--wild);
}

.harvest--wild_manual {
    border: 2px dashed var(--wild);
    background: var(--ink-0);
    color: var(--text);
}

.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 22px;
    margin-top: 12px;
    color: var(--text-2);
    font-size: 13px;
}

.legend li {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.legend__now {
    width: 2px;
    height: 14px;
    background: var(--ration);
}
</style>
