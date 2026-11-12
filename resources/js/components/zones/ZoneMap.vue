<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    watch,
} from 'vue';
import GameSprite from '@/components/viability/GameSprite.vue';
import { useT } from '@/composables/useT';
import { useZoneText } from '@/composables/useZoneText';
import type { ZoneEntityData, ZoneMapData } from '@/types/App/Data/data.d';
import {
    layerIndex,
    localToCell,
    rgbCss,
    temperatureColour,
    temperatureRange,
    zoneOutlinePath,
    cellToLocal,
} from './zoneMapGeometry';
import {
    loadTileGeometry,
    paintZoneMap,
    readInks,
    type ZoneMapInks,
    type ZoneMapLayer,
} from './zoneMapPainter';
import { entityProblemMark } from './zoneVerdict';

// region Props

const props = defineProps<{
    map: ZoneMapData;
    entities: ZoneEntityData[];
    layer: ZoneMapLayer;
    selectedId: string | null;
    zoneName: string;
}>();

const emit = defineEmits<{
    select: [instanceId: string];
}>();

// endregion

// region Sizing

/** Largest and smallest cell, in CSS pixels; below the smallest the map scrolls. */
const CELL_MAX_PX = 40;
const CELL_MIN_PX = 12;
/** Room left around the map for the entities that rise above the box. */
const STAGE_PAD_PX = 12;
/** Height of a plant on the map, in cells (most crops stand two cells high). */
const PLANT_HEIGHT_CELLS = 2;

const scroller = ref<HTMLElement | null>(null);
const availableWidth = ref(0);
let resizeObserver: ResizeObserver | undefined;

const cellPx = computed(() => {
    const fit = Math.floor(
        (availableWidth.value - STAGE_PAD_PX * 2) / props.map.box.width,
    );

    return Math.min(CELL_MAX_PX, Math.max(CELL_MIN_PX, fit || CELL_MAX_PX));
});

const stageWidth = computed(() => props.map.box.width * cellPx.value);
const stageHeight = computed(() => props.map.box.height * cellPx.value);

// endregion

// region Canvas

const root = ref<HTMLElement | null>(null);
const canvas = ref<HTMLCanvasElement | null>(null);
const inks = shallowRef<ZoneMapInks | null>(null);
const paintMs = ref<number | null>(null);
let frame = 0;

function paint(): void {
    frame = 0;

    if (!canvas.value || !inks.value) {
        return;
    }

    paintMs.value = paintZoneMap(canvas.value, {
        box: props.map.box,
        terrain: props.map.terrain,
        tiles: props.map.tiles,
        cellPx: cellPx.value,
        dpr: window.devicePixelRatio || 1,
        layer: props.layer,
        inks: inks.value,
        onSpriteLoad: schedulePaint,
    });
}

function schedulePaint(): void {
    if (frame === 0) {
        frame = requestAnimationFrame(paint);
    }
}

watch(
    () => [
        props.map.box,
        props.map.terrain,
        props.map.tiles,
        props.layer,
        cellPx.value,
    ],
    schedulePaint,
);

onMounted(() => {
    if (root.value) {
        inks.value = readInks(root.value);
    }

    if (scroller.value) {
        availableWidth.value = Math.round(scroller.value.clientWidth);
        // Resizing the canvas can resize the scroller (scrollbar): apply the
        // new width next frame, and only on a real change, so the observer
        // never loops within one frame.
        resizeObserver = new ResizeObserver(([entry]) => {
            const width = Math.round(
                entry?.contentRect.width ?? availableWidth.value,
            );

            if (width !== availableWidth.value) {
                requestAnimationFrame(() => {
                    availableWidth.value = width;
                });
            }
        });
        resizeObserver.observe(scroller.value);
    }

    loadTileGeometry(schedulePaint);
    void nextTick(schedulePaint);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    cancelAnimationFrame(frame);
});

// endregion

// region Zone outline

const outlinePath = computed(() =>
    zoneOutlinePath(props.map.zoneCells, props.map.box),
);

// endregion

// region Entities

type MapEntity = {
    key: string;
    instanceId: string | null;
    kind: 'plant' | 'building';
    name: string;
    sprite: string | null;
    x: number;
    y: number;
    width: number;
    height: number;
    growth: number | null;
    secondsUntilHarvest: number | null;
    state: ZoneEntityData['state'] | null;
    problems: string[];
};

const entitiesById = computed(
    () =>
        new Map(
            props.entities
                .filter((entity) => entity.instanceId !== null)
                .map((entity) => [entity.instanceId as string, entity]),
        ),
);

