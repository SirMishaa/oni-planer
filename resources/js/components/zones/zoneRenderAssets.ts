import { type OverlayPalette, parseRenderJson } from './zoneTemperature';
import { parseMaskAtlas, type UvQuad } from './zoneTerrainEdges';

/** An image ready to draw, with its pixel size. */
export type LoadedImage = {
    image: CanvasImageSource;
    width: number;
    height: number;
};

export type RenderAssets = {
    /** The 16 corner masks (UV variations each), indexed by 4-bit mask; null until loaded or when missing. */
    masks: (UvQuad[] | null)[] | null;
    /** The mask atlas with its mask in the alpha channel; null until loaded or when missing. */
    maskAtlas: LoadedImage | null;
    /** The terrain texture at that URL; null while it loads or when it failed. */
    texture: (url: string) => LoadedImage | null;
};

/** No assets at all: every solid falls back to flat colours and square edges. */
export const NO_RENDER_ASSETS: RenderAssets = {
    masks: null,
    maskAtlas: null,
    texture: () => null,
};

/**
 * Which channel of `mask_atlas.webp` holds the mask. Unknown until the first
 * real in-game export: if the atlas turns out to carry it in red, switch to
 * `'red'` and the atlas is converted to an alpha mask once on load.
 */
export const MASK_CHANNEL: 'alpha' | 'red' = 'alpha';

const RENDER_JSON = '/assets/game/render.json';
const MASK_ATLAS = '/assets/game/terrain/mask_atlas.webp';

// region Render document

let overlayPalette: OverlayPalette | null = null;
let masks: (UvQuad[] | null)[] | null = null;
let maskAtlas: LoadedImage | null = null;
let request: Promise<void> | null = null;

type AtlasItem = { name: string; uvBox: [number, number, number, number] };

/** Mask atlas items of `render.json`, invalid ones dropped. */
function atlasItems(atlas: { items?: unknown } | null): AtlasItem[] {
    if (!Array.isArray(atlas?.items)) {
        return [];
    }

    return (atlas.items as { name?: unknown; uvBox?: unknown }[]).filter(
        (item): item is AtlasItem =>
            typeof item?.name === 'string' &&
            Array.isArray(item.uvBox) &&
            item.uvBox.length === 4 &&
            item.uvBox.every((value) => typeof value === 'number'),
    );
}

/** Masks of the atlas's fallback biome (the one the game uses by default), else of its first biome. */
export function parseMasks(json: unknown): (UvQuad[] | null)[] | null {
    const atlas =
        (json as { maskAtlas?: { items?: unknown; fallbackBiome?: unknown } })
            ?.maskAtlas ?? null;
    const items = atlasItems(atlas);
    const biomes = [
        typeof atlas?.fallbackBiome === 'string' ? atlas.fallbackBiome : '',
        items[0]?.name.split('/')[0] ?? '',
    ];

    for (const biome of biomes) {
        const parsed = biome === '' ? null : parseMaskAtlas(items, biome);

        if (parsed?.some((variations) => variations !== null)) {
            return parsed;
        }
    }

    return null;
}

function loadImage(src: string): Promise<HTMLImageElement | null> {
    return new Promise((resolve) => {
        const image = new Image();
        image.decoding = 'async';
        image.onload = () => resolve(image.naturalWidth > 0 ? image : null);
        image.onerror = () => resolve(null);
        image.src = src;
    });
}

/** The atlas with its mask moved to alpha when `MASK_CHANNEL` says it lives in red. */
function maskFromImage(image: HTMLImageElement): LoadedImage | null {
    const width = image.naturalWidth;
    const height = image.naturalHeight;

    if (MASK_CHANNEL === 'alpha') {
        return { image, width, height };
    }

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    if (!ctx) {
        return null;
    }

    ctx.drawImage(image, 0, 0);
    const pixels = ctx.getImageData(0, 0, width, height);

    for (let offset = 0; offset < pixels.data.length; offset += 4) {
        pixels.data[offset + 3] = pixels.data[offset] ?? 0;
        pixels.data[offset] = 255;
        pixels.data[offset + 1] = 255;
        pixels.data[offset + 2] = 255;
    }

    ctx.putImageData(pixels, 0, 0);

    return { image: canvas, width, height };
}

/**
 * Loads `render.json` (overlay palette, mask atlas items) and the mask atlas
 * once; `onReady` runs after that, also for later callers. Missing files
 * leave the assets null, so the map shows its fallbacks.
 */
export function loadRenderAssets(onReady: () => void): void {
    request ??= fetch(RENDER_JSON)
        .then((response) => (response.ok ? response.json() : null))
        .then(async (json: unknown) => {
            overlayPalette = parseRenderJson(json);
            masks = parseMasks(json);

            if (masks !== null) {
                const image = await loadImage(MASK_ATLAS);
                maskAtlas = image ? maskFromImage(image) : null;
            }
        })
        .catch(() => {
            overlayPalette = null;
            masks = null;
            maskAtlas = null;
        });

    void request.then(onReady);
}

export function getOverlayPalette(): OverlayPalette | null {
    return overlayPalette;
}

// endregion

// region Terrain textures

const textures = new Map<string, HTMLImageElement | null>();

function terrainTexture(url: string, onLoad: () => void): LoadedImage | null {
    if (!textures.has(url)) {
        const image = new Image();
        image.decoding = 'async';
        image.onload = onLoad;
        image.onerror = () => textures.set(url, null);
        image.src = url;
        textures.set(url, image);
    }

    const image = textures.get(url);

    return image && image.complete && image.naturalWidth > 0
        ? { image, width: image.naturalWidth, height: image.naturalHeight }
        : null;
}

/** The assets loaded so far; a texture that finishes loading later calls `onTextureLoad`. */
export function getRenderAssets(onTextureLoad: () => void): RenderAssets {
    return {
        masks,
        maskAtlas: masks === null ? null : maskAtlas,
        texture: (url) => terrainTexture(url, onTextureLoad),
    };
}

// endregion
