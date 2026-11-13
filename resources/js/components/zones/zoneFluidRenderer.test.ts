import { describe, expect, it, vi } from 'vitest';
import type {
    ZoneBoxData,
    ZoneTerrainElementData,
} from '@/types/App/Data/data.d';
import {
    CELL_LIQUID_BIT,
    CELL_SURFACE_BIT,
    PALETTE_TEXELS,
    createFluidRenderer,
    packCells,
    packPalette,
    shouldAnimate,
} from './zoneFluidRenderer';
import { type FluidCell, fluidAlpha } from './zoneFluids';

const entry = (
    overrides: Partial<ZoneTerrainElementData>,
): ZoneTerrainElementData => ({
    elementId: null,
    colour: null,
    name: null,
    state: 'liquid',
    renderColour: null,
    substanceTexture: null,
    usesCaustics: false,
    glows: false,
    metallic: false,
    opaqueLiquid: false,
    scrollSpeed: null,
    maxMass: null,
    terrainTexture: null,
    lowTemp: null,
    highTemp: null,
    ...overrides,
});

describe('packCells', () => {
    const box: ZoneBoxData = { x: 0, y: 0, width: 2, height: 1 };

    it('packs palette index, amount and flags into RGBA bytes', () => {
        const cells: FluidCell[] = [
            {
                column: 0,
                row: 0,
                palette: 3,
                kind: 'liquid',
                amount: 0.5,
                surface: true,
            },
        ];

        expect(Array.from(packCells(cells, box))).toEqual([
            3,
            128,
            CELL_SURFACE_BIT | CELL_LIQUID_BIT,
            255,
            0,
            0,
            0,
            0,
        ]);
    });

    it('reuses the buffer and clears cells that left', () => {
        const gas: FluidCell = {
            column: 1,
            row: 0,
            palette: 1,
            kind: 'gas',
            amount: 1,
            surface: false,
        };
        const buffer = packCells([gas], box);
        const again = packCells([], box, buffer);

        expect(again).toBe(buffer);
        expect(Array.from(again)).toEqual([0, 0, 0, 0, 0, 0, 0, 0]);
    });

    it('skips cells outside the box or past the byte palette range', () => {
        const outside: FluidCell = {
            column: 5,
            row: 0,
            palette: 0,
            kind: 'gas',
            amount: 1,
            surface: false,
        };
        const wide: FluidCell = { ...outside, column: 0, palette: 256 };

        expect(Array.from(packCells([outside, wide], box))).toEqual([
            0, 0, 0, 0, 0, 0, 0, 0,
        ]);
    });
});

describe('packPalette', () => {
    it('reads colour, opacity and effects from the palette properties', () => {
        const data = packPalette([
            entry({
                renderColour: '#55bed280',
                opaqueLiquid: true,
                usesCaustics: true,
                scrollSpeed: 0.02,
                substanceTexture: 'Magma',
            }),
            entry({ state: 'gas', renderColour: '#ff000080', glows: true }),
        ]);
        const texel = (index: number, offset: number): number[] =>
            Array.from(
                data.slice(
                    (index * PALETTE_TEXELS + offset) * 4,
                    (index * PALETTE_TEXELS + offset + 1) * 4,
                ),
            );

        expect(texel(0, 0)).toEqual(
            [0x55 / 255, 0xbe / 255, 0xd2 / 255, 1].map(Math.fround),
        );
        expect(texel(0, 1)).toEqual([2, 0.45, 0.2, 1].map(Math.fround));
        expect(texel(0, 2)).toEqual([1, 0, 0, 0.02].map(Math.fround));
        expect(texel(1, 0)[3]).toBe(
            Math.fround(fluidAlpha(0x80, 'gas', false)),
        );
        expect(texel(1, 2)).toEqual([0, 1, 0, 0]);
    });

    it('leaves an entry without render colour transparent', () => {
        const data = packPalette([entry({})]);

        expect(data[3]).toBe(0);
    });
});

describe('shouldAnimate', () => {
    it('animates only a visible map on screen without reduced motion', () => {
        const visible = { hidden: false, onScreen: true, reducedMotion: false };

        expect(shouldAnimate(visible)).toBe(true);
        expect(shouldAnimate({ ...visible, hidden: true })).toBe(false);
        expect(shouldAnimate({ ...visible, onScreen: false })).toBe(false);
        expect(shouldAnimate({ ...visible, reducedMotion: true })).toBe(false);
    });
});

describe('createFluidRenderer', () => {
    it('returns null without WebGL2', () => {
        const canvas = {
            getContext: () => null,
        } as unknown as HTMLCanvasElement;

        expect(createFluidRenderer(canvas)).toBeNull();
    });

    it('returns null and warns when a shader fails to compile', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const gl = new Proxy(
            {
                createShader: () => ({}),
                getShaderParameter: () => false,
                getShaderInfoLog: () => 'syntax error',
                deleteShader: () => {},
            } as Record<string, unknown>,
            { get: (target, key) => target[key as string] ?? (() => null) },
        );
        const canvas = {
            getContext: () => gl,
        } as unknown as HTMLCanvasElement;

        expect(createFluidRenderer(canvas)).toBeNull();
        expect(warn).toHaveBeenCalledWith(
            expect.stringContaining('syntax error'),
        );
        warn.mockRestore();
    });
});