const mapEntities = computed<MapEntity[]>(() => {
    const box = props.map.box;
    const top = box.y + box.height - 1;
    const stateOf = (instanceId: string | null) =>
        instanceId === null ? undefined : entitiesById.value.get(instanceId);

    const buildings = (props.map.buildings ?? []).map(
        (building, index): MapEntity => {
            const entity = stateOf(building.instanceId);

            return {
                key: `b${building.instanceId ?? index}`,
                instanceId: building.instanceId,
                kind: 'building',
                name: building.name,
                sprite: building.sprite,
                x: building.x,
                y: building.y,
                width: building.width,
                height: building.height,
                growth: null,
                secondsUntilHarvest: null,
                state: entity?.state ?? null,
                problems: entity?.problems ?? [],
            };
        },
    );

    const plants = (props.map.plants ?? []).map((plant, index): MapEntity => {
        const entity = stateOf(plant.instanceId);

        return {
            key: `p${plant.instanceId ?? index}`,
            instanceId: plant.instanceId,
            kind: 'plant',
            name: plant.name,
            sprite: plant.sprite,
            x: plant.x,
            y: plant.y,
            width: 1,
            height: Math.max(
                1,
                Math.min(PLANT_HEIGHT_CELLS, top - plant.y + 1),
            ),
            growth: plant.growth,
            secondsUntilHarvest: plant.secondsUntilHarvest,
            state:
                entity?.state ??
                (plant.wilting ? 'wilting' : plant.grown ? 'grown' : 'growing'),
            problems: entity?.problems ?? [],
        };
    });

    return [...buildings, ...plants].filter(
        (entity) => layerIndex(entity.x, entity.y, box) !== null,
    );
});

function entityStyle(entity: MapEntity): Record<string, string> {
    const { column, row } = cellToLocal(
        entity.x,
        entity.y + entity.height - 1,
        props.map.box,
    );

    return {
        left: `${column * cellPx.value}px`,
        top: `${row * cellPx.value}px`,
        width: `${entity.width * cellPx.value}px`,
        height: `${entity.height * cellPx.value}px`,
    };
}

/** Problem mark; a building the fast state does not cover is unknown, never problem-free. */
function problemMark(entity: MapEntity): 'problem' | 'clear' | 'unknown' {
    return entityProblemMark(entity.problems.length, entity.state !== null);
}

function select(entity: MapEntity): void {
    if (entity.instanceId !== null) {
        emit('select', entity.instanceId);
    }
}

// endregion

// region Hover

const { t } = useT();
const text = useZoneText();

const stage = ref<HTMLElement | null>(null);
/** The card is fixed to the viewport (clientX/Y) so the map's scroll box never clips it. */
const hover = ref<{
    clientX: number;
    clientY: number;
    x: number;
    y: number;
    entityKey: string | null;
} | null>(null);

function onPointerMove(event: PointerEvent): void {
    if (!stage.value) {
        return;
    }

    const rect = stage.value.getBoundingClientRect();
    const left = event.clientX - rect.left;
    const top = event.clientY - rect.top;
    const column = Math.floor(left / cellPx.value);
    const row = Math.floor(top / cellPx.value);

    if (
        column < 0 ||
        row < 0 ||
        column >= props.map.box.width ||
        row >= props.map.box.height
    ) {
        hover.value = null;

        return;
    }

    const target = (event.target as Element | null)?.closest<HTMLElement>(
        '[data-entity]',
    );
    const { x, y } = localToCell(column, row, props.map.box);
    hover.value = {
        clientX: event.clientX,
        clientY: event.clientY,
        x,
        y,
        entityKey: target?.dataset.entity ?? null,
    };
}

function onEntityFocus(event: FocusEvent, entity: MapEntity): void {
    const rect = (event.target as Element).getBoundingClientRect();
    hover.value = {
        clientX: rect.right,
        clientY: rect.top,
        x: entity.x,
        y: entity.y,
        entityKey: entity.key,
    };
}

const hoveredEntity = computed(() =>
    hover.value?.entityKey
        ? (mapEntities.value.find(
              (entity) => entity.key === hover.value?.entityKey,
          ) ?? null)
        : null,
);

/** Terrain under the pointer: element, mass and temperature, each possibly unknown. */
const hoveredCell = computed(() => {
    if (!hover.value) {
        return null;
    }

    const terrain = props.map.terrain;
    const index = terrain
        ? layerIndex(hover.value.x, hover.value.y, terrain.box)
        : null;
    const paletteIndex =
        index === null ? null : (terrain?.element?.[index] ?? null);
    const element =
        paletteIndex === null || paletteIndex < 0
            ? null
            : (terrain?.palette[paletteIndex] ?? null);
    const mass = index === null ? null : (terrain?.mass?.[index] ?? null);
    const temperature =
        index === null ? null : (terrain?.temperature?.[index] ?? null);

    return {
        element: element
            ? (element.name ?? element.elementId ?? t('zones.map.vacuum'))
            : null,
        mass,
        temperature,
    };
});

