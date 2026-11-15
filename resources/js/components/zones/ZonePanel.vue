<script setup lang="ts">
import { computed } from 'vue';
import GameSprite from '@/components/viability/GameSprite.vue';
import ZoneIcon from '@/components/zones/ZoneIcon.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import { useZoneText } from '@/composables/useZoneText';
import type {
    ZoneBuildingData,
    ZoneCountsData,
    ZoneCritterData,
    ZoneEggData,
    ZoneEntityData,
    ZonePopulationData,
} from '@/types/App/Data/data.d';
import ZoneCritterSheet from './ZoneCritterSheet.vue';
import { cyclesToHatch, entitySpriteKind, fertilityFrozen } from './zoneFlock';
import { breedingStopped } from './zonePopulation';
import ZonePopulation from './ZonePopulation.vue';
import { zoneVerdict } from './zoneVerdict';

// region Props

const props = defineProps<{
    entities: ZoneEntityData[];
    problems: ZoneEntityData[];
    counts: ZoneCountsData;
    cellCount: number;
    plantsKnown: boolean;
    buildingStatesKnown: boolean;
    /** Crowding of each cavity and pond; null until the game sent the critters. */
    population: ZonePopulationData[] | null;
    /** Sprite of the zone's most common plant, the panel's illustration. */
    crest: string | null;
    selected: ZoneEntityData | null;
    /** A selected layout building whose state the game has not sent (shown as unknown). */
    selectedBuilding?: ZoneBuildingData | null;
    /** A selected critter or egg of the map. */
    selectedCritter?: ZoneCritterData | null;
    selectedEgg?: ZoneEggData | null;
    /** Width of the world grid, to place an egg from its cell. */
    gridWidth?: number | null;
}>();

const emit = defineEmits<{
    select: [instanceId: string];
    /** A critter problem: open the Entities tab on its flock band (and select the critter, if it is one). */
    flock: [groupKey: string, instanceId: string | null];
}>();

// endregion

// region Verdict

const { t, tc } = useT();
const format = useFormat();
const text = useZoneText();

const known = computed(
    () =>
        props.plantsKnown ||
        props.buildingStatesKnown ||
        props.problems.length > 0,
);
const partial = computed(
    () => known.value && !(props.plantsKnown && props.buildingStatesKnown),
);

const verdict = computed(() =>
    zoneVerdict(
        props.problems.length,
        props.plantsKnown,
        props.buildingStatesKnown,
    ),
);

const verdictText = computed(() =>
    verdict.value === 'issues'
        ? tc('zones.panel.verdict.issues', props.problems.length)
        : t(
              verdict.value === 'partial'
                  ? 'zones.panel.verdict.partial_clear'
                  : `zones.panel.verdict.${verdict.value}`,
          ),
);

const stopped = computed(() => breedingStopped(props.population));

const plantStates = computed(() => {
    const plants = props.entities.filter((entity) => entity.kind === 'plant');

    return {
        total: plants.length,
        growing: plants.filter((plant) => plant.state === 'growing').length,
        grown: plants.filter((plant) => plant.state === 'grown').length,
        wilting: plants.filter((plant) => plant.state === 'wilting').length,
    };
});

/** `13 plantes · 12 en croissance · 1 prête à récolter`. */
const summary = computed(() => {
    if (!props.plantsKnown) {
        return null;
    }

    const states = plantStates.value;

    if (states.total === 0) {
        return t('zones.panel.summary.no_plants');
    }

    return [
        tc('zones.panel.summary.plants', states.total),
        states.growing > 0
            ? t('zones.panel.summary.growing', { count: states.growing })
            : null,
        states.grown > 0 ? tc('zones.panel.summary.grown', states.grown) : null,
        states.wilting > 0
            ? tc('zones.panel.summary.wilting', states.wilting)
            : null,
    ]
        .filter((part) => part !== null)
        .join(' · ');
});

// endregion

// region Selection

