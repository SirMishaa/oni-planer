import type {
    ZoneBoxData,
    ZoneTerrainData,
    ZoneTileData,
} from '@/types/App/Data/data.d';
import {
    hexToRgb,
    layerIndex,
    mixSrgb,
    type Rgb,
    rgbCss,
    temperatureColour,
    temperatureRange,
    terrainMix,
    cellToLocal,
} from './zoneMapGeometry';

export type ZoneMapLayer = 'growth' | 'materials' | 'temperature' | 'problems';

/** Map inks read from the page's colour tokens. */
export type ZoneMapInks = {
    /** `--ink-0`: what element colours are mixed into. */
    base: Rgb;
    /** `--ink-1`: background of unknown cells. */
    unknown: Rgb;
    /** `--line`: hatching of unknown cells. */
    hatch: Rgb;
    /** `--ink-2`: a known element without a colour. */
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
/** Share of the temperature colour over the ink. */
const TEMPERATURE_MIX = 0.75;
const TEMPERATURE_BUCKETS = 64;
/** Tiles stay faint under the temperature layer so the colours read. */
const TILE_ALPHA_ON_TEMPERATURE = 0.3;
/** Problems layer: veil over the terrain so the pins stand out. */
const PROBLEMS_VEIL_ALPHA = 0.55;

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
        image.onload = onLoad;
        image.onerror = () => tileImages.set(src, null);
        image.src = src;
        tileImages.set(src, image);
    }

    const image = tileImages.get(src);

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

/** Fill style of each palette entry, computed once per paint rather than per cell. */
function paletteStyles(terrain: ZoneTerrainData, inks: ZoneMapInks): string[] {
    return terrain.palette.map((entry) => {
        const colour = hexToRgb(entry.colour);

        return rgbCss(
            colour === null
                ? inks.neutral
                : mixSrgb(colour, inks.base, terrainMix(entry.state)),
        );
    });
}

function temperatureStyles(inks: ZoneMapInks): string[] {
    return Array.from({ length: TEMPERATURE_BUCKETS }, (_, bucket) =>
        rgbCss(
            mixSrgb(
                temperatureColour(bucket / (TEMPERATURE_BUCKETS - 1)),
                inks.base,
                TEMPERATURE_MIX,
            ),
        ),
    );
}

/**
 * Paints the terrain (element colours, or temperatures on that layer),
 * then the tiles, on a canvas the size of the map box. Unknown cells (no
 * slow state, an unread layer, or outside the slow state's box) stay
 * hatched, never blank. Returns the paint time in milliseconds.
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
    },
): number {
    const started = performance.now();
    const { box, terrain, tiles, cellPx, dpr, layer, inks } = input;
    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return 0;
    }

    canvas.width = Math.round(box.width * cellPx * dpr);
    canvas.height = Math.round(box.height * cellPx * dpr);

    /** Device-pixel edges of a column or row, rounded so cells never leave seams. */
    const edge = (local: number): number => Math.round(local * cellPx * dpr);
    const fillCell = (column: number, row: number): void => {
        const left = edge(column);
        const top = edge(row);
        ctx.fillRect(left, top, edge(column + 1) - left, edge(row + 1) - top);
    };

    // region Unknown background
    ctx.fillStyle = hatchPattern(ctx, inks, dpr);
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    // endregion

    // region Terrain
    const values =
        layer === 'temperature' ? terrain?.temperature : terrain?.element;

    if (terrain && values) {
        const range =
            layer === 'temperature'
                ? temperatureRange(terrain.temperature)
                : null;
        const styles =
            layer === 'temperature'
                ? temperatureStyles(inks)
                : paletteStyles(terrain, inks);
        const cells = terrain.box.width * terrain.box.height;

        for (let index = 0; index < cells; index++) {
            const value = values[index];

            if (value === null || value === undefined || value < 0) {
                continue;
            }

            const x = terrain.box.x + (index % terrain.box.width);
            const y = terrain.box.y + Math.floor(index / terrain.box.width);

            if (layerIndex(x, y, box) === null) {
                continue;
            }

            const style = range
                ? styles[
                      Math.round(
                          ((value - range.min) / (range.max - range.min)) *
                              (TEMPERATURE_BUCKETS - 1),
                      )
                  ]
                : styles[value];

            if (style === undefined) {
                continue;
            }

            const { column, row } = cellToLocal(x, y, box);
            ctx.fillStyle = style;
            fillCell(column, row);
        }
    }
    // endregion

    // region Tiles
    ctx.globalAlpha = layer === 'temperature' ? TILE_ALPHA_ON_TEMPERATURE : 1;

    for (const tile of tiles ?? []) {
        if (layerIndex(tile.x, tile.y, box) === null) {
            continue;
        }

        const { column, row } = cellToLocal(tile.x, tile.y, box);
        const sprite = tileSprite(tile, input.onSpriteLoad);

        if (sprite) {
            const scale = (cellPx * dpr) / sprite.geometry.cell.w;
            ctx.drawImage(
                sprite.image,
                edge(column) - sprite.geometry.cell.x * scale,
                edge(row) - sprite.geometry.cell.y * scale,
                sprite.geometry.canvas.w * scale,
                sprite.geometry.canvas.h * scale,
            );
            continue;
        }

        ctx.fillStyle = rgbCss(inks.tile);
        fillCell(column, row);

        if ((tile.connections & 4) === 0) {
            ctx.fillStyle = rgbCss(inks.tileEdge);
            ctx.fillRect(
                edge(column),
                edge(row),
                edge(column + 1) - edge(column),
                Math.max(1, Math.round(dpr * 2)),
            );
        }
    }

    ctx.globalAlpha = 1;
    // endregion

    // region Problems veil
    if (layer === 'problems') {
        ctx.fillStyle = rgbCss(inks.base);
        ctx.globalAlpha = PROBLEMS_VEIL_ALPHA;
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.globalAlpha = 1;
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
