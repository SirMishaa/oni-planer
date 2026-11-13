import type {
    ZoneBoxData,
    ZoneTerrainData,
    ZoneTileData,
} from '@/types/App/Data/data.d';
import { patternFor } from './substanceTexturePatterns';
import { fluidAlpha, fluidCells } from './zoneFluids';
import {
    cellToLocal,
    layerIndex,
    localToCell,
    type Rgb,
    rgbCss,
} from './zoneMapGeometry';
import type { RenderAssets } from './zoneRenderAssets';
import {
    absoluteColour,
    adaptiveColour,
    heatFlowColour,
    type OverlayPalette,
    parseRgba,
    type Rgba,
    stateChangeColour,
    type TemperatureMode,
    temperatureRange,
} from './zoneTemperature';
import { variation } from './zoneTerrainEdges';
import {
    forEachKnownCell,
    paintFallbackCells,
    paintSolidTerrain,
    stripePattern,
    type TerrainPaint,
} from './zoneTerrainPainter';

export type ZoneMapLayer = 'growth' | 'materials' | 'temperature' | 'problems';

/** Map inks read from the page's colour tokens. */
export type ZoneMapInks = {
    /** `--ink-0`: the problems veil. */
    base: Rgb;
    /** `--ink-1`: background of unknown cells. */
    unknown: Rgb;
    /** `--line`: hatching of unknown cells, fallback stripes and the grid. */
    hatch: Rgb;
    /** `--ink-2`: a known element without render data. */
    neutral: Rgb;
    /** `--ink-3`: a tile without a sprite. */
    tile: Rgb;
    /** `--text-3`: top edge of a tile without a sprite. */
    tileEdge: Rgb;
};

/** Where the cell sits in a connection sprite (OniExtract `cells.json`). */
type TileGeometry = {
    canvas: { w: number; h: number };
    cell: { x: number; y: number; w: number; h: number };
};

const TILE_ASSETS = '/assets/game/connections';
/** Tiles stay faint under the temperature layer so the colours read. */
const TILE_ALPHA_ON_TEMPERATURE = 0.3;
/** Problems layer: veil over the terrain so the pins stand out. */
const PROBLEMS_VEIL_ALPHA = 0.55;
/** Suspended flecks of a static liquid, per unit of its pattern's `flecks`. */
const FLECKS_PER_CELL = 4;
const FLECK_STYLE = 'rgb(255 255 255 / 0.22)';

// region Tile sprites

let tileGeometry: Record<string, TileGeometry> | null = null;
let tileGeometryRequest: Promise<void> | null = null;
const tileImages = new Map<string, HTMLImageElement | null>();

/** Loads the sprite geometry once; `onReady` repaints with the sprites. */
export function loadTileGeometry(onReady: () => void): void {
    if (tileGeometry !== null) {
        return;
    }

    tileGeometryRequest ??= fetch(`${TILE_ASSETS}/cells.json`)
        .then((response) => (response.ok ? response.json() : {}))
        .then((json: Record<string, TileGeometry>) => {
            tileGeometry = json;
        })
        .catch(() => {
            tileGeometry = {};
        });

    void tileGeometryRequest.then(onReady);
}

/**
 * The loaded sprite of a tile, or null while it loads, when it failed, or
 * when the prefab has no exported sprites (the tile is then drawn flat).
 */
function tileSprite(
    tile: ZoneTileData,
    onLoad: () => void,
): { image: HTMLImageElement; geometry: TileGeometry } | null {
    const geometry = tileGeometry?.[tile.prefabId];

    if (!geometry) {
        return null;
    }

    const src = `${TILE_ASSETS}/${tile.prefabId}/${tile.connections}.webp`;

    if (!tileImages.has(src)) {
        const image = new Image();
        image.decoding = 'async';
        image.onerror = () => tileImages.set(src, null);
        image.src = src;
        tileImages.set(src, image);
    }

    const image = tileImages.get(src);

    if (image && !image.complete) {
        image.addEventListener('load', onLoad, { once: true });
    }

    return image && image.complete && image.naturalWidth > 0
        ? { image, geometry }
        : null;
}

// endregion

// region Painting

