<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import GameSprite from '@/components/viability/GameSprite.vue';
import { buildingSettingsLine } from '@/components/zones/zoneBuildingSettings';
import ZoneEggTimeline from '@/components/zones/ZoneEggTimeline.vue';
import {
    bandId,
    entitySpriteKind,
    groupEggs,
} from '@/components/zones/zoneFlock';
import ZoneFlock from '@/components/zones/ZoneFlock.vue';
import ZoneIcon from '@/components/zones/ZoneIcon.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import { useZoneText } from '@/composables/useZoneText';
import type {
    ZoneCritterData,
    ZoneEggData,
    ZoneEntityData,
    ZoneFeedingData,
    ZonePopulationData,
} from '@/types/App/Data/data.d';

// region Props

const props = defineProps<{
    /** Problems first, then by time to harvest (the server's order). */
    entities: ZoneEntityData[];
    /** What needs fixing: the entities with a problem, then the critter alerts (crowded regions, flock bands, single critters). */
    problems: ZoneEntityData[];
    /** The flock band to open (from a problem elsewhere on the page); the page clears it through `focused`. */
    focusGroup?: string | null;
    /** Doors, ladders and stairs on the zone or its border ring: shown apart, never a problem. */
    borderEntities: ZoneEntityData[];
    plantsKnown: boolean;
    buildingStatesKnown: boolean;
    /** Null until the game sent them (unknown, never "none"). */
    critters: ZoneCritterData[] | null;
    eggs: ZoneEggData[] | null;
    population: ZonePopulationData[] | null;
    /** What each flock band eats and how long its stock lasts (derived); null until the game sent the critters. */
    feeding: ZoneFeedingData[] | null;
    selectedId: string | null;
}>();

const emit = defineEmits<{
    /** A plant or building row, or a critter of the flock: show it on the map. */
    select: [instanceId: string];
    /** A critter problem: open its flock band (and select the critter, if it is one). */
    flock: [groupKey: string, instanceId: string | null];
    /** The requested flock band was opened. */
    focused: [];
}>();

// endregion

// region Filters

type Filter = 'all' | 'problems' | 'plants' | 'buildings' | 'critters' | 'eggs';

const { t } = useT();
const text = useZoneText();
const format = useFormat();
const filter = ref<Filter>('all');

const withProblems = computed(() => props.problems);
const plants = computed(() =>
    props.entities.filter((entity) => entity.kind === 'plant'),
);
const buildings = computed(() =>
    props.entities.filter((entity) => entity.kind === 'building'),
);

const critterCount = computed(() => props.critters?.length ?? 0);
const eggGroups = computed(() => groupEggs(props.eggs ?? []));

const filters = computed<
    {
        key: Filter;
        icon: 'list' | 'alert' | 'leaf' | 'cube' | 'paw' | 'egg';
        count: number;
    }[]
>(() => [
    {
        key: 'all',
        icon: 'list',
        count:
            props.entities.length +
            props.borderEntities.length +
            critterCount.value +
            (props.eggs?.length ?? 0),
    },
    { key: 'problems', icon: 'alert', count: withProblems.value.length },
    { key: 'plants', icon: 'leaf', count: plants.value.length },
    {
        key: 'buildings',
        icon: 'cube',
        count: buildings.value.length + props.borderEntities.length,
    },
    { key: 'critters', icon: 'paw', count: critterCount.value },
    { key: 'eggs', icon: 'egg', count: props.eggs?.length ?? 0 },
]);

const showsCritters = computed(
    () => filter.value === 'all' || filter.value === 'critters',
);
const showsEggs = computed(
    () => filter.value === 'all' || filter.value === 'eggs',
);

/** Row groups of the filter; `all` splits problems out, then plants, then buildings, then the border. */
const groups = computed(() => {
    const healthy = (list: ZoneEntityData[]) =>
        list.filter((entity) => entity.problems.length === 0);

    const border = { key: 'border' as const, rows: props.borderEntities };
    const all: { key: Filter | 'border'; rows: ZoneEntityData[] }[] = {
        all: [
            { key: 'problems' as const, rows: withProblems.value },
            { key: 'plants' as const, rows: healthy(plants.value) },
            { key: 'buildings' as const, rows: healthy(buildings.value) },
            border,
        ],
        problems: [{ key: 'problems' as const, rows: withProblems.value }],
        plants: [{ key: 'plants' as const, rows: plants.value }],
        buildings: [
            { key: 'buildings' as const, rows: buildings.value },
            border,
        ],
        critters: [],
        eggs: [],
    }[filter.value];

    return all.filter((group) => group.rows.length > 0);
});

