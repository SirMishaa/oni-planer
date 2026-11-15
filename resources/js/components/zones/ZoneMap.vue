<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    onUpdated,
    ref,
    shallowRef,
    watch,
} from 'vue';
import GameSprite from '@/components/viability/GameSprite.vue';
import { useCritterMotion } from '@/composables/useCritterMotion';
import { useT } from '@/composables/useT';
import { useZoneText } from '@/composables/useZoneText';
import type {
    ZoneCritterData,
    ZoneEggData,
    ZoneEntityData,
    ZoneMapData,
    ZonePlantData,
    ZoneTerrainData,
} from '@/types/App/Data/data.d';
import { isReplayable, trackFrom } from './critterMotion';
import { critterEntities, type MapEntity } from './zoneCritterEntities';
import {
    createFluidRenderer,
    type FluidRenderer,
    shouldAnimate,
} from './zoneFluidRenderer';
import { fluidCells, liquidFill } from './zoneFluids';
import {
    footprintTouchesBox,
    layerIndex,
    localToCell,
    fitCellPx as fitCellSize,
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
import {
    getOverlayPalette,
    getRenderAssets,
    loadRenderAssets,
} from './zoneRenderAssets';
import {
    absoluteColour,
    legendStops,
    type OverlayPalette,
    type TemperatureMode,
    temperatureRange,
} from './zoneTemperature';
import { entityProblemMark } from './zoneVerdict';

// region Props

const props = defineProps<{
    /** The layers that change with the layout. */
    map: ZoneMapData;
    /** Terrain of the slow state (its own prop, reloaded on the slow stream); null until read. */
    terrain: ZoneTerrainData | null;
    /** Plants of the fast state (their own prop, reloaded on the fast stream); null until read. */
    plants: ZonePlantData[] | null;
    entities: ZoneEntityData[];
    /** Critters of the zone; null until the game sent them. */
    critters?: ZoneCritterData[] | null;
    /** When the critters were received (ISO): the stored fast state, or a raw frame the relay applied; a new value starts a new replay. */
    crittersAt?: string | null;
    eggs?: ZoneEggData[] | null;
    /** Critters with an alert of their own: their pin and hover card show it. */
    critterProblems?: ZoneEntityData[];
    layer: ZoneMapLayer;
    selectedId: string | null;
    zoneName: string;
}>();

const emit = defineEmits<{
    select: [instanceId: string];
    /** The drawn cell size in CSS pixels, zoom included: the comparison draws the game's capture at it. */
    'cell-size': [px: number];
}>();

// endregion

// region Sizing

/** Height of a plant on the map, in cells (most crops stand two cells high). */
const PLANT_HEIGHT_CELLS = 2;

const scroller = ref<HTMLElement | null>(null);
const availableWidth = ref(0);
const viewportHeight = ref(window.innerHeight);
let resizeObserver: ResizeObserver | undefined;

/** Zoom over the fitted size (100 %: the whole box fits the width and the height), changed by the wheel and the buttons. */
const ZOOM_DEFAULT = 1;
const ZOOM_MIN = 0.5;
const ZOOM_MAX = 6;
/** Ctrl + wheel step, as a factor. */
const ZOOM_STEP = 1.25;
/** Button step: ten points of zoom. */
const ZOOM_BUTTON_STEP = 0.1;
/** Longest canvas side, in device pixels, kept under the browsers' canvas limits. */
const CANVAS_MAX_PX = 8192;
/** Pointer travel, in CSS pixels, past which a press becomes a pan and not a click. */
const PAN_THRESHOLD_PX = 4;

const zoom = ref(ZOOM_DEFAULT);

/** The largest cell that fits the whole box in the available width and height, before zoom. */
const fitCellPx = computed(() =>
    fitCellSize(availableWidth.value, viewportHeight.value, props.map.box),
);

const cellPx = computed(() => {
    const dpr = window.devicePixelRatio || 1;
    const longestSide = Math.max(props.map.box.width, props.map.box.height, 1);
    const cap = Math.floor(CANVAS_MAX_PX / dpr / longestSide);

    return Math.max(4, Math.min(cap, Math.round(fitCellPx.value * zoom.value)));
});

watch(cellPx, (px) => emit('cell-size', px), { immediate: true });

const stageWidth = computed(() => props.map.box.width * cellPx.value);
const stageHeight = computed(() => props.map.box.height * cellPx.value);

// endregion

// region Camera

const zoomPercent = computed(() => Math.round(zoom.value * 100));

/**
 * Sets the zoom, keeping the point under `anchor` (a client position, the
 * scroller's centre by default) where it was.
 */
function setZoom(
    next: number,
    anchor?: { clientX: number; clientY: number },
): void {
    const element = scroller.value;
    const stageElement = stage.value;
    const clamped = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, next));

    if (!element || !stageElement || clamped === zoom.value) {
        zoom.value = clamped;

        return;
    }

    const view = element.getBoundingClientRect();
    const clientX = anchor?.clientX ?? view.left + view.width / 2;
    const clientY = anchor?.clientY ?? view.top + view.height / 2;
    const before = stageElement.getBoundingClientRect();
    const beforeCell = cellPx.value;
    const cellX = (clientX - before.left) / beforeCell;
    const cellY = (clientY - before.top) / beforeCell;

    zoom.value = clamped;

    void nextTick(() => {
        const after = stageElement.getBoundingClientRect();
        element.scrollLeft += after.left + cellX * cellPx.value - clientX;
        element.scrollTop += after.top + cellY * cellPx.value - clientY;
    });
}

