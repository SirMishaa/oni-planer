<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ZoneController from '@/actions/App/Http/Controllers/ZoneController';
import GameSprite from '@/components/viability/GameSprite.vue';
import ZoneCaptures from '@/components/zones/ZoneCaptures.vue';
import ZoneCaptureView from '@/components/zones/ZoneCaptureView.vue';
import ZoneEntities from '@/components/zones/ZoneEntities.vue';
import ZoneIcon from '@/components/zones/ZoneIcon.vue';
import ZoneMap from '@/components/zones/ZoneMap.vue';
import type { ZoneMapLayer } from '@/components/zones/zoneMapPainter';
import ZonePanel from '@/components/zones/ZonePanel.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import { useUplink } from '@/composables/useUplink';
import { latestTime, useZoneLive } from '@/composables/useZoneLive';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ZoneShowData } from '@/types/App/Data/data.d';

defineOptions({ layout: AppLayout });

// region Props

/** The fields of `ZoneShowData`, passed at the top level (spelled out: the SFC compiler cannot resolve an imported props type). */
const props = defineProps<{
    zone: ZoneShowData['zone'];
    map: ZoneShowData['map'];
    mapTerrain: ZoneShowData['mapTerrain'];
    mapPlants: ZoneShowData['mapPlants'];
    entities: ZoneShowData['entities'];
    problems: ZoneShowData['problems'];
    borderEntities: ZoneShowData['borderEntities'];
    counts: ZoneShowData['counts'];
    plantsKnown: boolean;
    buildingStatesKnown: boolean;
    captures: ZoneShowData['captures'];
    captureRequest: ZoneShowData['captureRequest'];
    linkSeenAt: ZoneShowData['linkSeenAt'];
    population: ZoneShowData['population'];
    critters: ZoneShowData['critters'];
    eggs: ZoneShowData['eggs'];
    crittersAt: ZoneShowData['crittersAt'];
    liveProps: ZoneShowData['liveProps'];
    echo: ZoneShowData['echo'];
}>();

// endregion

// region Live link

/** `gone`: a live reload found the zone archived (removed in the game); the page keeps its last state. */
const { live, ageLabel, gone } = useZoneLive({
    echo: () => props.echo,
    zoneGuid: () => props.zone.guid,
    /** Each event reloads the props of its stream only: a fast state every 5 s never refetches the terrain. */
    only: (kind) => props.liveProps[kind] ?? props.liveProps.layout ?? [],
    updatedAt: () =>
        latestTime([
            props.zone.layoutAt,
            props.zone.slowStateAt,
            props.zone.fastStateAt,
        ]),
});

/** Three missed 15 s heartbeats: one late heartbeat (seen up to 30 s apart) must not disable the capture button. */
const LINK_WINDOW_MS = 45_000;

/**
 * The game is linked: its colony state, repeated every 15 s even while paused, is fresh. Zone
 * states stop while paused (they do not change), so they cannot tell.
 */