// endregion

// region Rows

function stateTone(entity: ZoneEntityData): 'ok' | 'warn' | 'bad' | 'ready' {
    if (entity.problems.length > 0 || entity.state === 'not_operational') {
        return 'bad';
    }

    if (entity.state === 'wilting') {
        return 'warn';
    }

    return entity.state === 'grown' ? 'ready' : 'ok';
}

function select(entity: ZoneEntityData): void {
    if (entity.groupKey !== null) {
        emit(
            'flock',
            entity.groupKey,
            entity.kind === 'critter' ? entity.instanceId : null,
        );

        return;
    }

    if (entity.kind === 'critter_group') {
        filter.value = 'critters';

        return;
    }

    if (entity.instanceId !== null) {
        emit('select', entity.instanceId);
    }
}

/** A critter problem opens the flock on the band it belongs to. */
function openBand(groupKey: string): void {
    filter.value = 'critters';
    void nextTick(() => {
        document
            .getElementById(bandId(groupKey))
            ?.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });
}

watch(
    () => props.focusGroup ?? null,
    (groupKey) => {
        if (groupKey !== null) {
            openBand(groupKey);
            emit('focused');
        }
    },
    { immediate: true },
);

/** Whether the row leads somewhere: the map for a plant or building, the flock for a critter problem. */
function isActionable(entity: ZoneEntityData): boolean {
    return entity.instanceId !== null || entity.groupKey !== null;
}

/** The filter and capacity line of a building row; null for anything else or when the game sent none. */
function settingsLine(
    entity: ZoneEntityData,
): { text: string; title: string | null } | null {
    return entity.kind === 'building'
        ? buildingSettingsLine(entity.settings, {
              t,
              number: format.number,
          })
        : null;
}

// endregion
</script>

