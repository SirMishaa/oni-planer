import { describe, expect, it } from 'vitest';
import { cornerQuads, parseMaskAtlas, variation } from './zoneTerrainEdges';

const item = (bits: string, index: number) => ({
    name: `Sandstone/${bits}_${index}`,
    uvBox: [index, 0, index + 1, 1] as [number, number, number, number],
});

describe('parseMaskAtlas', () => {
    it('builds 16 masks from source tiles and rotations', () => {
        const masks = parseMaskAtlas(
            ['0001', '0011', '0111', '1111', '1001'].map(item),
            'SANDSTONE',
        );

        expect(masks).toHaveLength(16);
        expect(masks[0]).toBeNull();

        for (let mask = 1; mask < 16; mask++) {
            expect(masks[mask], `mask ${mask}`).not.toBeNull();
        }
    });

    it('maps the uv box and rotates a source tile', () => {
        const masks = parseMaskAtlas(
            ['0001', '0011', '0111', '1111', '1001'].map(item),
            'sandstone',
        );
        const source = masks[1]![0]!;

        expect(source).toEqual({
            bl: [0, 1],
            br: [1, 1],
            tl: [0, 0],
            tr: [1, 0],
        });
        // 0010 reaches 0001 on the third step (rotation case 2)
        expect(masks[2]![0]).toEqual({
            bl: source.br,
            br: source.tr,
            tl: source.bl,
            tr: source.tl,
        });
    });
});

describe('cornerQuads', () => {
    it('marks a vertex fully inside one solid as opaque', () => {
        expect(cornerQuads(() => 2, 5, 5)).toEqual([{ order: 2, mask: 15 }]);
    });

    it('layers two solids at a junction', () => {
        const solidAt = (_x: number, y: number) => (y === 4 ? 1 : 3);

        expect(cornerQuads(solidAt, 5, 5)).toEqual([
            { order: 1, mask: 15 },
            { order: 3, mask: 0b1100 },
        ]);
    });

    it('ignores non-solid corners', () => {
        expect(cornerQuads(() => null, 5, 5)).toEqual([]);
    });
});

describe('variation', () => {
    it('picks the same variation on every call', () => {
        expect(variation(7, 9, 4)).toBe(variation(7, 9, 4));
        expect(variation(7, 9, 1)).toBe(0);

        const picked = new Set<number>();

        for (let x = 0; x < 50; x++) {
            const value = variation(x, 3, 4);

            expect(value).toBeGreaterThanOrEqual(0);
            expect(value).toBeLessThan(4);
            picked.add(value);
        }

        expect(picked.size).toBeGreaterThan(1);
    });
});
