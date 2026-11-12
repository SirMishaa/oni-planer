import { describe, expect, it } from 'vitest';
import type { ZoneBoxData, ZoneTerrainData } from '@/types/App/Data/data.d';
import { fluidCells, gasStrength, liquidFill } from './zoneFluids';

const entry = (
    state: 'solid' | 'liquid' | 'gas',
    maxMass: number | null,
): ZoneTerrainData['palette'][number] => ({
    elementId: null,
    colour: null,
    name: null,
    state,
    renderColour: null,
    substanceTexture: null,
    usesCaustics: false,
    glows: false,
    metallic: false,
    opaqueLiquid: false,
    scrollSpeed: null,
    maxMass,
    terrainTexture: null,
    lowTemp: null,
    highTemp: null,
});

const box: ZoneBoxData = { x: 0, y: 0, width: 1, height: 3 };

const column = (element: number[], mass: number[]): ZoneTerrainData => ({
    box,
    palette: [entry('liquid', 1000), entry('gas', null)],
    element,
    mass,
    temperature: null,
    previousTemperature: null,
    previousSeconds: null,
});

describe('liquidFill', () => {
    it('clamps the fill of an over-pressurised liquid', () => {
        expect(liquidFill(1500, 1000)).toBe(1);
    });

    it('fills a cell fully when max mass is unknown', () => {
        expect(liquidFill(10, null)).toBe(1);
        expect(liquidFill(null, 1000)).toBe(0);
    });
});

describe('gasStrength', () => {
    it('scales gas tint with mass', () => {
        expect(gasStrength(1)).toBe(0.5);
        expect(gasStrength(null)).toBe(0);
    });
});

describe('fluidCells', () => {
    // y ascending: bottom, middle, top
    const cells = fluidCells(column([0, 0, 0], [800, 300, 400]), box);

    it('draws a liquid under the same liquid full', () => {
        expect(cells.find((cell) => cell.row === 2)?.amount).toBe(1);
        expect(cells.find((cell) => cell.row === 1)?.amount).toBe(1);
    });

    it('flags the top of a liquid body as surface', () => {
        const top = cells.find((cell) => cell.row === 0);

        expect(top).toMatchObject({
            kind: 'liquid',
            surface: true,
            amount: 0.4,
        });
        expect(cells.filter((cell) => cell.surface)).toHaveLength(1);
    });

    it('skips cells outside the map box and non-fluid cells', () => {
        const small: ZoneBoxData = { x: 0, y: 1, width: 1, height: 1 };

        expect(fluidCells(column([0, 1, -1], [1, 1, 1]), small)).toEqual([
            expect.objectContaining({ column: 0, row: 0, kind: 'gas' }),
        ]);
    });
});