/** Room the card needs before it flips to the other side of the pointer. */
const HOVER_ROOM = { x: 280, y: 150 };
const HOVER_OFFSET_PX = 14;

/** Keeps the card inside the window: it flips left or up near the far edges. */
const hoverStyle = computed(() => {
    if (!hover.value) {
        return {};
    }

    const { clientX, clientY } = hover.value;
    const flipX = clientX > window.innerWidth - HOVER_ROOM.x;
    const flipY = clientY > window.innerHeight - HOVER_ROOM.y;

    return {
        left: flipX ? 'auto' : `${clientX + HOVER_OFFSET_PX}px`,
        right: flipX
            ? `${window.innerWidth - clientX + HOVER_OFFSET_PX}px`
            : 'auto',
        top: flipY ? 'auto' : `${clientY + HOVER_OFFSET_PX}px`,
        bottom: flipY
            ? `${window.innerHeight - clientY + HOVER_OFFSET_PX}px`
            : 'auto',
    };
});

function entityLabel(entity: MapEntity): string {
    const parts = [entity.name, text.position(entity.x, entity.y)];

    parts.push(
        entity.state
            ? t(`zones.states.${entity.state}`)
            : t('zones.panel.state_unknown'),
    );

    return [...parts, ...entity.problems].join(', ');
}

// endregion

// region Legend

const temperatureLegend = computed(() => {
    const range = temperatureRange(props.map.terrain?.temperature ?? null);

    if (!range) {
        return null;
    }

    const stops = [0, 0.25, 0.5, 0.75, 1]
        .map((position) => rgbCss(temperatureColour(position)))
        .join(', ');

    return {
        gradient: `linear-gradient(90deg, ${stops})`,
        min: text.celsius(range.min),
        max: text.celsius(range.max),
    };
});

// endregion
</script>