const { live: linked } = useUplink(
    computed(() => ({
        state: 'ok' as const,
        colonyName: null,
        cycle: null,
        lastSeenAt: props.linkSeenAt,
        schema: null,
        echo: {
            channel: 'uplink' as const,
            event: '.client-colony.state' as const,
        },
    })),
    ['linkSeenAt'],
    true,
    LINK_WINDOW_MS,
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

const known = computed(
    () =>
        props.plantsKnown ||
        props.buildingStatesKnown ||
        props.counts.problems > 0,
);

// endregion

// region Tabs, layers and selection

type Tab = 'map' | 'entities' | 'captures';

const tab = ref<Tab>('map');
const layer = ref<ZoneMapLayer>(
    (props.mapPlants?.length ?? 0) > 0 ? 'growth' : 'materials',
);
const selectedId = ref<string | null>(null);
/** The flock band a critter problem asked the Entities tab to open. */
const focusGroup = ref<string | null>(null);

const LAYERS: {
    key: ZoneMapLayer;
    icon: 'leaf' | 'cube' | 'temperature' | 'alert' | 'paw';
}[] = [
    { key: 'growth', icon: 'leaf' },
    { key: 'materials', icon: 'cube' },
    { key: 'temperature', icon: 'temperature' },
    { key: 'problems', icon: 'alert' },
    { key: 'critters', icon: 'paw' },
];

/** Content and border passages: everything the map and the selection can show a state for. */
const knownEntities = computed(() => [
    ...props.entities,
    ...props.borderEntities,
]);

/** Critters with an alert of their own: the map pins them where they stand. */
const critterProblems = computed(() =>
    props.problems.filter((problem) => problem.kind === 'critter'),
);

/** The selection survives reloads while the entity is still there. */
const selected = computed(
    () =>
        knownEntities.value.find(
            (entity) => entity.instanceId === selectedId.value,
        ) ?? null,
);

/** A selected critter or egg: the panel shows its sheet. */
const selectedCritter = computed(
    () =>
        props.critters?.find(
            (critter) => critter.instanceId === selectedId.value,
        ) ?? null,
);
const selectedEgg = computed(
    () =>
        props.eggs?.find((egg) => egg.instanceId === selectedId.value) ?? null,
);

/** A building drawn from the layout whose state the game has not sent: shown with an unknown state. */
const selectedBuilding = computed(() =>
    selected.value === null && selectedId.value !== null
        ? (props.map?.buildings?.find(
              (building) => building.instanceId === selectedId.value,
          ) ?? null)
        : null,
);

// region Comparison with the game

const format = useFormat();

/** The map and the latest capture side by side (one under the other for a box wider than tall). */
const compare = ref(false);
/** The map's drawn cell size, zoom included: the capture is drawn at it. */
const mapCellPx = ref<number | null>(null);
const latestCapture = computed(() => props.captures[0] ?? null);
const comparing = computed(
    () => compare.value && props.map !== null && latestCapture.value !== null,
);
const compareStacked = computed(
    () => props.map !== null && props.map.box.width > props.map.box.height,
);
const latestCaptureLabel = computed(() =>
    latestCapture.value === null
        ? ''
        : [
              t('zones.compare.game'),
              format.age(Date.now() - Date.parse(latestCapture.value.takenAt)),
              latestCapture.value.matchesLayout
                  ? t('zones.captures.current_layout')
                  : t('zones.captures.older_layout'),
          ].join(' · '),
);

// endregion

/** A critter problem: the Entities tab, on the flock band of the problem. */
function showInFlock(groupKey: string, instanceId: string | null): void {
    if (instanceId !== null) {
        selectedId.value = instanceId;
    }

    focusGroup.value = groupKey;
    tab.value = 'entities';
}

function showOnMap(instanceId: string): void {
    selectedId.value = instanceId;
    tab.value = 'map';
}

// endregion
</script>

<template>
    <div class="colony min-h-full pb-16 md:pb-0">
        <div class="mx-auto max-w-[1760px] px-4 pt-6 pb-20 md:px-9">
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

            <p v-if="gone" class="zone-banner zone-banner--gone" role="alert">
                {{ t('zones.show.removed') }}
                <Link :href="ZoneController.index.url()">{{
                    t('zones.show.back')
                }}</Link>
            </p>
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
                    <em>{{
                        entities.length + counts.critters + counts.eggs
                    }}</em>
                    <em
                        v-if="known && counts.problems > 0"
                        class="zone-tab__bad"
                        >· {{ tc('zones.index.problems', counts.problems) }}</em
                    >
                </button>
                <button
                    id="zone-tab-captures"
                    type="button"
                    role="tab"
                    class="zone-tab"
                    :aria-selected="tab === 'captures'"
                    aria-controls="zone-panel-captures"
                    @click="tab = 'captures'"
                >
                    <ZoneIcon name="camera" />{{
                        t('zones.show.tabs.captures')
                    }}
                    <em>{{ captures.length }}</em>
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
                    <button
                        v-if="latestCapture"
                        type="button"
                        class="zone-layer zone-layer--compare"
                        :aria-pressed="compare"
                        @click="compare = !compare"
                    >
                        <ZoneIcon name="camera" />{{
                            t('zones.compare.toggle')
                        }}
                    </button>
                </div>

                <div
                    class="zone-body"
                    :class="{ 'zone-body--compare': comparing }"
                >
                    <div
                        v-if="map"
                        class="zone-compare"
                        :class="{
                            'zone-compare--on': comparing,
                            'zone-compare--stacked': compareStacked,
                        }"
                    >
                        <ZoneMap
                            :map="map"
                            :terrain="mapTerrain"
                            :plants="mapPlants"
                            :entities="knownEntities"
                            :critters="critters"
                            :critters-at="crittersAt"
                            :eggs="eggs"
                            :critter-problems="critterProblems"
                            :layer="layer"
                            :selected-id="selectedId"
                            :zone-name="zone.name"
                            @select="selectedId = $event"
                            @cell-size="mapCellPx = $event"
                        />
                        <figure
                            v-if="comparing && latestCapture"
                            class="zone-compare__game"
                        >
                            <ZoneCaptureView
                                :capture="latestCapture"
                                :box="map.box"
                                :cell-px="mapCellPx"
                                :alt="latestCaptureLabel"
                            />
                            <figcaption class="zone-compare__caption">
                                {{ latestCaptureLabel }}
                            </figcaption>
                        </figure>
                    </div>
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
                        :population="population"
                        :crest="crest"
                        :selected="selected"
                        :selected-building="selectedBuilding"
                        :selected-critter="selectedCritter"
                        :selected-egg="selectedEgg"
                        :grid-width="map?.gridWidth ?? null"
                        @select="selectedId = $event"
                        @flock="showInFlock"
                    />
                </div>
            </section>
            <!-- endregion -->

            <!-- region Entities tab -->
            <section
                v-else-if="tab === 'entities'"
                id="zone-panel-entities"
                role="tabpanel"
                aria-labelledby="zone-tab-entities"
            >
                <ZoneEntities
                    :entities="entities"
                    :problems="problems"
                    :focus-group="focusGroup"
                    :border-entities="borderEntities"
                    :plants-known="plantsKnown"
                    :building-states-known="buildingStatesKnown"
                    :critters="critters"
                    :eggs="eggs"
                    :population="population"
                    :selected-id="selectedId"
                    @select="showOnMap"
                    @flock="showInFlock"
                    @focused="focusGroup = null"
                />
            </section>
            <!-- endregion -->

            <!-- region Captures tab -->
            <section
                v-else
                id="zone-panel-captures"
                role="tabpanel"
                aria-labelledby="zone-tab-captures"
            >
                <ZoneCaptures
                    :zone-id="zone.id"
                    :box="map?.box ?? null"
                    :captures="captures"
                    :capture-request="captureRequest"
                    :live="linked"
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

.zone-banner.zone-banner--gone {
    border-color: oklch(72% 0.17 25 / 0.55);
    background: oklch(72% 0.17 25 / 0.1);
}

.zone-banner--gone a {
    margin-left: 6px;
    text-decoration: underline;
    text-underline-offset: 3px;
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

    /* Comparing: map and game share the width, the panel goes under them. */
    .zone-body--compare {
        grid-template-columns: minmax(0, 1fr);
    }

    .zone-compare--on:not(.zone-compare--stacked) {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }
}

.zone-compare {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 12px;
    min-width: 0;
}

.zone-compare__game {
    display: grid;
    gap: 4px;
    min-width: 0;
    margin: 0;
}

.zone-compare__caption {
    color: var(--text-3);
    font-size: 12px;
    text-align: center;
}

.zone-layer--compare {
    margin-left: auto;
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