/** The − and + buttons: ten points down or up, on a whole ten. */
function stepZoom(direction: 1 | -1): void {
    setZoom(Math.round((zoom.value + direction * ZOOM_BUTTON_STEP) * 10) / 10);
}

/** Ctrl (or ⌘) + wheel zooms the map; a plain wheel scrolls the map, or the page when the map does not scroll. */
function onWheel(event: WheelEvent): void {
    if (!event.ctrlKey && !event.metaKey) {
        return;
    }

    event.preventDefault();
    setZoom(zoom.value * (event.deltaY < 0 ? ZOOM_STEP : 1 / ZOOM_STEP), event);
}

/** A press on the map: a drag past the threshold pans it, and the click that ends a pan is swallowed. */
const pan = {
    pointerId: null as number | null,
    startX: 0,
    startY: 0,
    scrollLeft: 0,
    scrollTop: 0,
    moved: false,
};
const panning = ref(false);

function onPanStart(event: PointerEvent): void {
    if (event.button !== 0 || !scroller.value) {
        return;
    }

    pan.pointerId = event.pointerId;
    pan.startX = event.clientX;
    pan.startY = event.clientY;
    pan.scrollLeft = scroller.value.scrollLeft;
    pan.scrollTop = scroller.value.scrollTop;
    pan.moved = false;
}

function onPanMove(event: PointerEvent): void {
    if (pan.pointerId !== event.pointerId || !scroller.value) {
        return;
    }

    const dx = event.clientX - pan.startX;
    const dy = event.clientY - pan.startY;

    if (!pan.moved && Math.hypot(dx, dy) < PAN_THRESHOLD_PX) {
        return;
    }

    if (!pan.moved) {
        pan.moved = true;
        panning.value = true;
        scroller.value.setPointerCapture(event.pointerId);
    }

    scroller.value.scrollLeft = pan.scrollLeft - dx;
    scroller.value.scrollTop = pan.scrollTop - dy;
}

function onPanEnd(event: PointerEvent): void {
    if (pan.pointerId !== event.pointerId) {
        return;
    }

    pan.pointerId = null;
    panning.value = false;
}

function onClickCapture(event: MouseEvent): void {
    if (pan.moved) {
        pan.moved = false;
        event.stopPropagation();
        event.preventDefault();
    }
}

// endregion

// region Canvas

/**
 * Three stacked canvases (ruling 9): the base (hatch, static fluids or
 * temperature colours), the animated WebGL fluids, then the solids (terrain
 * edges, fallbacks, tiles, grid) so solid edges cover fluids as in the game.
 */
const root = ref<HTMLElement | null>(null);
const canvas = ref<HTMLCanvasElement | null>(null);
const solidsCanvas = ref<HTMLCanvasElement | null>(null);
const fluidCanvas = ref<HTMLCanvasElement | null>(null);
const inks = shallowRef<ZoneMapInks | null>(null);
const paintMs = ref<number | null>(null);
const temperatureMode = ref<TemperatureMode>('absolute');
const showGrid = ref<boolean>(false);
/** Game temperature overlay colours, null until `render.json` loads (or when it is missing). */
const overlayPalette = shallowRef<OverlayPalette | null>(null);
/** True once the `render.json` request has settled, found or not. */
const renderAssetsLoaded = ref<boolean>(false);
let frame = 0;

function paint(): void {
    frame = 0;

    if (!canvas.value || !inks.value) {
        return;
    }

    paintMs.value = paintZoneMap(canvas.value, {
        box: props.map.box,
        terrain: props.terrain,
        tiles: props.map.tiles,
        cellPx: cellPx.value,
        dpr: window.devicePixelRatio || 1,
        layer: props.layer,
        inks: inks.value,
        onSpriteLoad: schedulePaint,
        temperatureMode: temperatureMode.value,
        palette: overlayPalette.value,
        assets: getRenderAssets(schedulePaint),
        fluidsAnimated: fluidsAnimated.value,
        showGrid: showGrid.value,
        solidsCanvas: solidsCanvas.value,
    });
}

function schedulePaint(): void {
    if (frame === 0) {
        frame = requestAnimationFrame(paint);
    }
}

// endregion

// region Animated fluids

/** False once WebGL2 is missing or its context was lost: fluids then stay static. */
const webglUsable = ref<boolean>(true);
const fluidRenderer = shallowRef<FluidRenderer | null>(null);
/** The temperature layer paints no fluids: no WebGL canvas there. */
const showFluidCanvas = computed(
    () => props.layer !== 'temperature' && webglUsable.value,
);
const fluidsAnimated = computed(
    () => showFluidCanvas.value && fluidRenderer.value !== null,
);