<template>
    <div ref="root" class="zone-map">
        <!-- region Stage -->
        <div ref="scroller" class="zone-map__scroll">
            <div
                ref="stage"
                class="zone-map__stage"
                :style="{
                    width: `${stageWidth}px`,
                    height: `${stageHeight}px`,
                }"
                role="group"
                :aria-label="t('zones.map.label', { name: zoneName })"
                @pointermove="onPointerMove"
                @pointerleave="hover = null"
            >
                <canvas
                    ref="canvas"
                    class="zone-map__canvas"
                    :style="{
                        width: `${stageWidth}px`,
                        height: `${stageHeight}px`,
                    }"
                    :data-paint-ms="paintMs?.toFixed(2)"
                    aria-hidden="true"
                />

                <!-- region Outline -->
                <svg
                    class="zone-map__outline"
                    :viewBox="`0 0 ${map.box.width} ${map.box.height}`"
                    :width="stageWidth"
                    :height="stageHeight"
                    preserveAspectRatio="none"
                    aria-hidden="true"
                >
                    <path :d="outlinePath" />
                </svg>
                <!-- endregion -->

                <!-- region Entities -->
                <button
                    v-for="entity in mapEntities"
                    :key="entity.key"
                    type="button"
                    class="zone-entity"
                    :class="{
                        'zone-entity--plant': entity.kind === 'plant',
                        'zone-entity--selected':
                            entity.instanceId !== null &&
                            entity.instanceId === selectedId,
                        'zone-entity--dimmed':
                            layer === 'problems' &&
                            problemMark(entity) === 'clear',
                        'zone-entity--faint': layer === 'temperature',
                    }"
                    :style="entityStyle(entity)"
                    :data-entity="entity.key"
                    :aria-label="entityLabel(entity)"
                    :aria-pressed="
                        entity.instanceId !== null &&
                        entity.instanceId === selectedId
                    "
                    @click="select(entity)"
                    @focus="onEntityFocus($event, entity)"
                    @blur="hover = null"
                >
                    <img
                        v-if="entity.sprite"
                        :src="entity.sprite"
                        alt=""
                        class="zone-entity__sprite"
                        decoding="async"
                    />
                    <GameSprite
                        v-else
                        :src="null"
                        :kind="entity.kind"
                        :size="Math.max(10, Math.round(cellPx * 0.8))"
                        class="zone-entity__placeholder"
                    />
                    <span
                        v-if="
                            layer === 'growth' &&
                            entity.kind === 'plant' &&
                            entity.growth !== null
                        "
                        class="zone-entity__bar"
                        :class="{
                            'zone-entity__bar--wilting':
                                entity.state === 'wilting',
                        }"
                        aria-hidden="true"
                    >
                        <i :style="{ width: `${entity.growth * 100}%` }" />
                    </span>
                    <span
                        v-if="problemMark(entity) === 'problem'"
                        class="zone-entity__pin"
                        aria-hidden="true"
                        >!</span
                    >
                    <span
                        v-else-if="
                            layer === 'problems' &&
                            problemMark(entity) === 'unknown'
                        "
                        class="zone-entity__pin zone-entity__pin--unknown"
                        aria-hidden="true"
                        >?</span
                    >
                </button>
                <!-- endregion -->

                <!-- region Hover card -->
                <div
                    v-if="hover"
                    class="zone-map__hover"
                    :style="hoverStyle"
                    aria-hidden="true"
                >
                    <template v-if="hoveredEntity">
                        <strong
                            >{{ hoveredEntity.name }} ·
                            {{
                                text.position(hoveredEntity.x, hoveredEntity.y)
                            }}</strong
                        >
                        <span
                            v-if="!hoveredEntity.state"
                            class="zone-map__hover-unknown"
                            >{{ t('zones.panel.state_unknown') }}</span
                        >
                        <span v-else>{{
                            t(`zones.states.${hoveredEntity.state}`)
                        }}</span>
                        <span
                            v-if="
                                hoveredEntity.growth !== null &&
                                hoveredEntity.state !== 'grown'
                            "
                        >
                            {{
                                t('zones.map.growth', {
                                    percent: Math.round(
                                        hoveredEntity.growth * 100,
                                    ),
                                })
                            }}
                        </span>
                        <span
                            v-if="
                                hoveredEntity.secondsUntilHarvest !== null &&
                                hoveredEntity.state === 'growing'
                            "
                        >
                            {{
                                t('zones.map.harvest_in', {
                                    time: text.duration(
                                        hoveredEntity.secondsUntilHarvest,
                                    ),
                                })
                            }}
                        </span>
                        <span
                            v-for="problem in hoveredEntity.problems"
                            :key="problem"
                            class="zone-map__hover-problem"
                            >! {{ problem }}</span
                        >
                    </template>
                    <template v-else-if="hoveredCell">
                        <strong>{{ text.position(hover.x, hover.y) }}</strong>
                        <span v-if="hoveredCell.element">{{
                            hoveredCell.element
                        }}</span>
                        <span v-else class="zone-map__hover-unknown">{{
                            t('zones.map.unknown_cell')
                        }}</span>
                        <span v-if="hoveredCell.mass !== null">{{
                            text.mass(hoveredCell.mass)
                        }}</span>
                        <span v-if="hoveredCell.temperature !== null">{{
                            text.celsius(hoveredCell.temperature)
                        }}</span>
                    </template>
                </div>
                <!-- endregion -->
            </div>
        </div>
        <!-- endregion -->

        <!-- region Legend -->
        <div class="zone-map__legend">
            <span class="zone-map__key">
                <i class="zone-map__swatch zone-map__swatch--edge" />
                {{ t('zones.map.zone_edge') }}
            </span>
            <span class="zone-map__key">
                <i class="zone-map__swatch zone-map__swatch--unknown" />
                {{ t('zones.layers.unknown') }}
            </span>
            <span
                v-if="layer === 'temperature' && temperatureLegend"
                class="zone-map__key"
            >
                <span>{{ temperatureLegend.min }}</span>
                <i
                    class="zone-map__scale"
                    :style="{ background: temperatureLegend.gradient }"
                />
                <span>{{ temperatureLegend.max }}</span>
            </span>
            <span v-if="layer === 'problems'" class="zone-map__key">
                <i class="zone-map__swatch zone-map__swatch--pin">!</i>
                {{ t('zones.layers.problems') }}
            </span>
            <span v-if="layer === 'problems'" class="zone-map__key">
                <i
                    class="zone-map__swatch zone-map__swatch--pin zone-map__swatch--unknown-state"
                    >?</i
                >
                {{ t('zones.panel.state_unknown') }}
            </span>
        </div>
        <p v-if="!map.terrain" class="zone-map__note">
            {{ t('zones.layers.no_terrain') }}
        </p>
        <!-- endregion -->
    </div>
</template>

<style scoped>
.zone-map {
    min-width: 0;
}

.zone-map__scroll {
    overflow: auto;
    padding: 12px;
    border-radius: 8px;
    background: var(--ink-0);
}

