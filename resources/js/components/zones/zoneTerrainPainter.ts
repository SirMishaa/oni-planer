import type {
    ZoneBoxData,
    ZoneTerrainData,
    ZoneTerrainElementData,
} from '@/types/App/Data/data.d';
import { layerIndex } from './zoneMapGeometry';
import type { LoadedImage, RenderAssets } from './zoneRenderAssets';
import { parseRgba } from './zoneTemperature';
import { cornerQuads, type UvQuad, variation } from './zoneTerrainEdges';

/** Cells spanned by one tile of a terrain texture (tunable). */
export const TERRAIN_TEXTURE_CELLS = 4;

/**
 * Atlas UVs follow Unity (v grows up) while the exported image stores its
 * top row first, so v is flipped to read pixels.
 */
const MASK_ATLAS_FLIP_V = true;

/**
 * The game's dark rim along terrain edges lives in the mask atlas itself:
 * its alpha is the mask, its colour is white inside and ink along the edge.
 * The texture is multiplied by that colour, so the rim comes from the game.
 */

/** What a palette entry draws as. */
export type CellKind = 'solid' | 'fluid' | 'empty' | 'fallback';

/** Shared painting state of one paint. */
export type TerrainPaint = {
    ctx: CanvasRenderingContext2D;
    box: ZoneBoxData;
    cellPx: number;
    dpr: number;
    /** Device pixel of a local coordinate in cells (fractions allowed), rounded so cells never leave seams. */
    px: (local: number) => number;
    /** `--ink-2` as CSS. */
    neutral: string;
    /** 45° stripe in `--line` marking a fallback. */
    stripe: CanvasPattern | string;
};

/** A corner quad of one solid, centred on the vertex at local (column, row) of world (x, y). */
type VertexQuad = {
    x: number;
    y: number;
    column: number;
    row: number;
    mask: number;
};

// region Classification

export function cellKind(entry: ZoneTerrainElementData | undefined): CellKind {
    if (!entry) {
        return 'fallback';
    }

    if (entry.state === 'special') {
        return 'empty';
    }

    if (
        entry.state === 'solid' &&
        (entry.renderColour !== null || entry.terrainTexture !== null)
    ) {
        return 'solid';
    }

    if (
        (entry.state === 'liquid' || entry.state === 'gas') &&
        parseRgba(entry.renderColour) !== null
    ) {
        return 'fluid';
    }

    return 'fallback';
}

/**
 * Render order of each palette entry: rank of its element id string among the
 * solids (sorted by id, not the game's substance index); null for the rest.
 */
export function solidOrders(
    palette: readonly ZoneTerrainElementData[],
): (number | null)[] {
    const ids = [
        ...new Set(
            palette
                .filter((entry) => cellKind(entry) === 'solid')
                .map((entry) => entry.elementId ?? ''),
        ),
    ].sort();

    return palette.map((entry) =>
        cellKind(entry) === 'solid' ? ids.indexOf(entry.elementId ?? '') : null,
    );
}

// endregion

// region Canvas helpers

export function createCanvas(width: number, height: number): HTMLCanvasElement {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    return canvas;
}

/** Transparent tile of 45° lines, so the flat colour under it stays readable. */
export function stripePattern(
    ctx: CanvasRenderingContext2D,
    colour: string,
    dpr: number,
): CanvasPattern | string {
    const size = Math.max(6, Math.round(10 * dpr));
    const tile = createCanvas(size, size);
    const tileCtx = tile.getContext('2d');

    if (!tileCtx) {
        return colour;
    }

    tileCtx.strokeStyle = colour;
    tileCtx.lineWidth = Math.max(1, dpr * 1.5);
    tileCtx.beginPath();
    tileCtx.moveTo(0, 0);
    tileCtx.lineTo(size, size);
    tileCtx.moveTo(-size / 2, size / 2);
    tileCtx.lineTo(size / 2, size * 1.5);
    tileCtx.moveTo(size / 2, -size / 2);
    tileCtx.lineTo(size * 1.5, size / 2);
    tileCtx.stroke();

    return ctx.createPattern(tile, 'repeat') ?? colour;
}

/** Flat fallback fill of an entry: its render colour, opaque, else the neutral ink. */
function flatColour(
    entry: ZoneTerrainElementData | undefined,
    neutral: string,
): string {
    const colour = parseRgba(entry?.renderColour);

    return colour === null
        ? neutral
        : `rgb(${colour[0]} ${colour[1]} ${colour[2]})`;
}

// endregion

// region Fallback cells

/** Cells whose palette entry has no usable render data: neutral ink and a stripe, never a hole. */
export function paintFallbackCells(
    paint: TerrainPaint,
    terrain: ZoneTerrainData,
): void {
    const { ctx, box, px } = paint;
    const kinds = terrain.palette.map(cellKind);

    forEachKnownCell(terrain, box, (palette, column, row) => {
        if ((kinds[palette] ?? 'fallback') !== 'fallback') {
            return;
        }

        const left = px(column);
        const top = px(row);
        const width = px(column + 1) - left;
        const height = px(row + 1) - top;
        ctx.fillStyle = paint.neutral;
        ctx.fillRect(left, top, width, height);
        ctx.fillStyle = paint.stripe;
        ctx.fillRect(left, top, width, height);
    });
}