const motion = { hidden: false, onScreen: true, reducedMotion: false };
let intersectionObserver: IntersectionObserver | undefined;
let reducedMotionQuery: MediaQueryList | undefined;

function syncAnimation(): void {
    const renderer = fluidRenderer.value;

    if (!renderer) {
        return;
    }

    if (shouldAnimate(motion)) {
        renderer.start();
    } else {
        renderer.stop();
    }
}

function updateFluids(): void {
    const renderer = fluidRenderer.value;
    const terrain = props.terrain;

    if (!renderer) {
        return;
    }

    renderer.update({
        cells: terrain ? fluidCells(terrain, props.map.box) : [],
        palette: terrain?.palette ?? [],
        box: props.map.box,
        cellPx: cellPx.value,
        dpr: window.devicePixelRatio || 1,
    });
}

function onContextLost(): void {
    teardownFluids();
    webglUsable.value = false;
}

function teardownFluids(): void {
    const renderer = fluidRenderer.value;

    if (!renderer) {
        return;
    }

    // Our listener goes first: destroy() loses the context on purpose.
    fluidCanvas.value?.removeEventListener('webglcontextlost', onContextLost);
    fluidRenderer.value = null;
    renderer.destroy();
}

watch(fluidCanvas, (element, previous) => {
    if (previous) {
        previous.removeEventListener('webglcontextlost', onContextLost);
    }

    const renderer = fluidRenderer.value;

    if (renderer) {
        fluidRenderer.value = null;
        renderer.destroy();
    }

    if (!element) {
        return;
    }

    const created = createFluidRenderer(element);

    if (!created) {
        webglUsable.value = false;

        return;
    }

    element.addEventListener('webglcontextlost', onContextLost);
    fluidRenderer.value = created;
    updateFluids();
    syncAnimation();
});

watch(() => [props.map.box, props.terrain, cellPx.value], updateFluids);

function onVisibilityChange(): void {
    motion.hidden = document.hidden;
    syncAnimation();
}

function onWindowResize(): void {
    viewportHeight.value = window.innerHeight;
}

function onReducedMotionChange(event: MediaQueryListEvent): void {
    motion.reducedMotion = event.matches;
    syncAnimation();
}

// endregion

// region Lifecycle

watch(
    () => [
        props.map.box,
        props.terrain,
        props.map.tiles,
        props.layer,
        cellPx.value,
        temperatureMode.value,
        showGrid.value,
        fluidsAnimated.value,
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

    motion.hidden = document.hidden;
    document.addEventListener('visibilitychange', onVisibilityChange);
    window.addEventListener('resize', onWindowResize);
    reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    motion.reducedMotion = reducedMotionQuery.matches;
    reducedMotionQuery.addEventListener('change', onReducedMotionChange);

    if (stage.value) {
        intersectionObserver = new IntersectionObserver(([entry]) => {
            motion.onScreen = entry?.isIntersecting ?? true;
            syncAnimation();
        });
        intersectionObserver.observe(stage.value);
    }

    syncAnimation();
    loadTileGeometry(schedulePaint);
    loadRenderAssets(() => {
        overlayPalette.value = getOverlayPalette();
        renderAssetsLoaded.value = true;
        schedulePaint();
    });
    void nextTick(schedulePaint);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    intersectionObserver?.disconnect();
    document.removeEventListener('visibilitychange', onVisibilityChange);
    window.removeEventListener('resize', onWindowResize);
    reducedMotionQuery?.removeEventListener('change', onReducedMotionChange);
    cancelAnimationFrame(frame);
    teardownFluids();
});

// endregion

// region Zone outline

const outlinePath = computed(() =>
    zoneOutlinePath(props.map.zoneCells, props.map.box),
);

// endregion

// region Entities

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
            const rect = building.spriteRect;

            return {
                key: `b${building.instanceId ?? index}`,
                instanceId: building.instanceId,
                kind: 'building',
                name: building.name,
                sprite: building.sprite,
                x: building.x,
                y: building.y,
                width: building.width,
                height: building.spriteHeight,
                hanging: building.hangsFromCeiling,
                flipped: building.flipped,
                drawBox:
                    rect === null
                        ? null
                        : {
                              x:
                                  building.x +
                                  (building.flipped
                                      ? building.width - rect.x - rect.w
                                      : rect.x),
                              y: building.y + rect.y,
                              width: rect.w,
                              height: rect.h,
                          },
                growth: null,
                secondsUntilHarvest: null,
                state: entity?.state ?? null,
                problems: entity?.problems ?? [],
            };
        },
    );

    const plants = (props.plants ?? []).map((plant, index): MapEntity => {
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
            hanging: false,
            flipped: false,
            drawBox: null,
            growth: plant.growth,
            secondsUntilHarvest: plant.secondsUntilHarvest,
            state:
                entity?.state ??
                (plant.wilting ? 'wilting' : plant.grown ? 'grown' : 'growing'),
            problems: entity?.problems ?? [],
        };
    });

    const problemsByCritter = new Map(
        (props.critterProblems ?? []).map((problem) => [
            problem.instanceId,
            problem.problems,
        ]),
    );
    const critters = critterEntities(
        props.critters ?? [],
        props.eggs ?? [],
        box,
        props.map.gridWidth,
    ).map((entity) => ({
        ...entity,
        problems: problemsByCritter.get(entity.instanceId) ?? [],
    }));

    return [
        ...[...buildings, ...plants].filter((entity) =>
            footprintTouchesBox(
                entity.x,
                entity.y,
                entity.width,
                entity.height,
                box,
            ),
        ),
        ...critters,
    ];
});

