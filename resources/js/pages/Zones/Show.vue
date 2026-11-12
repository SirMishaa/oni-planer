<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ZoneController from '@/actions/App/Http/Controllers/ZoneController';
import GameSprite from '@/components/viability/GameSprite.vue';
import ZoneEntities from '@/components/zones/ZoneEntities.vue';
import ZoneIcon from '@/components/zones/ZoneIcon.vue';
import ZoneMap from '@/components/zones/ZoneMap.vue';
import type { ZoneMapLayer } from '@/components/zones/zoneMapPainter';
import ZonePanel from '@/components/zones/ZonePanel.vue';
import { useT } from '@/composables/useT';
import { latestTime, useZoneLive } from '@/composables/useZoneLive';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ZoneShowData } from '@/types/App/Data/data.d';

defineOptions({ layout: AppLayout });

// region Props

/** The fields of `ZoneShowData`, passed at the top level (spelled out: the SFC compiler cannot resolve an imported props type). */
const props = defineProps<{
    zone: ZoneShowData['zone'];
    map: ZoneShowData['map'];
    entities: ZoneShowData['entities'];
    problems: ZoneShowData['problems'];
    counts: ZoneShowData['counts'];
    plantsKnown: boolean;
    buildingStatesKnown: boolean;
    echo: ZoneShowData['echo'];
}>();

// endregion

// region Live link

const { live, ageLabel } = useZoneLive(
    () => props.zone.guid,
    [
        'zone',
        'map',
        'entities',
        'problems',
        'counts',
        'plantsKnown',
        'buildingStatesKnown',
    ],
    () =>
        latestTime([
            props.zone.layoutAt,
            props.zone.slowStateAt,
            props.zone.fastStateAt,
        ]),
);

// endregion

// region Header

const { t, tc } = useT();

/** Sprite of the zone's most common plant: the crest of a farm. */
const crest = computed(() => {
    const counts = new Map<string, { sprite: string | null; count: number }>();

    for (const entity of props.entities) {
        if (entity.kind === 'plant') {
            const entry = counts.get(entity.prefabId) ?? {
                sprite: entity.sprite,
                count: 0,
            };
            entry.count++;
            counts.set(entity.prefabId, entry);
        }
    }

    return (
        [...counts.values()].sort((a, b) => b.count - a.count)[0]?.sprite ??
        null
    );
});

const known = computed(() => props.plantsKnown || props.buildingStatesKnown);

// endregion

// region Tabs, layers and selection

type Tab = 'map' | 'entities';

const tab = ref<Tab>('map');
const layer = ref<ZoneMapLayer>(
    (props.map?.plants?.length ?? 0) > 0 ? 'growth' : 'materials',
);
const selectedId = ref<string | null>(null);

const LAYERS: {
    key: ZoneMapLayer;
    icon: 'leaf' | 'cube' | 'temperature' | 'alert';
}[] = [
    { key: 'growth', icon: 'leaf' },
    { key: 'materials', icon: 'cube' },
    { key: 'temperature', icon: 'temperature' },
    { key: 'problems', icon: 'alert' },
];

/** The selection survives reloads while the entity is still there. */
const selected = computed(
    () =>
        props.entities.find(
            (entity) => entity.instanceId === selectedId.value,
        ) ?? null,
);

function showOnMap(instanceId: string): void {
    selectedId.value = instanceId;
    tab.value = 'map';
}

// endregion
</script>