function hatchPattern(
    ctx: CanvasRenderingContext2D,
    inks: ZoneMapInks,
    dpr: number,
): CanvasPattern | string {
    const size = Math.max(4, Math.round(8 * dpr));
    const tile = document.createElement('canvas');
    tile.width = size;
    tile.height = size;
    const tileCtx = tile.getContext('2d');

    if (!tileCtx) {
        return rgbCss(inks.unknown);
    }

    tileCtx.fillStyle = rgbCss(inks.unknown);
    tileCtx.fillRect(0, 0, size, size);
    tileCtx.strokeStyle = rgbCss(inks.hatch);
    tileCtx.lineWidth = Math.max(1, dpr);
    tileCtx.beginPath();
    tileCtx.moveTo(0, size);
    tileCtx.lineTo(size, 0);
    tileCtx.moveTo(-size / 2, size / 2);
    tileCtx.lineTo(size / 2, -size / 2);
    tileCtx.moveTo(size / 2, size * 1.5);
    tileCtx.lineTo(size * 1.5, size / 2);
    tileCtx.stroke();

    return ctx.createPattern(tile, 'repeat') ?? rgbCss(inks.unknown);
}

export function rgbaCss([red, green, blue, alpha]: Rgba): string {
    return `rgb(${red} ${green} ${blue} / ${alpha / 255})`;
}

/**
 * Overlay colour of one cell in the mode; null paints nothing (unknown input,
 * heat flow before a second reading, or no palette for a mode that needs one).
 */
function temperatureColour(
    mode: TemperatureMode,
    palette: OverlayPalette | null,
    terrain: ZoneTerrainData,
    index: number,
    kelvin: number,
    range: { min: number; max: number } | null,
): Rgba | null {
    switch (mode) {
        case 'absolute':
            return palette ? absoluteColour(kelvin, palette).colour : null;
        case 'adaptive':
            return range && palette
                ? adaptiveColour(kelvin, range, palette).colour
                : null;
        case 'heatFlow': {
            const previous = terrain.previousTemperature?.[index] ?? null;
            const seconds = terrain.previousSeconds ?? 0;

            return previous === null || !(seconds > 0) || palette === null
                ? null
                : heatFlowColour((kelvin - previous) / seconds, palette);
        }
        case 'stateChange': {
            const entry = terrain.palette[terrain.element?.[index] ?? -1];
            const state = entry?.state;

            return entry &&
                (state === 'solid' || state === 'liquid' || state === 'gas')
                ? stateChangeColour(
                      kelvin,
                      state,
                      entry.lowTemp,
                      entry.highTemp,
                  )
                : null;
        }
    }
}

/**
 * Temperature layer: each known cell in the mode's colour, no textures. State
 * change needs no palette; the other modes without one clear known cells to
 * the map backdrop (the page explains why), so they never read as unknown.
 */
function paintTemperatures(
    paint: TerrainPaint,
    terrain: ZoneTerrainData,
    mode: TemperatureMode,
    palette: OverlayPalette | null,
): void {
    const { ctx, box, px } = paint;
    const values = terrain.temperature;

    if (!values) {
        return;
    }

    const range = mode === 'adaptive' ? temperatureRange(values) : null;
    const paletteMissing = palette === null && mode !== 'stateChange';
    const source = terrain.box;

    for (let index = 0; index < source.width * source.height; index++) {
        const kelvin = values[index];

        if (
            kelvin === null ||
            kelvin === undefined ||
            !Number.isFinite(kelvin)
        ) {
            continue;
        }

        const x = source.x + (index % source.width);
        const y = source.y + Math.floor(index / source.width);

        if (layerIndex(x, y, box) === null) {
            continue;
        }

        const colour = paletteMissing
            ? null
            : temperatureColour(mode, palette, terrain, index, kelvin, range);

        if (colour === null && !paletteMissing) {
            continue;
        }

        const { column, row } = cellToLocal(x, y, box);
        const left = px(column);
        const top = px(row);
        ctx.clearRect(left, top, px(column + 1) - left, px(row + 1) - top);

        if (colour === null) {
            continue;
        }

        ctx.fillStyle = rgbaCss(colour);
        ctx.fillRect(left, top, px(column + 1) - left, px(row + 1) - top);
    }
}

/**
 * Liquids and gases without the animated layer: render colour with its
 * alpha (opaque liquids fully opaque), liquids filled from the bottom of the
 * cell, gases tinted by their strength, plus the family's flecks.
 */