/** Critters and eggs come with the fast state: no state of their own, but their problems are known. */
function isCreature(entity: MapEntity): boolean {
    return entity.kind === 'critter' || entity.kind === 'egg';
}

function entityStyle(entity: MapEntity): Record<string, string> {
    const box = entity.drawBox ?? entity;
    const { column, row } = cellToLocal(
        box.x,
        box.y + box.height - 1,
        props.map.box,
    );

    return {
        left: `${column * cellPx.value}px`,
        top: `${row * cellPx.value}px`,
        width: `${box.width * cellPx.value}px`,
        height: `${box.height * cellPx.value}px`,
    };
}

/** Problem mark; a building the fast state does not cover is unknown, never problem-free. */
function problemMark(entity: MapEntity): 'problem' | 'clear' | 'unknown' {
    return entityProblemMark(
        entity.problems.length,
        entity.state !== null || isCreature(entity),
    );
}

function select(entity: MapEntity): void {
    if (entity.instanceId !== null) {
        emit('select', entity.instanceId);
    }
}

// endregion

// region Critter motion

/**
 * Between two fast states, each critter travels from where it is drawn to
 * its new game position, then walks its path. Vue places each critter
 * where the game saw it (left/top); every frame, the replay moves it by a CSS
 * `translate` written straight on its element, so neither the template nor
 * the layout runs per frame. The facing goes the same way (`data-facing`).
 */
const crittersReceivedAt = ref<number>(performance.now());

/** A new fast state only: a reload that resends the same critters must not restart their walk. */
watch(
    () => props.crittersAt,
    () => {
        crittersReceivedAt.value = performance.now();
    },
);

const critterPoses = useCritterMotion(
    computed(() => props.critters ?? null),
    crittersReceivedAt,
    {
        gridWidth: () => props.map.gridWidth,
        box: () => props.map.box,
        replay: () =>
            !window.matchMedia('(prefers-reduced-motion: reduce)').matches &&
            isReplayable(props.crittersAt ?? null, Date.now()),
    },
);

const crittersById = computed(
    () =>
        new Map(
            (props.critters ?? []).map((critter) => [
                critter.instanceId,
                critter,
            ]),
        ),
);

/** The critter buttons, collected after each render. */
let critterElements: HTMLElement[] = [];

function applyCritterPoses(): void {
    const poses = critterPoses.value;
    const px = cellPx.value;

    for (const element of critterElements) {
        const instanceId = element.dataset.critter ?? '';
        const pose = poses.get(instanceId);
        const critter = crittersById.value.get(instanceId);

        if (!pose || !critter) {
            continue;
        }

        const dx = (pose.x - critter.x) * px;
        const dy = (critter.y - pose.y) * px;
        element.style.transform =
            dx === 0 && dy === 0 ? '' : `translate(${dx}px, ${dy}px)`;
        element.dataset.facing = pose.facing;
    }
}

function collectCritterElements(): void {
    critterElements = stage.value
        ? [...stage.value.querySelectorAll<HTMLElement>('[data-critter]')]
        : [];
    applyCritterPoses();
}

watch(critterPoses, applyCritterPoses, { flush: 'sync' });
onMounted(collectCritterElements);
onUpdated(collectCritterElements);

/**
 * The path the selected critter is walking, as dots in the box's local cells:
 * the steps of its replay track, so the dots stop where the replay stops (the
 * first cell out of the box). They show the whole path of the message, walked
 * steps included: dropping those would re-render the map at every step.
 */
const selectedPath = computed<{ column: number; row: number }[]>(() => {
    const critter = props.selectedId
        ? crittersById.value.get(props.selectedId)
        : undefined;

    if (!critter) {
        return [];
    }

    const box = props.map.box;

    return trackFrom(critter, 0, props.map.gridWidth, box).path.map((step) =>
        cellToLocal(Math.floor(step.x), step.y, box),
    );
});

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

/** Overlay without colours: enough to find a temperature band before `render.json` loads. */
const NO_PALETTE: OverlayPalette = { temperature: [], heatFlow: [] };

const temperatureSpan = computed(() =>
    temperatureRange(props.terrain?.temperature ?? null),
);

