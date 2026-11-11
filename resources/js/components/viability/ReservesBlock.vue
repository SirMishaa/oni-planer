<script setup lang="ts">
import { computed } from 'vue';
import GameSprite from '@/components/viability/GameSprite.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import type {
    ViabilityCritterReserveData,
    ViabilityReservesData,
} from '@/types/App/Data/Viability/data.d';

// region Props

const props = defineProps<{
    reserves: ViabilityReservesData;
    /** The headline autonomy, already formatted (`12` or `50+`), or null when it could not be computed. */
    autonomyText: string | null;
    /** Sections the game could not read this time. */
    unavailable: string[];
}>();

// endregion

// region Helpers

/** Printing Pod sprite (the building), shown with a placeholder if absent. */
const POD_SPRITE = '/assets/game/buildings/Headquarters.webp';

const { t, tc } = useT();
const format = useFormat();

const notes = computed(() =>
    (['plants', 'critters', 'printingPod'] as const)
        .filter((section) => props.unavailable.includes(section))
        .map((section) => t(`colony.viability.unavailable.${section}`)),
);

function critterCount(critter: ViabilityCritterReserveData): string {
    return tc(
        critter.tame
            ? 'colony.viability.reserves.critter.tame'
            : 'colony.viability.reserves.critter.wild',
        critter.count,
    );
}

const podNext = computed(() => {
    const cycles = props.reserves.printingPod.cyclesUntilNextPrint;

    return cycles === null
        ? t('colony.viability.reserves.pod.next_unknown')
        : tc(
              'colony.viability.reserves.pod.next',
              Math.round(cycles * 10) / 10,
          );
});

// endregion
</script>

<template>
    <section>
        <!-- region Heading -->
        <h2 class="block-title">{{ t('colony.viability.reserves.title') }}</h2>
        <p class="block-intro">
            {{
                autonomyText === null
                    ? t('colony.viability.reserves.intro_unknown')
                    : t('colony.viability.reserves.intro', {
                          autonomy: autonomyText,
                      })
            }}
        </p>
        <p v-for="note in notes" :key="note" class="block-note" role="status">
            {{ note }}
        </p>
        <!-- endregion -->

        <ul class="reserves">
            <!-- region Wild plants to pick -->
            <li
                v-for="source in reserves.wildManual"
                :key="source.key"
                class="reserve"
            >
                <GameSprite
                    :src="source.plantIcon"
                    kind="plant"
                    :size="60"
                    :dimmed="source.forbiddenToAll"
                />
                <div class="min-w-0">
                    <b class="reserve__title">{{
                        t('colony.viability.reserves.wild.title', {
                            name: source.plantName,
                        })
                    }}</b>
                    <span class="reserve__body">{{
                        tc('colony.viability.reserves.wild.body', source.count)
                    }}</span>
                    <em
                        v-if="source.forbiddenToAll"
                        class="reserve__value reserve__value--uneaten"
                        >{{
                            t('colony.viability.reserves.wild.nobody_eats')
                        }}</em
                    >
                    <em v-else class="figure reserve__value">{{
                        t('colony.viability.reserves.wild.value', {
                            kcal: format.number(source.kcalPerCycle),
                        })
                    }}</em>
                </div>
            </li>
            <!-- endregion -->

            <!-- region Critters -->
            <li
                v-for="critter in reserves.critters"
                :key="`${critter.id}:${critter.tame}`"
                class="reserve"
            >
                <GameSprite :src="critter.icon" kind="critter" :size="60" />
                <div class="min-w-0">
                    <b class="reserve__title">{{ critter.name }}</b>
                    <span class="reserve__body">{{
                        critterCount(critter)
                    }}</span>
                    <em class="figure reserve__value">{{
                        critter.kcal === null
                            ? t('colony.viability.reserves.critter.unknown')
                            : t('colony.viability.reserves.critter.value', {
                                  kcal: format.number(critter.kcal),
                              })
                    }}</em>
                </div>
            </li>
            <!-- endregion -->

            <!-- region Printing Pod -->
            <li class="reserve">
                <GameSprite :src="POD_SPRITE" kind="building" :size="60" />
                <div class="min-w-0">
                    <b class="reserve__title">{{
                        t('colony.viability.reserves.pod.title')
                    }}</b>
                    <span class="reserve__body">{{ podNext }}</span>
                    <em class="figure reserve__value">{{
                        t('colony.viability.reserves.pod.value')
                    }}</em>
                </div>
            </li>
            <!-- endregion -->
        </ul>
    </section>
</template>

<style scoped>
.reserves {
    display: grid;
    gap: 0 40px;
}

@media (min-width: 768px) {
    .reserves {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (min-width: 1024px) {
    .reserves {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

.reserve {
    display: grid;
    grid-template-columns: 60px minmax(0, 1fr);
    gap: 14px;
    align-items: center;
    padding-block: 12px;
    border-bottom: 1px solid var(--ink-3);
}

.reserve__title {
    display: block;
    font-weight: 600;
    line-height: 1.25;
}

.reserve__body {
    display: block;
    color: var(--text-2);
    font-size: 13px;
}

.reserve__value {
    display: block;
    color: var(--wild);
    font-size: 17px;
    font-style: normal;
    font-weight: 700;
}

.reserve__value--uneaten {
    color: var(--text-3);
    font-size: 14px;
    font-style: italic;
    font-weight: 400;
}
</style>
