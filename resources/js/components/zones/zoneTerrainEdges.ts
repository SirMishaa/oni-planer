/** Corner UVs of one atlas tile, as `GroundMasks.UVData`. */
export type UvQuad = {
    bl: [number, number];
    br: [number, number];
    tl: [number, number];
    tr: [number, number];
};

type AtlasItem = { name: string; uvBox: [number, number, number, number] };

// region Mask atlas

function normaliseBiome(biome: string): string {
    return biome.toLowerCase().replaceAll('_', '');
}

/** Rotates a source tile's UVs the way `BiomeMaskData.GetNonNullRotationUVs` does. */
function rotateUvs(uvs: UvQuad, rotation: number): UvQuad {
    switch (rotation) {
        case 2:
            return { bl: uvs.br, br: uvs.tr, tl: uvs.bl, tr: uvs.tl };
        case 1:
            return { bl: uvs.tr, br: uvs.tl, tl: uvs.br, tr: uvs.bl };
        default:
            return { bl: uvs.tl, br: uvs.bl, tl: uvs.tr, tr: uvs.br };
    }
}

/**
 * The 16 corner masks of a biome, indexed by their 4-bit mask, each with its
 * UV variations. Port of `GroundMasks.Initialize` and `GenerateRotations`;
 * masks without an atlas tile come from rotating a source tile, mask 0 stays null.
 */
export function parseMaskAtlas(
    items: AtlasItem[],
    biome: string,
): (UvQuad[] | null)[] {
    const wanted = normaliseBiome(biome);
    const sources: (UvQuad[] | null)[] = Array.from({ length: 16 }, () => null);

    for (const item of items) {
        const slash = item.name.indexOf('/');

        if (slash < 0 || normaliseBiome(item.name.slice(0, slash)) !== wanted) {
            continue;
        }

        const mask = Number.parseInt(item.name.slice(slash + 1, slash + 5), 2);

        if (Number.isNaN(mask) || mask < 0 || mask > 15) {
            continue;
        }

        const [x, y, z, w] = item.uvBox;
        const quad: UvQuad = { bl: [x, w], br: [z, w], tl: [x, y], tr: [z, y] };

        (sources[mask] ??= []).push(quad);
    }

    const tiles = [...sources];

    for (let mask = 1; mask < 15; mask++) {
        if (sources[mask] === null) {
            tiles[mask] = rotatedVariations(sources, mask);
        }
    }

    return tiles;
}

function rotatedVariations(
    sources: (UvQuad[] | null)[],
    destination: number,
): UvQuad[] | null {
    let mask = destination;

    for (let rotation = 0; rotation < 3; rotation++) {
        const next =
            (((mask & 8) >> 3) << 2) |
            ((mask & 4) >> 2) |
            (((mask & 2) >> 1) << 3) |
            ((mask & 1) << 1);
        const source = sources[next];

        if (source) {
            return source.map((uvs) => rotateUvs(uvs, rotation));
        }

        mask = next;
    }

    return null;
}

// endregion

// region Vertices

/**
 * The quads to draw at the vertex shared by cells (x-1, y-1), (x, y-1),
 * (x-1, y) and (x, y), as in `GroundRenderer.WorldChunk.Rebuild`: one per
 * distinct solid render order, ascending. `solidAt` returns that order or null.
 */
export function cornerQuads(
    solidAt: (x: number, y: number) => number | null,
    x: number,
    y: number,
): { order: number; mask: number }[] {
    const corners = [
        solidAt(x - 1, y - 1) ?? -1,
        solidAt(x, y - 1) ?? -1,
        solidAt(x - 1, y) ?? -1,
        solidAt(x, y) ?? -1,
    ];
    const orders = [...new Set(corners.filter((order) => order >= 0))].sort(
        (a, b) => a - b,
    );
    const quads: { order: number; mask: number }[] = [];

    for (const order of orders) {
        const mask =
            ((corners[2]! >= order ? 1 : 0) << 3) |
            ((corners[3]! >= order ? 1 : 0) << 2) |
            ((corners[0]! >= order ? 1 : 0) << 1) |
            (corners[1]! >= order ? 1 : 0);

        if (mask > 0) {
            quads.push({ order, mask });
        }
    }

    return quads;
}

/** Deterministic variation index in `[0, count)` for a vertex, stable across repaints. */
export function variation(x: number, y: number, count: number): number {
    let hash = Math.imul(x | 0, 0x27d4eb2d) ^ Math.imul(y | 0, 0x165667b1);

    hash = Math.imul(hash ^ (hash >>> 15), 0x85ebca6b);
    hash = Math.imul(hash ^ (hash >>> 13), 0xc2b2ae35);
    hash ^= hash >>> 16;

    const random = (hash >>> 0) / 0x100000000;

    return Math.min(count - 1, Math.floor(count * random));
}

// endregion