<template>
    <div class="colony min-h-full pb-16 md:pb-0">
        <div class="mx-auto max-w-[1320px] px-4 pt-6 pb-20 md:px-9">
            <!-- region Header -->
            <header class="zone-header">
                <Link
                    :href="ZoneController.index.url()"
                    class="zone-header__back"
                >
                    {{ t('zones.show.back') }}
                </Link>
                <div class="zone-header__row">
                    <span class="zone-header__crest">
                        <GameSprite :src="crest" kind="plant" :size="30" />
                    </span>
                    <h1 class="zone-header__title">{{ zone.name }}</h1>
                    <span class="zone-header__meta">
                        {{ tc('zones.index.cells', zone.cellCount) }} ·
                        {{ t('zones.show.world', { id: zone.worldId }) }}
                    </span>
                    <span
                        class="zone-live"
                        :class="{ 'zone-live--on': live }"
                        role="status"
                    >
                        <span class="zone-live__dot" aria-hidden="true" />
                        {{ ageLabel }}
                    </span>
                </div>
            </header>

            <p v-if="zone.orphaned" class="zone-banner" role="status">
                {{ t('zones.show.orphaned') }}
            </p>
            <!-- endregion -->

            <!-- region Tabs -->
            <div class="zone-tabs" role="tablist">
                <button
                    id="zone-tab-map"
                    type="button"
                    role="tab"
                    class="zone-tab"
                    :aria-selected="tab === 'map'"
                    aria-controls="zone-panel-map"
                    @click="tab = 'map'"
                >
                    <ZoneIcon name="map" />{{ t('zones.show.tabs.map') }}
                </button>
                <button
                    id="zone-tab-entities"
                    type="button"
                    role="tab"
                    class="zone-tab"
                    :aria-selected="tab === 'entities'"
                    aria-controls="zone-panel-entities"
                    @click="tab = 'entities'"
                >
                    <ZoneIcon name="list" />{{ t('zones.show.tabs.entities') }}
                    <em>{{ entities.length }}</em>
                    <em
                        v-if="known && counts.problems > 0"
                        class="zone-tab__bad"
                        >· {{ tc('zones.index.problems', counts.problems) }}</em
                    >
                </button>
            </div>
            <!-- endregion -->

            <!-- region Map tab -->
            <section
                v-if="tab === 'map'"
                id="zone-panel-map"
                role="tabpanel"
                aria-labelledby="zone-tab-map"
            >
                <div v-if="map" class="zone-layers" role="group">
                    <span class="zone-layers__label">
                        <ZoneIcon name="layers" />{{ t('zones.layers.label') }}
                    </span>
                    <button
                        v-for="item in LAYERS"
                        :key="item.key"
                        type="button"
                        class="zone-layer"
                        :aria-pressed="layer === item.key"
                        @click="layer = item.key"
                    >
                        <ZoneIcon :name="item.icon" />{{
                            t(`zones.layers.${item.key}`)
                        }}
                    </button>
                </div>

                <div class="zone-body">
                    <ZoneMap
                        v-if="map"
                        :map="map"
                        :entities="entities"
                        :layer="layer"
                        :selected-id="selectedId"
                        :zone-name="zone.name"
                        @select="selectedId = $event"
                    />
                    <p v-else class="zone-empty">
                        <ZoneIcon name="map" :size="28" />
                        {{ t('zones.show.no_layout') }}
                    </p>

                    <ZonePanel
                        :entities="entities"
                        :problems="problems"
                        :counts="counts"
                        :cell-count="zone.cellCount"
                        :plants-known="plantsKnown"
                        :building-states-known="buildingStatesKnown"
                        :crest="crest"
                        :selected="selected"
                        @select="selectedId = $event"
                    />
                </div>
            </section>
            <!-- endregion -->

            <!-- region Entities tab -->
            <section
                v-else
                id="zone-panel-entities"
                role="tabpanel"
                aria-labelledby="zone-tab-entities"
            >
                <ZoneEntities
                    :entities="entities"
                    :plants-known="plantsKnown"
                    :building-states-known="buildingStatesKnown"
                    :selected-id="selectedId"
                    @select="showOnMap"
                />
            </section>
            <!-- endregion -->
        </div>
    </div>
</template>

<style scoped>
/* region Header */
.zone-header__back {
    color: var(--text-3);
    font-size: 13px;
    text-decoration: none;
}

.zone-header__back:hover {
    color: var(--text);
    text-decoration: underline;
    text-underline-offset: 3px;
}

.zone-header__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 14px;
    margin-top: 6px;
}

.zone-header__crest {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: oklch(30% 0.05 150 / 0.6);
}

.zone-header__title {
    font-family: var(--font-figure);
    font-size: 27px;
    font-weight: 700;
    line-height: 1.15;
}

.zone-header__meta {
    color: var(--text-3);
    font-size: 13.5px;
}

.zone-live {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-left: auto;
    padding: 5px 12px;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text-2);
    font-size: 13.5px;
}

.zone-live__dot {
    width: 8px;
    height: 8px;
    border: 1.5px solid var(--text-3);
    border-radius: 50%;
}

.zone-live--on {
    color: var(--text);
}

.zone-live--on .zone-live__dot {
    border-color: var(--live);
    background: var(--live);
    box-shadow: 0 0 0 3px oklch(78% 0.15 150 / 0.2);
}

.zone-banner {
    max-width: 80ch;
    margin-top: 16px;
    padding: 10px 14px;
    border: 1px solid oklch(80% 0.14 62 / 0.55);
    border-radius: 8px;
    background: oklch(80% 0.14 62 / 0.1);
    font-size: 14px;
}
/* endregion */

/* region Tabs and layers */
.zone-tabs {
    display: flex;
    gap: 2px;
    margin-top: 18px;
    border-bottom: 1px solid var(--line);
}

.zone-tab {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    min-height: 40px;
    padding: 8px 14px;
    border-bottom: 2px solid transparent;
    color: var(--text-2);
    font-size: 14px;
}

.zone-tab:hover {
    color: var(--text);
}

.zone-tab[aria-selected='true'] {
    border-color: var(--color-oni-amber);
    color: var(--text);
}

.zone-tab em {
    color: var(--text-3);
    font-style: normal;
}

.zone-tab .zone-tab__bad {
    color: var(--bad);
}

.zone-layers {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    padding-block: 10px;
}

.zone-layers__label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-right: 6px;
    color: var(--text-3);
    font-size: 11.5px;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.zone-layer {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 32px;
    padding: 4px 11px;
    border-radius: 6px;
    background: var(--ink-2);
    color: var(--text-2);
    font-size: 13px;
}

.zone-layer:hover {
    color: var(--text);
}

.zone-layer[aria-pressed='true'] {
    background: oklch(72% 0.145 52 / 0.22);
    color: var(--color-oni-amber);
}
/* endregion */

/* region Body */
.zone-body {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 24px;
    align-items: start;
    margin-top: 4px;
}

@media (min-width: 1024px) {
    .zone-body {
        grid-template-columns: minmax(0, 1fr) 310px;
    }
}

.zone-empty {
    display: grid;
    gap: 10px;
    place-items: center;
    padding: 56px 20px;
    border: 1px dashed var(--line);
    border-radius: 8px;
    color: var(--text-2);
    text-align: center;
}
/* endregion */
</style>