/** The selected egg's cell, and its hatch in cycles when the game sent its incubation. */
const egg = computed(() => {
    const selectedEgg = props.selectedEgg;

    if (!selectedEgg) {
        return null;
    }

    const gridWidth = props.gridWidth ?? null;

    return {
        position:
            gridWidth === null
                ? null
                : text.position(
                      selectedEgg.cell % gridWidth,
                      Math.floor(selectedEgg.cell / gridWidth),
                  ),
        hatchCycles:
            selectedEgg.incubation === null ||
            selectedEgg.incubationCycles === null
                ? null
                : cyclesToHatch(
                      { incubation: selectedEgg.incubation },
                      selectedEgg.incubationCycles,
                  ),
    };
});

function select(entity: ZoneEntityData): void {
    if (entity.groupKey !== null) {
        emit(
            'flock',
            entity.groupKey,
            entity.kind === 'critter' ? entity.instanceId : null,
        );

        return;
    }

    if (entity.instanceId !== null) {
        emit('select', entity.instanceId);
    }
}

// endregion
</script>

<template>
    <aside class="zone-panel">
        <!-- region Verdict -->
        <section class="zone-hero" :class="`zone-hero--${verdict}`">
            <span class="zone-hero__pic">
                <GameSprite :src="crest" kind="plant" :size="52" />
                <span class="zone-hero__badge">
                    <ZoneIcon
                        :name="
                            verdict === 'ok'
                                ? 'check'
                                : verdict === 'issues'
                                  ? 'alert'
                                  : 'question'
                        "
                        :size="14"
                    />
                </span>
            </span>
            <div class="min-w-0">
                <p class="zone-hero__verdict">{{ verdictText }}</p>
                <p v-if="summary" class="zone-panel__muted">{{ summary }}</p>
                <p v-if="stopped" class="zone-hero__stopped">
                    <ZoneIcon name="alert" :size="13" />{{
                        t('zones.population.stopped')
                    }}
                </p>
                <p v-if="!known" class="zone-panel__muted">
                    {{ t('zones.panel.summary.unknown') }}
                </p>
                <p v-else-if="partial" class="zone-hero__partial">
                    <strong>{{ t('zones.panel.verdict.partial') }}.</strong>
                    {{ t('zones.panel.summary.partial') }}
                </p>
            </div>
        </section>
        <!-- endregion -->

        <!-- region To fix -->
        <section>
            <h2 class="zone-panel__heading">{{ t('zones.panel.todo') }}</h2>
            <p v-if="!known" class="zone-panel__muted">
                {{ t('zones.panel.todo_unknown') }}
            </p>
            <p v-else-if="problems.length === 0" class="zone-panel__muted">
                {{
                    t(
                        partial
                            ? 'zones.panel.nothing_todo_partial'
                            : 'zones.panel.nothing_todo',
                    )
                }}
            </p>
            <p v-else-if="partial" class="zone-panel__muted zone-issues__note">
                {{ t('zones.panel.todo_partial') }}
            </p>
            <ul v-if="known && problems.length > 0" class="zone-issues">
                <li
                    v-for="(entity, index) in problems"
                    :key="entity.instanceId ?? `problem-${index}`"
                    class="zone-issue"
                >
                    <span class="zone-issue__thumb">
                        <GameSprite
                            :src="entity.sprite"
                            :kind="entitySpriteKind(entity.kind)"
                            :size="38"
                            :alt="entity.name"
                        />
                        <img
                            v-if="entity.neededItemSprite"
                            :src="entity.neededItemSprite"
                            alt=""
                            class="zone-issue__need"
                        />
                    </span>
                    <span class="min-w-0">
                        <b class="zone-issue__name">{{ entity.name }}</b>
                        <span
                            v-for="problem in entity.problems"
                            :key="problem"
                            class="zone-issue__problem"
                            >{{ problem }}</span
                        >
                    </span>
                    <button
                        v-if="entity.groupKey !== null"
                        type="button"
                        class="zone-issue__show"
                        @click="select(entity)"
                    >
                        <ZoneIcon name="paw" :size="13" />
                        {{ t('zones.panel.show_flock') }}
                    </button>
                    <button
                        v-else-if="
                            entity.instanceId !== null && entity.x !== null
                        "
                        type="button"
                        class="zone-issue__show"
                        @click="select(entity)"
                    >
                        <ZoneIcon name="pin" :size="13" />
                        {{ t('zones.panel.show') }}
                    </button>
                </li>
            </ul>
        </section>
        <!-- endregion -->

        <!-- region Population -->
        <ZonePopulation :population="population" />
        <!-- endregion -->

        <!-- region Contents -->
        <section>
            <h2 class="zone-panel__heading">{{ t('zones.panel.contents') }}</h2>
            <dl class="zone-kv">
                <dt>
                    <ZoneIcon name="leaf" />{{
                        t('zones.entities.filters.plants')
                    }}
                </dt>
                <dd>
                    <template v-if="plantsKnown">{{ counts.plants }}</template>
                    <span v-else class="zone-panel__unknown">{{
                        t('zones.panel.unknown')
                    }}</span>
                </dd>
                <dt>
                    <ZoneIcon name="cube" />{{
                        t('zones.entities.filters.buildings')
                    }}
                </dt>
                <dd>
                    <template v-if="buildingStatesKnown">{{
                        counts.buildings
                    }}</template>
                    <span v-else class="zone-panel__unknown">{{
                        t('zones.panel.unknown')
                    }}</span>
                </dd>
                <template v-if="population !== null">
                    <dt>
                        <ZoneIcon name="paw" />{{
                            t('zones.entities.filters.critters')
                        }}
                    </dt>
                    <dd>{{ counts.critters }}</dd>
                    <dt>
                        <ZoneIcon name="egg" />{{
                            t('zones.entities.filters.eggs')
                        }}
                    </dt>
                    <dd>{{ counts.eggs }}</dd>
                </template>
                <dt><ZoneIcon name="grid" />{{ t('zones.panel.size') }}</dt>
                <dd>{{ tc('zones.index.cells', cellCount) }}</dd>
            </dl>
        </section>
        <!-- endregion -->

        <!-- region Selection -->
        <section>
            <h2 class="zone-panel__heading">
                {{ t('zones.panel.selection') }}
            </h2>
            <ZoneCritterSheet
                v-if="selectedCritter"
                :critter="selectedCritter"
                :frozen="fertilityFrozen(selectedCritter, population)"
            />
            <template v-else-if="selectedEgg && egg">
                <div class="zone-selected">
                    <GameSprite
                        :src="selectedEgg.sprite"
                        kind="critter"
                        :size="28"
                    />
                    <b class="zone-selected__name">{{ selectedEgg.name }}</b>
                    <span v-if="egg.position" class="zone-panel__muted">{{
                        egg.position
                    }}</span>
                </div>
                <dl class="zone-kv">
                    <dt>
                        <ZoneIcon name="egg" />{{ t('zones.eggs.incubation') }}
                    </dt>
                    <dd>
                        <template v-if="selectedEgg.incubation !== null"
                            >{{
                                format.number(selectedEgg.incubation)
                            }}
                            %</template
                        >
                        <span v-else class="zone-panel__unknown">{{
                            t('zones.panel.unknown')
                        }}</span>
                    </dd>
                    <dt>
                        <ZoneIcon name="clock" />{{
                            t('zones.eggs.hatches_in')
                        }}
                    </dt>
                    <dd>
                        <template v-if="egg.hatchCycles !== null">{{
                            tc(
                                'zones.time.cycles',
                                Math.round(egg.hatchCycles * 10) / 10,
                            )
                        }}</template>
                        <span v-else class="zone-panel__unknown">{{
                            t('zones.panel.unknown')
                        }}</span>
                    </dd>
                    <dt>{{ t('zones.eggs.viability') }}</dt>
                    <dd>
                        <template v-if="selectedEgg.viability !== null"
                            >{{
                                format.number(selectedEgg.viability)
                            }}
                            %</template
                        >
                        <span v-else class="zone-panel__unknown">{{
                            t('zones.panel.unknown')
                        }}</span>
                    </dd>
                    <dt>{{ t('zones.panel.place') }}</dt>
                    <dd>
                        {{
                            t(
                                `zones.eggs.location.${selectedEgg.location ?? 'unknown'}`,
                            )
                        }}
                    </dd>
                </dl>
            </template>
            <template v-else-if="!selected && selectedBuilding">
                <div class="zone-selected">
                    <GameSprite
                        :src="selectedBuilding.sprite"
                        kind="building"
                        :size="28"
                    />
                    <b class="zone-selected__name">{{
                        selectedBuilding.name
                    }}</b>
                    <span class="zone-panel__muted">{{
                        text.position(selectedBuilding.x, selectedBuilding.y)
                    }}</span>
                </div>
                <dl class="zone-kv">
                    <dt>{{ t('zones.panel.state') }}</dt>
                    <dd class="zone-panel__unknown">
                        {{ t('zones.panel.state_unknown') }}
                    </dd>
                </dl>
            </template>
            <p v-else-if="!selected" class="zone-panel__muted">
                {{ t('zones.panel.selection_hint') }}
            </p>
            <template v-else>
                <div class="zone-selected">
                    <GameSprite
                        :src="selected.sprite"
                        :kind="selected.kind"
                        :size="28"
                    />
                    <b class="zone-selected__name">{{ selected.name }}</b>
                    <span class="zone-panel__muted">{{
                        text.position(selected.x, selected.y)
                    }}</span>
                </div>
                <dl class="zone-kv">
                    <dt>{{ t('zones.panel.state') }}</dt>
                    <dd
                        class="zone-state"
                        :class="`zone-state--${selected.state}`"
                    >
                        {{ t(`zones.states.${selected.state}`) }}
                    </dd>
                    <template v-if="selected.kind === 'plant'">
                        <dt>
                            <ZoneIcon name="leaf" />{{
                                t('zones.panel.growth')
                            }}
                        </dt>
                        <dd>
                            <template v-if="selected.growth !== null">{{
                                text.percent(selected.growth)
                            }}</template>
                            <span v-else class="zone-panel__unknown">{{
                                t('zones.panel.unknown')
                            }}</span>
                        </dd>
                        <dt>
                            <ZoneIcon name="clock" />{{
                                t('zones.panel.harvest_in')
                            }}
                        </dt>
                        <dd>
                            <template v-if="selected.state === 'grown'">{{
                                t('zones.map.ready')
                            }}</template>
                            <template
                                v-else-if="
                                    selected.secondsUntilHarvest !== null
                                "
                                >{{
                                    text.duration(selected.secondsUntilHarvest)
                                }}</template
                            >
                            <span v-else class="zone-panel__unknown">{{
                                t('zones.panel.unknown')
                            }}</span>
                        </dd>
                        <dt>{{ t('zones.panel.auto_harvest') }}</dt>
                        <dd>
                            <template v-if="selected.autoHarvest !== null">{{
                                t(
                                    selected.autoHarvest
                                        ? 'zones.panel.yes'
                                        : 'zones.panel.no',
                                )
                            }}</template>
                            <span v-else class="zone-panel__unknown">{{
                                t('zones.panel.unknown')
                            }}</span>
                        </dd>
                    </template>
                </dl>
                <ul
                    v-if="selected.problems.length > 0"
                    class="zone-selected__problems"
                >
                    <li v-for="problem in selected.problems" :key="problem">
                        <ZoneIcon name="alert" :size="13" />{{ problem }}
                    </li>
                </ul>
            </template>
        </section>
        <!-- endregion -->
    </aside>