/** Calls `visit` for every cell of the map box with a known palette index. */
export function forEachKnownCell(
    terrain: ZoneTerrainData,
    box: ZoneBoxData,
    visit: (
        palette: number,
        column: number,
        row: number,
        index: number,
    ) => void,
): void {
    const elements = terrain.element;

    if (!elements) {
        return;
    }

    const source = terrain.box;

    for (let index = 0; index < source.width * source.height; index++) {
        const palette = elements[index];

        if (palette === undefined || palette < 0) {
            continue;
        }

        const x = source.x + (index % source.width);
        const y = source.y + Math.floor(index / source.width);

        if (layerIndex(x, y, box) === null) {
            continue;
        }

        visit(palette, x - box.x, box.y + box.height - 1 - y, index);
    }
}

// endregion

// region Solid terrain

/**
 * Solid terrain like `GroundRenderer`: for every vertex of the map, the
 * corner masks of each solid meeting there, then per solid (ascending
 * render order) its texture clipped by those masks, with an ink rim.
 */
export function paintSolidTerrain(
    paint: TerrainPaint,
    terrain: ZoneTerrainData,
    assets: RenderAssets,
): void {
    const elements = terrain.element;

    if (!elements) {
        return;
    }

    const { box } = paint;
    const orders = solidOrders(terrain.palette);
    const entryOfOrder = new Map<number, ZoneTerrainElementData>();

    orders.forEach((order, palette) => {
        const entry = terrain.palette[palette];

        if (order !== null && entry && !entryOfOrder.has(order)) {
            entryOfOrder.set(order, entry);
        }
    });

    if (entryOfOrder.size === 0) {
        return;
    }

    const solidAt = (x: number, y: number): number | null => {
        const index = layerIndex(x, y, terrain.box);
        const palette = index === null ? undefined : elements[index];

        return palette === undefined || palette < 0
            ? null
            : (orders[palette] ?? null);
    };

    const quadsByOrder = new Map<number, VertexQuad[]>();

    for (let y = box.y; y <= box.y + box.height; y++) {
        for (let x = box.x; x <= box.x + box.width; x++) {
            for (const { order, mask } of cornerQuads(solidAt, x, y)) {
                let quads = quadsByOrder.get(order);

                if (!quads) {
                    quads = [];
                    quadsByOrder.set(order, quads);
                }

                quads.push({
                    x,
                    y,
                    column: x - box.x,
                    row: box.y + box.height - y,
                    mask,
                });
            }
        }
    }

    const scratch = { mask: createCanvas(1, 1) };

    for (const order of [...quadsByOrder.keys()].sort((a, b) => a - b)) {
        paintSolidLayer(
            paint,
            entryOfOrder.get(order),
            quadsByOrder.get(order) ?? [],
            assets,
            scratch,
        );
    }
}

function paintSolidLayer(
    paint: TerrainPaint,
    entry: ZoneTerrainElementData | undefined,
    quads: VertexQuad[],
    assets: RenderAssets,
    scratch: { mask: HTMLCanvasElement },
): void {
    const { ctx, px } = paint;
    const canvasWidth = ctx.canvas.width;
    const canvasHeight = ctx.canvas.height;
    let minColumn = Infinity;
    let maxColumn = -Infinity;
    let minRow = Infinity;
    let maxRow = -Infinity;

    for (const quad of quads) {
        minColumn = Math.min(minColumn, quad.column);
        maxColumn = Math.max(maxColumn, quad.column);
        minRow = Math.min(minRow, quad.row);
        maxRow = Math.max(maxRow, quad.row);
    }

    const left = Math.max(0, px(minColumn - 0.5));
    const top = Math.max(0, px(minRow - 0.5));
    const width = Math.min(canvasWidth, px(maxColumn + 0.5)) - left;
    const height = Math.min(canvasHeight, px(maxRow + 0.5)) - top;
    const maskCtx =
        width > 0 && height > 0 ? resize(scratch.mask, width, height) : null;

    if (!maskCtx) {
        return;
    }

    // region Mask
    for (const quad of quads) {
        drawMaskQuad(maskCtx, paint, quad, assets, left, top);
    }
    // endregion

    // region Texture
    maskCtx.globalCompositeOperation = 'source-in';
    const texture = entry?.terrainTexture
        ? assets.texture(entry.terrainTexture)
        : null;
    const pattern = texture
        ? texturePattern(maskCtx, paint, texture, left, top)
        : null;

    if (pattern) {
        maskCtx.fillStyle = pattern;
        maskCtx.fillRect(0, 0, width, height);
    } else {
        maskCtx.fillStyle = flatColour(entry, paint.neutral);
        maskCtx.fillRect(0, 0, width, height);
        maskCtx.globalCompositeOperation = 'source-atop';
        maskCtx.fillStyle = paint.stripe;
        maskCtx.fillRect(0, 0, width, height);
    }

    // region Ink rim (the atlas colour: white inside, ink along the edge)
    maskCtx.globalCompositeOperation = 'multiply';

    for (const quad of quads) {
        drawMaskQuad(maskCtx, paint, quad, assets, left, top);
    }
    // endregion

    maskCtx.globalCompositeOperation = 'source-over';
    ctx.drawImage(scratch.mask, left, top);
    // endregion
}