/** The band name (absolute only: band names are absolute ranges) or signed rate (heat flow) of a cell; null when unknown. */
function temperatureDetail(index: number, kelvin: number): string | null {
    const terrain = props.terrain;
    const palette = overlayPalette.value ?? NO_PALETTE;

    switch (temperatureMode.value) {
        case 'absolute':
            return (
                props.map.temperatureBands[
                    absoluteColour(kelvin, palette).band
                ] ?? null
            );
        case 'heatFlow': {
            const previous = terrain?.previousTemperature?.[index] ?? null;
            const seconds = terrain?.previousSeconds ?? 0;

            return previous === null || !(seconds > 0)
                ? null
                : text.celsiusPerSecond((kelvin - previous) / seconds);
        }
        case 'adaptive':
        case 'stateChange':
            return null;
    }
}

/** Terrain under the pointer: element, state, mass, temperature and fill, each possibly unknown. */
const hoveredCell = computed(() => {
    if (!hover.value) {
        return null;
    }

    const terrain = props.terrain;
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
        state: element?.state ?? null,
        mass,
        temperature,
        fill:
            element?.state === 'liquid'
                ? Math.round(liquidFill(mass, element.maxMass) * 100)
                : null,
        detail:
            props.layer === 'temperature' &&
            index !== null &&
            temperature !== null &&
            Number.isFinite(temperature)
                ? temperatureDetail(index, temperature)
                : null,
    };
});

/** The hovered cell's frame, in stage pixels. */
// region Lifted block

/** Share of a cell kept around a lifted block, so its inked rim lifts with it. */
const LIFT_MARGIN_CELLS = 0.15;

const liftCanvas = ref<HTMLCanvasElement | null>(null);

/** The hovered solid cell or tile (never a fluid, never under an entity), lifted like the entities. */
const hoveredBlock = computed<{ x: number; y: number } | null>(() => {
    const current = hover.value;

    if (!current || hoveredEntity.value) {
        return null;
    }

    const terrain = props.terrain;
    const index = terrain
        ? layerIndex(current.x, current.y, terrain.box)
        : null;
    const palette = index === null ? -1 : (terrain?.element?.[index] ?? -1);
    const solid = terrain?.palette[palette]?.state === 'solid';
    const tile = (props.map.tiles ?? []).some(
        (candidate) => candidate.x === current.x && candidate.y === current.y,
    );

    return solid || tile ? { x: current.x, y: current.y } : null;
});

/** Backing resolution of the lifted copy over the map's own pixels, so its slight scale stays sharp. */
const LIFT_OVERSAMPLE = 2;

/**
 * The lifted copy's crop of the solids canvas, in that canvas's pixels, snapped
 * to whole pixels so the copy sits exactly on the map and is not resampled.
 */
const liftRect = computed(() => {
    const block = hoveredBlock.value;
    const source = solidsCanvas.value;

    if (!block || !source || stageWidth.value <= 0) {
        return null;
    }

    const ratio = source.width / stageWidth.value;
    const { column, row } = cellToLocal(block.x, block.y, props.map.box);
    const left = Math.floor(
        (column - LIFT_MARGIN_CELLS) * cellPx.value * ratio,
    );
    const top = Math.floor((row - LIFT_MARGIN_CELLS) * cellPx.value * ratio);
    const size = Math.ceil((1 + LIFT_MARGIN_CELLS * 2) * cellPx.value * ratio);

    return { left, top, size, ratio };
});

const liftStyle = computed(() => {
    const rect = liftRect.value;

    if (!rect) {
        return {};
    }

    return {
        left: `${rect.left / rect.ratio}px`,
        top: `${rect.top / rect.ratio}px`,
        width: `${rect.size / rect.ratio}px`,
        height: `${rect.size / rect.ratio}px`,
    };
});

/** Copies the hovered block (and its rim) from the solids canvas into the lifted copy. */
function drawLift(): void {
    const rect = liftRect.value;
    const source = solidsCanvas.value;
    const target = liftCanvas.value;
    const context = target?.getContext('2d');

    if (!rect || !source || !target || !context) {
        return;
    }

    target.width = rect.size * LIFT_OVERSAMPLE;
    target.height = rect.size * LIFT_OVERSAMPLE;
    context.imageSmoothingEnabled = false;
    context.drawImage(
        source,
        rect.left,
        rect.top,
        rect.size,
        rect.size,
        0,
        0,
        target.width,
        target.height,
    );
}

watch(hoveredBlock, () => void nextTick(drawLift));

// endregion

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

    if (!isCreature(entity)) {
        parts.push(
            entity.state
                ? t(`zones.states.${entity.state}`)
                : t('zones.panel.state_unknown'),
        );
    }

    return [...parts, ...entity.problems].join(', ');
}

// endregion

// region Legend

const TEMPERATURE_MODES: { key: TemperatureMode; label: string }[] = [
    { key: 'absolute', label: 'zones.map.modes.absolute' },
    { key: 'adaptive', label: 'zones.map.modes.adaptive' },
    { key: 'heatFlow', label: 'zones.map.modes.heat_flow' },
    { key: 'stateChange', label: 'zones.map.modes.state_change' },
];

/** Heat flow needs two slow states; until the second arrives it paints nothing. */
const heatFlowWaiting = computed(() => {
    const terrain = props.terrain;

    return (
        temperatureMode.value === 'heatFlow' &&
        (!terrain?.previousTemperature || !((terrain.previousSeconds ?? 0) > 0))
    );
});