function paintStaticFluids(
    paint: TerrainPaint,
    terrain: ZoneTerrainData,
): void {
    const { ctx, box, px } = paint;

    for (const cell of fluidCells(terrain, box)) {
        const entry = terrain.palette[cell.palette];
        const colour = parseRgba(entry?.renderColour);

        if (!entry || colour === null || cell.amount <= 0) {
            continue;
        }

        const left = px(cell.column);
        const top = px(cell.row);
        const width = px(cell.column + 1) - left;
        const height = px(cell.row + 1) - top;

        if (cell.kind === 'gas') {
            ctx.fillStyle = rgbaCss([
                colour[0],
                colour[1],
                colour[2],
                Math.round(
                    255 * fluidAlpha(colour[3], 'gas', false) * cell.amount,
                ),
            ]);
            ctx.fillRect(left, top, width, height);
            continue;
        }

        const filled = Math.max(1, Math.round(height * cell.amount));
        const alpha = Math.round(
            255 * fluidAlpha(colour[3], 'liquid', entry.opaqueLiquid),
        );
        ctx.fillStyle = rgbaCss([colour[0], colour[1], colour[2], alpha]);
        ctx.fillRect(left, top + height - filled, width, filled);

        const flecks = Math.round(
            patternFor(entry.substanceTexture).flecks * FLECKS_PER_CELL,
        );

        if (flecks > 0) {
            const { x, y } = localToCell(cell.column, cell.row, box);
            const size = Math.max(1, Math.round(paint.dpr * 1.5));
            ctx.fillStyle = FLECK_STYLE;

            for (let fleck = 0; fleck < flecks; fleck++) {
                const fx = variation(x * 31 + fleck, y, 1000) / 1000;
                const fy = variation(x, y * 17 + fleck, 1000) / 1000;
                ctx.fillRect(
                    left + Math.floor(fx * (width - size)),
                    top + height - filled + Math.floor(fy * (filled - size)),
                    size,
                    size,
                );
            }
        }
    }
}

function paintTiles(
    paint: TerrainPaint,
    tiles: ZoneTileData[],
    inks: ZoneMapInks,
    onSpriteLoad: () => void,
): void {
    const { ctx, box, px, cellPx, dpr } = paint;

    for (const tile of tiles) {
        if (layerIndex(tile.x, tile.y, box) === null) {
            continue;
        }

        const { column, row } = cellToLocal(tile.x, tile.y, box);
        const sprite = tileSprite(tile, onSpriteLoad);
        const left = px(column);
        const top = px(row);
        const width = px(column + 1) - left;

        if (sprite) {
            const scale = (cellPx * dpr) / sprite.geometry.cell.w;
            ctx.drawImage(
                sprite.image,
                left - sprite.geometry.cell.x * scale,
                top - sprite.geometry.cell.y * scale,
                sprite.geometry.canvas.w * scale,
                sprite.geometry.canvas.h * scale,
            );
            continue;
        }

        ctx.fillStyle = rgbCss(inks.tile);
        ctx.fillRect(left, top, width, px(row + 1) - top);

        if ((tile.connections & 4) === 0) {
            ctx.fillStyle = rgbCss(inks.tileEdge);
            ctx.fillRect(left, top, width, Math.max(1, Math.round(dpr * 2)));
        }
    }
}

/** 1-device-pixel lines at every cell edge. */
function paintGrid(paint: TerrainPaint, inks: ZoneMapInks): void {
    const { ctx, box, px } = paint;
    const { width, height } = ctx.canvas;
    ctx.fillStyle = rgbCss(inks.hatch);

    for (let column = 0; column <= box.width; column++) {
        ctx.fillRect(Math.min(px(column), width - 1), 0, 1, height);
    }

    for (let row = 0; row <= box.height; row++) {
        ctx.fillRect(0, Math.min(px(row), height - 1), width, 1);
    }
}

function terrainPaint(
    ctx: CanvasRenderingContext2D,
    box: ZoneBoxData,
    cellPx: number,
    dpr: number,
    inks: ZoneMapInks,
): TerrainPaint {
    return {
        ctx,
        box,
        cellPx,
        dpr,
        px: (local) => Math.round(local * cellPx * dpr),
        neutral: rgbCss(inks.neutral),
        stripe: stripePattern(ctx, rgbCss(inks.hatch), dpr),
    };
}

/**
 * Paints the map box. Growth, materials and problems: static fluids unless
 * the animated layer draws them, fallbacks for elements without render
 * data, then textured solids with the game's edge masks on top (the game
 * draws ground over liquids), then tiles. Temperature: the mode's overlay
 * colour per cell (known cells cleared to the backdrop when the palette a
 * mode needs is missing), tiles faint. Unknown cells stay hatched; known cells
 * that are not solid show the map backdrop under their fluid, never a page
 * ink mixed into their colour.
 *
 * With `solidsCanvas`, the paint splits in two passes so the animated fluid
 * canvas can sit between them: `canvas` gets the base (hatch, cleared known
 * cells, static fluids or temperature colours), `solidsCanvas` the rest
 * (fallbacks, solids, tiles, problems veil, grid) over a transparent
 * background. Returns the paint time in milliseconds.
 */