/** Resizes (and so clears) a scratch canvas; its context in default state. */
function resize(
    canvas: HTMLCanvasElement,
    width: number,
    height: number,
): CanvasRenderingContext2D | null {
    canvas.width = width;
    canvas.height = height;

    return canvas.getContext('2d');
}

/** A repeating texture aligned to world cells: tile origin at world (0, 0). */
function texturePattern(
    ctx: CanvasRenderingContext2D,
    paint: TerrainPaint,
    texture: LoadedImage,
    left: number,
    top: number,
): CanvasPattern | null {
    const pattern = ctx.createPattern(texture.image, 'repeat');

    if (!pattern || typeof DOMMatrix === 'undefined') {
        return pattern;
    }

    const cell = paint.cellPx * paint.dpr;
    const scale = (TERRAIN_TEXTURE_CELLS * cell) / texture.width;
    const originX = -paint.box.x * cell - left;
    const originY = (paint.box.y + paint.box.height) * cell - top;

    pattern.setTransform(
        new DOMMatrix().translate(originX, originY).scale(scale),
    );

    return pattern;
}

/**
 * One corner mask centred on its vertex: the atlas tile (variation picked
 * per vertex) when loaded, else the quarter cells of the set corners, which
 * adds up to square cells.
 */
function drawMaskQuad(
    ctx: CanvasRenderingContext2D,
    paint: TerrainPaint,
    quad: VertexQuad,
    assets: RenderAssets,
    offsetX: number,
    offsetY: number,
): void {
    const { px } = paint;
    const left = px(quad.column - 0.5) - offsetX;
    const right = px(quad.column + 0.5) - offsetX;
    const top = px(quad.row - 0.5) - offsetY;
    const bottom = px(quad.row + 0.5) - offsetY;
    const variations = assets.masks?.[quad.mask] ?? null;

    if (assets.maskAtlas && variations && variations.length > 0) {
        const uvs = variations[variation(quad.x, quad.y, variations.length)];

        if (
            uvs &&
            drawAtlasQuad(
                ctx,
                assets.maskAtlas,
                uvs,
                left,
                top,
                right - left,
                bottom - top,
            )
        ) {
            return;
        }
    }

    const middleX = px(quad.column) - offsetX;
    const middleY = px(quad.row) - offsetY;
    ctx.fillStyle = '#fff';

    if (quad.mask & 8) {
        ctx.fillRect(left, top, middleX - left, middleY - top);
    }

    if (quad.mask & 4) {
        ctx.fillRect(middleX, top, right - middleX, middleY - top);
    }

    if (quad.mask & 2) {
        ctx.fillRect(left, middleY, middleX - left, bottom - middleY);
    }

    if (quad.mask & 1) {
        ctx.fillRect(middleX, middleY, right - middleX, bottom - middleY);
    }
}

/**
 * Draws an atlas tile into a destination square with the tile's own corner
 * order (its UVs may be rotated): the affine map that sends the tile's
 * top-left, top-right and bottom-left pixels to the square's corners.
 */
function drawAtlasQuad(
    ctx: CanvasRenderingContext2D,
    atlas: LoadedImage,
    uvs: UvQuad,
    left: number,
    top: number,
    width: number,
    height: number,
): boolean {
    const pixel = ([u, v]: [number, number]): [number, number] => [
        u * atlas.width,
        (MASK_ATLAS_FLIP_V ? 1 - v : v) * atlas.height,
    ];
    const [tlX, tlY] = pixel(uvs.tl);
    const [trX, trY] = pixel(uvs.tr);
    const [blX, blY] = pixel(uvs.bl);
    const acrossX = trX - tlX;
    const acrossY = trY - tlY;
    const downX = blX - tlX;
    const downY = blY - tlY;
    const determinant = acrossX * downY - downX * acrossY;

    if (Math.abs(determinant) < 1e-9) {
        return false;
    }

    const a = (width * downY) / determinant;
    const c = (-width * downX) / determinant;
    const b = (-height * acrossY) / determinant;
    const d = (height * acrossX) / determinant;
    const xs = [tlX, trX, blX, trX + blX - tlX];
    const ys = [tlY, trY, blY, trY + blY - tlY];
    const sourceX = Math.min(...xs);
    const sourceY = Math.min(...ys);
    const sourceWidth = Math.max(...xs) - sourceX;
    const sourceHeight = Math.max(...ys) - sourceY;

    ctx.setTransform(
        a,
        b,
        c,
        d,
        left - (a * tlX + c * tlY),
        top - (b * tlX + d * tlY),
    );
    ctx.drawImage(
        atlas.image,
        sourceX,
        sourceY,
        sourceWidth,
        sourceHeight,
        sourceX,
        sourceY,
        sourceWidth,
        sourceHeight,
    );
    ctx.setTransform(1, 0, 0, 1, 0, 0);

    return true;
}

// endregion