<template>
    <div class="zone-entities">
        <!-- region Filters -->
        <div class="zone-filters" role="group">
            <button
                v-for="item in filters"
                :key="item.key"
                type="button"
                class="zone-filter"
                :class="{
                    'zone-filter--on': filter === item.key,
                    'zone-filter--bad':
                        item.key === 'problems' && item.count > 0,
                }"
                :data-test="`zone-filter-${item.key}`"
                :aria-pressed="filter === item.key"
                @click="filter = item.key"
            >
                <ZoneIcon :name="item.icon" />
                {{ t(`zones.entities.filters.${item.key}`) }}
                <b>{{ item.count }}</b>
            </button>
        </div>

        <p v-if="!plantsKnown" class="zone-entities__unknown">
            <ZoneIcon name="question" />{{ t('zones.entities.plants_unknown') }}
        </p>
        <p v-if="!buildingStatesKnown" class="zone-entities__unknown">
            <ZoneIcon name="question" />{{
                t('zones.entities.buildings_unknown')
            }}
        </p>
        <p
            v-if="critters === null && showsCritters"
            class="zone-entities__unknown"
        >
            <ZoneIcon name="question" />{{
                t('zones.entities.critters_unknown')
            }}
        </p>
        <p v-if="eggs === null && showsEggs" class="zone-entities__unknown">
            <ZoneIcon name="question" />{{ t('zones.entities.eggs_unknown') }}
        </p>
        <!-- endregion -->

        <!-- region Table -->
        <div v-if="groups.length > 0" class="zone-entities__scroll">
            <table class="zone-table">
                <thead>
                    <tr>
                        <th>{{ t('zones.entities.columns.entity') }}</th>
                        <th>{{ t('zones.entities.columns.position') }}</th>
                        <th>{{ t('zones.entities.columns.state') }}</th>
                        <th>{{ t('zones.entities.columns.growth') }}</th>
                        <th class="zone-table__r">
                            {{ t('zones.entities.columns.harvest_in') }}
                        </th>
                        <th>{{ t('zones.entities.columns.auto_harvest') }}</th>
                    </tr>
                </thead>
                <tbody v-for="group in groups" :key="group.key">
                    <tr class="zone-table__group">
                        <th colspan="6" scope="colgroup">
                            {{ t(`zones.entities.groups.${group.key}`) }} ·
                            {{ group.rows.length }}
                        </th>
                    </tr>
                    <tr
                        v-for="(entity, index) in group.rows"
                        :key="entity.instanceId ?? `${group.key}-${index}`"
                        class="zone-table__row"
                        :class="{
                            'zone-table__row--selected':
                                entity.instanceId !== null &&
                                entity.instanceId === selectedId,
                        }"
                        @click="select(entity)"
                    >
                        <td>
                            <button
                                type="button"
                                class="zone-table__entity"
                                :disabled="!isActionable(entity)"
                                :aria-label="
                                    t(
                                        entity.kind === 'plant' ||
                                            entity.kind === 'building'
                                            ? 'zones.entities.show_on_map'
                                            : 'zones.entities.show_in_flock',
                                        { name: entity.name },
                                    )
                                "
                                @click.stop="select(entity)"
                            >
                                <GameSprite
                                    :src="entity.sprite"
                                    :kind="entitySpriteKind(entity.kind)"
                                    :size="24"
                                />
                                <span class="zone-table__name">
                                    {{ entity.name }}
                                    <small
                                        v-if="settingsLine(entity)"
                                        class="zone-table__settings"
                                        :title="
                                            settingsLine(entity)?.title ??
                                            undefined
                                        "
                                        data-test="zone-building-settings"
                                        >{{ settingsLine(entity)?.text }}</small
                                    >
                                </span>
                            </button>
                        </td>
                        <td class="zone-table__muted">
                            {{ text.position(entity.x, entity.y) }}
                        </td>
                        <td>
                            <span
                                class="zone-status"
                                :class="`zone-status--${stateTone(entity)}`"
                            >
                                {{
                                    entity.problems.length > 0
                                        ? entity.problems.join(', ')
                                        : t(`zones.states.${entity.state}`)
                                }}
                            </span>
                            <img
                                v-if="entity.neededItemSprite"
                                :src="entity.neededItemSprite"
                                alt=""
                                class="zone-table__need"
                            />
                        </td>
                        <td>
                            <template
                                v-if="
                                    entity.kind === 'plant' &&
                                    entity.growth !== null
                                "
                            >
                                <span class="zone-mini" aria-hidden="true"
                                    ><i
                                        :style="{
                                            width: `${entity.growth * 100}%`,
                                        }"
                                /></span>
                                {{ text.percent(entity.growth) }}
                            </template>
                            <span v-else class="zone-table__muted">{{
                                entity.kind === 'plant'
                                    ? t('zones.panel.unknown')
                                    : t('zones.entities.not_applicable')
                            }}</span>
                        </td>
                        <td class="zone-table__r">
                            <template v-if="entity.kind !== 'plant'">
                                <span class="zone-table__muted">{{
                                    t('zones.entities.not_applicable')
                                }}</span>
                            </template>
                            <template v-else-if="entity.state === 'grown'">{{
                                t('zones.map.ready')
                            }}</template>
                            <template
                                v-else-if="entity.secondsUntilHarvest !== null"
                                >{{
                                    text.duration(entity.secondsUntilHarvest)
                                }}</template
                            >
                            <span v-else class="zone-table__muted">{{
                                t('zones.panel.unknown')
                            }}</span>
                        </td>
                        <td>
                            <span
                                v-if="entity.autoHarvest === null"
                                class="zone-table__muted"
                                >{{
                                    entity.kind === 'plant'
                                        ? t('zones.panel.unknown')
                                        : t('zones.entities.not_applicable')
                                }}</span
                            >
                            <template v-else>{{
                                t(
                                    entity.autoHarvest
                                        ? 'zones.panel.yes'
                                        : 'zones.panel.no',
                                )
                            }}</template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <!-- endregion -->

        <!-- region Flock -->
        <section
            v-if="showsCritters && critterCount > 0"
            class="zone-entities__section"
        >
            <h2 class="zone-entities__heading">
                {{ t('zones.entities.groups.critters') }} · {{ critterCount }}
            </h2>
            <ZoneFlock
                :critters="critters ?? []"
                :population="population"
                :feeding="feeding"
                :selected-id="selectedId"
                @select="emit('select', $event)"
            />
        </section>
        <!-- endregion -->

        <!-- region Eggs -->
        <section
            v-if="showsEggs && eggGroups.length > 0"
            class="zone-entities__section"
        >
            <h2 class="zone-entities__heading">
                {{ t('zones.entities.groups.eggs') }} · {{ eggs?.length ?? 0 }}
            </h2>
            <ZoneEggTimeline
                v-for="group in eggGroups"
                :key="group.key"
                :group="group"
            />
        </section>
        <!-- endregion -->

        <p
            v-if="
                groups.length === 0 &&
                !(showsCritters && critterCount > 0) &&
                !(showsEggs && eggGroups.length > 0)
            "
            class="zone-entities__none"
        >
            {{ t('zones.entities.none') }}
        </p>
    </div>