export function paintZoneMap(
    canvas: HTMLCanvasElement,
    input: {
        box: ZoneBoxData;
        terrain: ZoneTerrainData | null;
        tiles: ZoneTileData[] | null;
        cellPx: number;
        dpr: number;
        layer: ZoneMapLayer;
        inks: ZoneMapInks;
        onSpriteLoad: () => void;
        temperatureMode: TemperatureMode;
        palette: OverlayPalette | null;
        assets: RenderAssets;
        fluidsAnimated: boolean;
        showGrid: boolean;
        solidsCanvas?: HTMLCanvasElement | null;
    },
): number {
    const started = performance.now();
    const { box, terrain, tiles, cellPx, dpr, layer, inks } = input;
    const ctx = canvas.getContext('2d');
    const solidsCanvas = input.solidsCanvas ?? canvas;
    const solidsCtx =
        solidsCanvas === canvas ? ctx : solidsCanvas.getContext('2d');

    if (!ctx || !solidsCtx) {
        return 0;
    }

    const width = Math.round(box.width * cellPx * dpr);
    const height = Math.round(box.height * cellPx * dpr);
    canvas.width = width;
    canvas.height = height;

    if (solidsCanvas !== canvas) {
        // Setting the size also clears the canvas to transparent.
        solidsCanvas.width = width;
        solidsCanvas.height = height;
    }

    const paint = terrainPaint(ctx, box, cellPx, dpr, inks);
    const solids =
        solidsCtx === ctx
            ? paint
            : terrainPaint(solidsCtx, box, cellPx, dpr, inks);

    // region Base
    ctx.fillStyle = hatchPattern(ctx, inks, dpr);
    ctx.fillRect(0, 0, width, height);

    if (terrain && layer === 'temperature') {
        paintTemperatures(paint, terrain, input.temperatureMode, input.palette);
    } else if (terrain) {
        forEachKnownCell(terrain, box, (_palette, column, row) => {
            const left = paint.px(column);
            const top = paint.px(row);
            ctx.clearRect(
                left,
                top,
                paint.px(column + 1) - left,
                paint.px(row + 1) - top,
            );
        });

        // Fluids first: the game draws ground over liquids, so the solids'
        // organic edges (half a cell of spill) stay on top of them.
        if (!input.fluidsAnimated) {
            paintStaticFluids(paint, terrain);
        }
    }
    // endregion

    // region Solids
    if (terrain && layer !== 'temperature') {
        paintFallbackCells(solids, terrain);
        paintSolidTerrain(solids, terrain, input.assets);
    }
    // endregion

    // region Tiles
    solidsCtx.globalAlpha =
        layer === 'temperature' ? TILE_ALPHA_ON_TEMPERATURE : 1;
    paintTiles(solids, tiles ?? [], inks, input.onSpriteLoad);
    solidsCtx.globalAlpha = 1;
    // endregion

    // region Problems veil
    if (layer === 'problems') {
        solidsCtx.fillStyle = rgbCss(inks.base);
        solidsCtx.globalAlpha = PROBLEMS_VEIL_ALPHA;
        solidsCtx.fillRect(0, 0, width, height);
        solidsCtx.globalAlpha = 1;
    }
    // endregion

    // region Grid
    if (input.showGrid) {
        paintGrid(solids, inks);
    }
    // endregion

    return performance.now() - started;
}

// endregion

// region Inks

/** Resolves a CSS colour (oklch tokens included) to sRGB through a 1×1 canvas. */
export function resolveRgb(cssColour: string, fallback: Rgb): Rgb {
    const probe = document.createElement('canvas');
    probe.width = 1;
    probe.height = 1;
    const ctx = probe.getContext('2d', { willReadFrequently: true });

    if (!ctx || cssColour.trim() === '') {
        return fallback;
    }

    ctx.fillStyle = cssColour;
    ctx.fillRect(0, 0, 1, 1);
    const [red = 0, green = 0, blue = 0] = ctx.getImageData(0, 0, 1, 1).data;

    return [red, green, blue];
}

export function readInks(element: Element): ZoneMapInks {
    const style = getComputedStyle(element);
    const token = (name: string, fallback: Rgb): Rgb =>
        resolveRgb(style.getPropertyValue(name), fallback);

    return {
        base: token('--ink-0', [17, 27, 33]),
        unknown: token('--ink-1', [24, 35, 42]),
        hatch: token('--line', [52, 66, 75]),
        neutral: token('--ink-2', [31, 43, 51]),
        tile: token('--ink-3', [41, 55, 64]),
        tileEdge: token('--text-3', [140, 155, 165]),
    };
}

// endregion