.zone-map__stage {
    position: relative;
    margin-inline: auto;
    margin-top: 18px;
    touch-action: manipulation;
}

.zone-map__canvas {
    position: absolute;
    inset: 0;
    border-radius: 2px;
    image-rendering: auto;
}

.zone-map__outline {
    position: absolute;
    inset: 0;
    z-index: 4;
    overflow: visible;
    pointer-events: none;
}

.zone-map__outline path {
    fill: none;
    stroke: var(--color-oni-amber);
    stroke-width: 2px;
    stroke-linecap: square;
    vector-effect: non-scaling-stroke;
}

/* region Entities */
.zone-entity {
    position: absolute;
    z-index: 2;
    display: grid;
    place-items: end start;
    padding: 0;
    border: 0;
    border-radius: 3px;
    background: none;
    cursor: pointer;
    transition:
        opacity 160ms ease-out,
        filter 160ms ease-out;
}

.zone-entity--plant {
    z-index: 3;
}

.zone-entity:focus-visible,
.zone-entity--selected {
    outline: 2px solid var(--color-oni-amber);
    outline-offset: 1px;
}

.zone-entity:hover {
    filter: brightness(1.15);
}

.zone-entity--dimmed {
    opacity: 0.3;
    filter: grayscale(1);
}

.zone-entity--faint {
    opacity: 0.55;
}

.zone-entity__sprite {
    width: 100%;
    height: 100%;
    object-fit: contain;
    object-position: left bottom;
    pointer-events: none;
}

.zone-entity__placeholder {
    margin: 0 0 2px 2px;
    pointer-events: none;
}

.zone-entity__bar {
    position: absolute;
    right: 3px;
    bottom: 2px;
    left: 3px;
    height: 4px;
    overflow: hidden;
    border-radius: 2px;
    background: oklch(0% 0 0 / 0.55);
}

.zone-entity__bar i {
    display: block;
    height: 100%;
    border-radius: 2px;
    background: var(--ok);
}

.zone-entity__bar--wilting i {
    background: var(--warn);
}

.zone-entity__pin.zone-entity__pin--unknown {
    background: var(--ink-3);
    color: var(--text);
}

.zone-entity__pin {
    position: absolute;
    top: -7px;
    right: -7px;
    z-index: 1;
    width: 18px;
    height: 18px;
    border: 1.5px solid var(--ink-0);
    border-radius: 50%;
    background: var(--bad);
    color: oklch(98% 0.01 25);
    font: 700 11px/15px var(--font-text);
    text-align: center;
}
/* endregion */

/* region Hover card */
.zone-map__hover {
    position: fixed;
    z-index: 30;
    display: grid;
    gap: 1px;
    min-width: 170px;
    max-width: 260px;
    padding: 8px 10px;
    border: 1px solid var(--line);
    border-radius: 6px;
    background: oklch(22% 0.03 240 / 0.97);
    box-shadow: 0 6px 20px oklch(0% 0 0 / 0.4);
    color: var(--text-2);
    font-size: 12.5px;
    pointer-events: none;
}

.zone-map__hover strong {
    margin-bottom: 3px;
    color: var(--text);
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.zone-map__hover-problem {
    color: var(--bad);
    font-weight: 600;
}

.zone-map__hover-unknown {
    color: var(--text-3);
    font-style: italic;
}
/* endregion */

/* region Legend */
.zone-map__legend {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 18px;
    margin-top: 10px;
    color: var(--text-3);
    font-size: 12.5px;
}

.zone-map__key {
    display: inline-flex;
    align-items: center;
    gap: 7px;
}

.zone-map__swatch {
    display: inline-grid;
    place-items: center;
    width: 14px;
    height: 14px;
    border-radius: 3px;
}

.zone-map__swatch--edge {
    border: 2px solid var(--color-oni-amber);
}

.zone-map__swatch--unknown {
    border: 1px solid var(--line);
    background: repeating-linear-gradient(
        -45deg,
        var(--line) 0 1.5px,
        var(--ink-1) 1.5px 5px
    );
}

.zone-map__swatch--pin {
    border-radius: 50%;
    background: var(--bad);
    color: oklch(98% 0.01 25);
    font: 700 10px/1 var(--font-text);
    font-style: normal;
}

.zone-map__swatch.zone-map__swatch--unknown-state {
    background: var(--ink-3);
    color: var(--text);
}

.zone-map__scale {
    width: 120px;
    height: 8px;
    border-radius: 4px;
}

.zone-map__note {
    margin-top: 6px;
    color: var(--text-3);
    font-size: 13px;
}
/* endregion */
</style>