</template>

<style scoped>
.zone-panel {
    display: flex;
    flex-direction: column;
    gap: 22px;
    min-width: 0;
}

.zone-panel__heading {
    margin-bottom: 8px;
    color: var(--text-3);
    font-size: 11.5px;
    font-weight: 600;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}

.zone-panel__muted {
    color: var(--text-3);
    font-size: 13px;
}

.zone-panel__unknown {
    color: var(--text-3);
    font-style: italic;
}

/* region Verdict */
.zone-hero {
    --state: var(--ok);
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 14px;
    align-items: center;
    padding: 12px;
    border-radius: 8px;
    background: linear-gradient(
        180deg,
        oklch(26% 0.04 150 / 0.55),
        var(--ink-1)
    );
}

.zone-hero--issues {
    --state: var(--warn);
}

.zone-hero--partial {
    --state: var(--text);
}

.zone-hero--unknown {
    --state: var(--text-2);
    background: var(--ink-1);
}

.zone-hero__pic {
    position: relative;
    display: grid;
    place-items: center;
    width: 64px;
    height: 64px;
    border-radius: 8px;
    background: oklch(30% 0.05 150 / 0.6);
}

.zone-hero__badge {
    position: absolute;
    right: -7px;
    bottom: -6px;
    display: grid;
    place-items: center;
    width: 26px;
    height: 26px;
    border: 1.5px solid var(--state);
    border-radius: 50%;
    background: var(--ink-1);
    color: var(--state);
}