/** `render.json` loaded without overlay colours: every mode but state change paints the backdrop. */
const overlayMissing = computed(
    () =>
        renderAssetsLoaded.value &&
        overlayPalette.value === null &&
        temperatureMode.value !== 'stateChange',
);

const temperatureLegend = computed(() => {
    if (heatFlowWaiting.value) {
        return [];
    }

    return legendStops(
        temperatureMode.value,
        overlayPalette.value,
        temperatureSpan.value,
    ).map((stop, index) => {
        const [r, g, b, a] = stop.colour ?? [0, 0, 0, 0];

        return {
            key: index,
            colour: stop.colour ? `rgb(${r} ${g} ${b} / ${a / 255})` : null,
            label: stop.labelKey ? t(stop.labelKey) : stop.label,
        };
    });
});

// endregion
</script>

<template>
    <div ref="root" class="zone-map">
        <!-- region Stage -->
        <div
            ref="scroller"
            class="zone-map__scroll"
            :class="{ 'zone-map__scroll--panning': panning }"
            @wheel="onWheel"
            @pointerdown="onPanStart"
            @pointermove="onPanMove"
            @pointerup="onPanEnd"
            @pointercancel="onPanEnd"
            @click.capture="onClickCapture"
        >
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
                <canvas
                    v-if="showFluidCanvas"
                    ref="fluidCanvas"
                    class="zone-map__canvas"
                    :style="{
                        width: `${stageWidth}px`,
                        height: `${stageHeight}px`,
                    }"
                    aria-hidden="true"
                />
                <canvas
                    ref="solidsCanvas"
                    class="zone-map__canvas"
                    :style="{
                        width: `${stageWidth}px`,
                        height: `${stageHeight}px`,
                    }"
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
                    <path class="zone-map__outline-shadow" :d="outlinePath" />
                    <path class="zone-map__outline-line" :d="outlinePath" />
                </svg>
                <!-- endregion -->

                <!-- region Selected critter path -->
                <svg
                    v-if="selectedPath.length > 0"
                    class="zone-map__path"
                    :viewBox="`0 0 ${map.box.width} ${map.box.height}`"
                    :width="stageWidth"
                    :height="stageHeight"
                    preserveAspectRatio="none"
                    aria-hidden="true"
                >
                    <circle
                        v-for="(dot, index) in selectedPath"
                        :key="index"
                        :cx="dot.column + 0.5"
                        :cy="dot.row + 0.5"
                        r="0.13"
                    />
                </svg>
                <!-- endregion -->

                <!-- region Entities -->
                <div class="zone-map__entities">
                    <button
                        v-for="entity in mapEntities"
                        :key="entity.key"
                        type="button"
                        class="zone-entity"
                        :class="{
                            'zone-entity--plant': entity.kind === 'plant',
                            'zone-entity--creature': isCreature(entity),
                            'zone-entity--selected':
                                entity.instanceId !== null &&
                                entity.instanceId === selectedId,
                            'zone-entity--dimmed':
                                (layer === 'problems' &&
                                    problemMark(entity) === 'clear') ||
                                (layer === 'critters' && !isCreature(entity)),
                            'zone-entity--faint': layer === 'temperature',
                        }"
                        :style="entityStyle(entity)"
                        :data-entity="entity.key"
                        :data-critter="
                            entity.kind === 'critter'
                                ? entity.instanceId
                                : undefined
                        "
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
                            :class="{
                                'zone-entity__sprite--hanging': entity.hanging,
                                'zone-entity__sprite--flipped':
                                    entity.flipped && entity.kind !== 'critter',
                            }"
                            decoding="async"
                        />
                        <GameSprite
                            v-else
                            :src="null"
                            :kind="isCreature(entity) ? 'critter' : entity.kind"
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
                </div>
                <!-- endregion -->

                <!-- region Hover card -->
                <canvas
                    v-if="hoveredBlock"
                    :key="`${hoveredBlock.x},${hoveredBlock.y}`"
                    ref="liftCanvas"
                    class="zone-map__lift"
                    :style="liftStyle"
                    aria-hidden="true"
                />
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
                        <template v-if="!isCreature(hoveredEntity)">
                            <span
                                v-if="!hoveredEntity.state"
                                class="zone-map__hover-unknown"
                                >{{ t('zones.panel.state_unknown') }}</span
                            >
                            <span v-else>{{
                                t(`zones.states.${hoveredEntity.state}`)
                            }}</span>
                        </template>
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
                        <strong>{{
                            hoveredCell.element ?? t('zones.map.unknown_cell')
                        }}</strong>
                        <span v-if="hoveredCell.state">{{
                            t(`zones.map.states.${hoveredCell.state}`)
                        }}</span>
                        <span v-if="hoveredCell.mass !== null">{{
                            text.mass(hoveredCell.mass)
                        }}</span>
                        <span v-if="hoveredCell.temperature !== null">{{
                            text.celsius(hoveredCell.temperature)
                        }}</span>
                        <span v-if="hoveredCell.fill !== null">{{
                            t('zones.map.fill', {
                                percent: hoveredCell.fill,
                            })
                        }}</span>
                        <span
                            v-if="hoveredCell.detail"
                            class="zone-map__hover-detail"
                            >{{ hoveredCell.detail }}</span
                        >
                        <span class="zone-map__hover-position">{{
                            text.position(hover.x, hover.y)
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
            <button
                type="button"
                class="zone-map__toggle zone-map__grid"
                data-test="zone-map-grid"
                :aria-pressed="showGrid"
                @click="showGrid = !showGrid"
            >
                {{ t('zones.map.grid') }}
            </button>
            <div
                class="zone-map__zoom"
                role="group"
                :aria-label="t('zones.map.zoom')"
            >
                <button
                    type="button"
                    class="zone-map__toggle"
                    data-test="zone-map-zoom-out"
                    :aria-label="t('zones.map.zoom_out')"
                    :disabled="zoom <= ZOOM_MIN"
                    @click="stepZoom(-1)"
                >
                    −
                </button>
                <button
                    type="button"
                    class="zone-map__toggle"
                    data-test="zone-map-zoom-reset"
                    :title="t('zones.map.zoom_reset')"
                    @click="setZoom(ZOOM_DEFAULT)"
                >
                    {{ zoomPercent }} %
                </button>
                <button
                    type="button"
                    class="zone-map__toggle"
                    data-test="zone-map-zoom-in"
                    :aria-label="t('zones.map.zoom_in')"
                    :disabled="zoom >= ZOOM_MAX"
                    @click="stepZoom(1)"
                >
                    +
                </button>
            </div>
        </div>

        <div v-if="layer === 'temperature'" class="zone-map__temperature">
            <div
                class="zone-map__modes"
                role="group"
                :aria-label="t('zones.layers.temperature')"
            >
                <button
                    v-for="mode in TEMPERATURE_MODES"
                    :key="mode.key"
                    type="button"
                    class="zone-map__toggle"
                    :aria-pressed="temperatureMode === mode.key"
                    @click="temperatureMode = mode.key"
                >
                    {{ t(mode.label) }}
                </button>
            </div>
            <p
                v-if="overlayMissing"
                class="zone-map__note"
                data-test="zone-map-overlay-missing"
            >
                {{ t('zones.map.overlay_missing') }}
            </p>
            <p v-if="heatFlowWaiting" class="zone-map__note">
                {{ t('zones.map.heat_flow_waiting') }}
            </p>
            <ol v-else-if="temperatureLegend.length" class="zone-map__stops">
                <li
                    v-for="stop in temperatureLegend"
                    :key="stop.key"
                    class="zone-map__stop"
                >
                    <i
                        v-if="stop.colour"
                        class="zone-map__swatch"
                        :style="{ background: stop.colour }"
                    />
                    {{ stop.label }}
                </li>
            </ol>
        </div>
        <p v-if="!terrain" class="zone-map__note">
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
    max-height: 72vh;
    cursor: grab;
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
    stroke-linejoin: round;
    vector-effect: non-scaling-stroke;
}

/* A discreet dashed line over a faint dark halo, so it reads on light water as well as on dark rock without hiding the map. */
.zone-map__outline-shadow {
    stroke: rgb(0 0 0 / 0.3);
    stroke-width: 3.5px;
}

.zone-map__outline-line {
    stroke: rgb(255 255 255 / 0.75);
    stroke-width: 1.5px;
    stroke-dasharray: 6 5;
    stroke-linecap: round;
}

/* The selected critter's path: small dots over the map, under the entities. */
.zone-map__path {
    position: absolute;
    inset: 0;
    z-index: 1;
    pointer-events: none;
}

.zone-map__path circle {
    fill: rgb(255 255 255 / 0.8);
    stroke: rgb(0 0 0 / 0.35);
    stroke-width: 0.04;
}

/* A hovered block lifts like the entities: same curve, same lift. */
.zone-map__lift {
    position: absolute;
    z-index: 3;
    transform-origin: 50% 100%;
    pointer-events: none;
    animation: zone-lift 220ms cubic-bezier(0.25, 1, 0.5, 1) forwards;
}

@keyframes zone-lift {
    from {
        transform: none;
        filter: drop-shadow(0 0 0 oklch(15% 0.02 230 / 0)) brightness(1);
    }

    to {
        transform: translateY(-1px) scale(1.02);
        filter: drop-shadow(0 2px 3px oklch(15% 0.02 230 / 0.45))
            brightness(1.05);
    }
}

@media (prefers-reduced-motion: reduce) {
    .zone-map__lift {
        animation: none;
        filter: brightness(1.06);
    }
}

/* region Entities */
.zone-map__entities {
    position: absolute;
    inset: 0;
    z-index: 2;
    /* A window on the game: buildings of the next room show only their part in the box; pins keep a little room. */
    overflow: clip;
    overflow-clip-margin: 10px;
    pointer-events: none;
}

.zone-map__entities > .zone-entity {
    pointer-events: auto;
}

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

/* region Sticker selection: an outline that follows the sprite's silhouette, lifted like a sticker */
.zone-entity {
    --sticker: oklch(97% 0.012 85);
    --sticker-shadow: oklch(15% 0.02 230 / 0.55);
    /* The same colours fully transparent: the outline fades from clear white, not from black. */
    --sticker-clear: oklch(97% 0.012 85 / 0);
    --sticker-shadow-clear: oklch(15% 0.02 230 / 0);
}

/* Same filter list in every state (5 shadows, then brightness), so the outline
   animates with the lift instead of snapping in at the end. */
.zone-entity__sprite,
.zone-entity__placeholder {
    transform-origin: 50% 100%;
    filter: drop-shadow(0 0 0 var(--sticker-clear))
        drop-shadow(0 0 0 var(--sticker-clear))
        drop-shadow(0 0 0 var(--sticker-clear))
        drop-shadow(0 0 0 var(--sticker-clear))
        drop-shadow(0 0 0 var(--sticker-shadow-clear)) brightness(1);
    transition:
        transform 220ms cubic-bezier(0.25, 1, 0.5, 1),
        filter 220ms cubic-bezier(0.25, 1, 0.5, 1);
}

.zone-entity:hover .zone-entity__sprite,
.zone-entity:hover .zone-entity__placeholder {
    /* Hover lifts only; the white outline is for the selection. */
    transform: translateY(-1px) scale(1.03);
    filter: drop-shadow(0 0 0 var(--sticker-clear))
        drop-shadow(0 0 0 var(--sticker-clear))
        drop-shadow(0 0 0 var(--sticker-clear))
        drop-shadow(0 0 0 var(--sticker-clear))
        drop-shadow(0 0 0 var(--sticker-shadow-clear)) brightness(1.06);
}

.zone-entity--selected {
    z-index: 5;
}

/* Two classes: beats the hover rule (:hover counts as one), so a hovered entity shows its selection at once. */
.zone-entity.zone-entity--selected .zone-entity__sprite,
.zone-entity.zone-entity--selected .zone-entity__placeholder {
    transform: translateY(-3px) scale(1.08);
    filter: drop-shadow(2px 0 0 var(--sticker))
        drop-shadow(-2px 0 0 var(--sticker)) drop-shadow(0 2px 0 var(--sticker))
        drop-shadow(0 -2px 0 var(--sticker))
        drop-shadow(0 5px 5px var(--sticker-shadow)) brightness(1.08);
}

.zone-entity:focus-visible {
    outline: 1.5px solid var(--sticker);
    outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
    .zone-entity__sprite,
    .zone-entity__placeholder {
        transition: none;
    }

    .zone-entity:hover .zone-entity__sprite,
    .zone-entity:hover .zone-entity__placeholder,
    .zone-entity--selected .zone-entity__sprite,
    .zone-entity--selected .zone-entity__placeholder {
        transform: none;
    }
}
/* endregion */

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

.zone-entity__sprite--hanging {
    object-position: left top;
}

/* A critter or an egg: centred on its spot, so a mirrored image stays in place. */
.zone-entity--creature {
    z-index: 4;
    place-items: end center;
}

.zone-entity--creature .zone-entity__sprite {
    object-position: center bottom;
}

/* Mirrored with `scale`, which composes with the hover and selection `transform`. A critter's facing follows its walk (`data-facing`, set by the replay). */
.zone-entity__sprite--flipped,
.zone-entity[data-facing='left'] .zone-entity__sprite {
    scale: -1 1;
}

/* The replay moves critters every frame with `translate` on the button (no transition on it): a layer of their own spares the map a repaint. */
.zone-entity[data-critter] {
    will-change: transform;
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

.zone-map__hover-detail {
    color: var(--color-oni-amber);
}

.zone-map__hover-position {
    margin-top: 3px;
    color: var(--text-3);
    font-size: 11.5px;
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
    border: 1.5px dashed rgb(255 255 255 / 0.75);
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

.zone-map__grid {
    margin-left: auto;
}

.zone-map__scroll--panning {
    cursor: grabbing;
    user-select: none;
}

.zone-map__zoom {
    display: flex;
    gap: 4px;
}

.zone-map__zoom .zone-map__toggle:disabled {
    opacity: 0.4;
    cursor: default;
}

.zone-map__toggle {
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    padding: 3px 10px;
    border-radius: 6px;
    background: var(--ink-2);
    color: var(--text-2);
    font-size: 12.5px;
}

.zone-map__toggle:hover {
    color: var(--text);
}

.zone-map__toggle[aria-pressed='true'] {
    background: oklch(72% 0.145 52 / 0.22);
    color: var(--color-oni-amber);
}

.zone-map__temperature {
    display: grid;
    gap: 8px;
    margin-top: 10px;
}

.zone-map__modes {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.zone-map__stops {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 14px;
    color: var(--text-3);
    font-size: 12px;
    font-variant-numeric: tabular-nums;
}

.zone-map__stop {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.zone-map__note {
    margin-top: 6px;
    color: var(--text-3);
    font-size: 13px;
}
/* endregion */
</style>