</template>

<style scoped>
.zone-entities {
    min-width: 0;
}

/* region Filters */
.zone-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding-block: 12px;
}

.zone-filter {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 32px;
    padding: 4px 12px;
    border: 1px solid transparent;
    border-radius: 99px;
    background: var(--ink-2);
    color: var(--text-2);
    font-size: 13px;
}

.zone-filter b {
    color: var(--text);
    font-weight: 600;
}

.zone-filter--bad b {
    color: var(--bad);
}

.zone-filter:hover {
    color: var(--text);
}

.zone-filter--on {
    border-color: var(--line);
    background: var(--ink-3);
    color: var(--text);
}

.zone-entities__unknown {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    color: var(--text-2);
    font-size: 13px;
}
/* endregion */

/* region Sections */
.zone-entities__section {
    margin-top: 18px;
    padding: 12px 16px 4px;
    border: 1px solid var(--line);
    border-radius: 8px;
}

.zone-entities__heading {
    margin-bottom: 10px;
    color: var(--text-2);
    font-size: 12.5px;
    font-weight: 600;
}

.zone-entities__section :deep(.zone-eggs:first-of-type) {
    padding-top: 4px;
    border-top: 0;
}
/* endregion */

/* region Table */
.zone-entities__scroll {
    overflow-x: auto;
    border: 1px solid var(--line);
    border-radius: 8px;
}

.zone-table {
    width: 100%;
    min-width: 680px;
    border-collapse: collapse;
}

.zone-table th {
    padding: 8px 10px;
    border-bottom: 1px solid var(--line);
    background: var(--ink-1);
    color: var(--text-3);
    font-size: 11.5px;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-align: left;
    text-transform: uppercase;
}

.zone-table td {
    padding: 6px 10px;
    border-bottom: 1px solid oklch(30% 0.03 222 / 0.6);
    font-size: 14px;
}

.zone-table .zone-table__r {
    text-align: right;
}

.zone-table__group th {
    padding-top: 10px;
    color: var(--text-2);
    font-size: 12.5px;
    letter-spacing: 0;
    text-transform: none;
}

.zone-table__row {
    cursor: pointer;
}

.zone-table__row:hover td {
    background: var(--ink-1);
}

.zone-table__row--selected td {
    background: oklch(72% 0.145 52 / 0.12);
}

.zone-table__entity {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 32px;
    text-align: left;
}

.zone-table__entity:focus-visible {
    outline: 2px solid var(--color-oni-amber);
    outline-offset: 2px;
    border-radius: 4px;
}

.zone-table__name {
    display: flex;
    flex-direction: column;
}

.zone-table__settings {
    color: var(--text-3);
    font-size: 12px;
}

.zone-table__muted {
    color: var(--text-3);
}

.zone-table__need {
    display: inline-block;
    width: 18px;
    height: 18px;
    margin-left: 6px;
    object-fit: contain;
    vertical-align: middle;
}

.zone-entities__none {
    padding: 14px;
    color: var(--text-3);
}

.zone-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
}

.zone-status::before {
    width: 7px;
    height: 7px;
    flex: none;
    border-radius: 50%;
    background: currentColor;
    content: '';
}

.zone-status--ok {
    color: var(--ok);
}

.zone-status--ready {
    color: var(--ration);
}

.zone-status--warn {
    color: var(--warn);
}

.zone-status--bad {
    color: var(--bad);
}

.zone-mini {
    display: inline-block;
    width: 70px;
    height: 5px;
    margin-right: 8px;
    overflow: hidden;
    border-radius: 3px;
    background: var(--ink-3);
    vertical-align: middle;
}

.zone-mini i {
    display: block;
    height: 100%;
    background: var(--ok);
}
/* endregion */
</style>