.zone-hero__verdict {
    color: var(--state);
    font-family: var(--font-figure);
    font-size: 18px;
    font-weight: 700;
    line-height: 1.2;
}

.zone-hero__stopped {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 2px;
    color: var(--warn);
    font-size: 13px;
    font-weight: 600;
}

.zone-hero__partial {
    margin-top: 4px;
    color: var(--text-2);
    font-size: 12.5px;
}
/* endregion */

/* region Issues */
.zone-issues {
    display: grid;
    gap: 8px;
}

.zone-issue {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 10px;
    align-items: center;
    padding: 8px 10px;
    border-left: 3px solid var(--bad);
    border-radius: 6px;
    background: oklch(68% 0.17 25 / 0.14);
}

.zone-issue__thumb {
    position: relative;
    display: grid;
    width: 38px;
    height: 38px;
}

.zone-issue__need {
    position: absolute;
    right: -6px;
    bottom: -4px;
    width: 22px;
    height: 22px;
    padding: 2px;
    border: 1px solid var(--bad);
    border-radius: 50%;
    background: var(--ink-1);
    object-fit: contain;
}

.zone-issue__name {
    display: block;
    color: oklch(88% 0.07 25);
    font-weight: 600;
}

.zone-issue__problem {
    display: block;
    color: var(--text-2);
    font-size: 12.5px;
}

.zone-issue__show {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    min-height: 28px;
    padding: 2px 8px;
    border-radius: 6px;
    color: var(--color-oni-amber);
    font-size: 12.5px;
}

.zone-issue__show:hover,
.zone-issue__show:focus-visible {
    background: oklch(72% 0.145 52 / 0.16);
}
/* endregion */

/* region Key values */
.zone-kv {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 5px 10px;
}

.zone-kv dt {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text-2);
}

.zone-kv dd {
    text-align: right;
}

.zone-state {
    font-weight: 600;
}

.zone-state--growing,
.zone-state--operational {
    color: var(--ok);
}

.zone-state--grown {
    color: var(--ration);
}

.zone-state--wilting {
    color: var(--warn);
}

.zone-state--not_operational {
    color: var(--bad);
}

.zone-selected {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.zone-selected__name {
    font-weight: 600;
}

.zone-selected__problems {
    display: grid;
    gap: 4px;
    margin-top: 8px;
    color: var(--bad);
    font-size: 13px;
}

.zone-selected__problems li {
    display: flex;
    align-items: center;
    gap: 6px;
}
/* endregion */
</style>
