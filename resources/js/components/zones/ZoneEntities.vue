<script setup lang="ts">
import { computed, ref } from 'vue';
import GameSprite from '@/components/viability/GameSprite.vue';
import ZoneIcon from '@/components/zones/ZoneIcon.vue';
import { useT } from '@/composables/useT';
import { useZoneText } from '@/composables/useZoneText';
import type { ZoneEntityData } from '@/types/App/Data/data.d';

// region Props

const props = defineProps<{
    /** Problems first, then by time to harvest (the server's order). */
    entities: ZoneEntityData[];
    plantsKnown: boolean;
    buildingStatesKnown: boolean;
    selectedId: string | null;
}>();

const emit = defineEmits<{
    select: [instanceId: string];
}>();

// endregion

// region Filters

type Filter = 'all' | 'problems' | 'plants' | 'buildings';

const { t } = useT();
const text = useZoneText();
const filter = ref<Filter>('all');

const withProblems = computed(() =>
    props.entities.filter((entity) => entity.problems.length > 0),
);
const plants = computed(() =>
    props.entities.filter((entity) => entity.kind === 'plant'),
);
const buildings = computed(() =>
    props.entities.filter((entity) => entity.kind === 'building'),
);

const filters = computed<
    { key: Filter; icon: 'list' | 'alert' | 'leaf' | 'cube'; count: number }[]
>(() => [
    { key: 'all', icon: 'list', count: props.entities.length },
    { key: 'problems', icon: 'alert', count: withProblems.value.length },
    { key: 'plants', icon: 'leaf', count: plants.value.length },
    { key: 'buildings', icon: 'cube', count: buildings.value.length },
]);

/** Row groups of the filter; `all` splits problems out, then plants, then buildings. */
const groups = computed(() => {
    const healthy = (list: ZoneEntityData[]) =>
        list.filter((entity) => entity.problems.length === 0);

    const all: { key: Filter; rows: ZoneEntityData[] }[] = {
        all: [
            { key: 'problems' as const, rows: withProblems.value },
            { key: 'plants' as const, rows: healthy(plants.value) },
            { key: 'buildings' as const, rows: healthy(buildings.value) },
        ],
        problems: [{ key: 'problems' as const, rows: withProblems.value }],
        plants: [{ key: 'plants' as const, rows: plants.value }],
        buildings: [{ key: 'buildings' as const, rows: buildings.value }],
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
    if (entity.instanceId !== null) {
        emit('select', entity.instanceId);
    }
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
        <!-- endregion -->

        <!-- region Table -->
        <div class="zone-entities__scroll">
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
                                :disabled="entity.instanceId === null"
                                :aria-label="
                                    t('zones.entities.show_on_map', {
                                        name: entity.name,
                                    })
                                "
                                @click.stop="select(entity)"
                            >
                                <GameSprite
                                    :src="entity.sprite"
                                    :kind="entity.kind"
                                    :size="24"
                                />
                                <span>{{ entity.name }}</span>
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
            <p v-if="groups.length === 0" class="zone-entities__none">
                {{ t('zones.entities.none') }}
            </p>
        </div>
        <!-- endregion -->
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
