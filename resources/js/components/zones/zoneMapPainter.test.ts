import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    ZoneBoxData,
    ZoneTerrainData,
    ZoneTerrainElementData,
} from '@/types/App/Data/data.d';
import type { SubstanceTexture } from '@/types/App/Enums/data.d';
import { SUBSTANCE_TEXTURE_PATTERNS } from './substanceTexturePatterns';
import { rgbCss } from './zoneMapGeometry';
import {
    paintZoneMap,
    rgbaCss,
    type ZoneMapInks,
    type ZoneMapLayer,
} from './zoneMapPainter';
import { NO_RENDER_ASSETS } from './zoneRenderAssets';
import type { OverlayPalette, Rgba, TemperatureMode } from './zoneTemperature';

// region Canvas stub

type Call = {
    op: string;
    style: unknown;
    source: unknown;
};

/** Minimal 2D context: records fills and image draws, ignores the rest. */
class StubContext {
    fillStyle: unknown = '#000';
    strokeStyle: unknown = '#000';
    lineWidth = 1;
    globalAlpha = 1;
    globalCompositeOperation = 'source-over';
    imageSmoothingEnabled = true;
    calls: Call[] = [];

    constructor(public canvas: StubCanvas) {}

    fillRect(): void {
        this.calls.push({
            op: 'fillRect',
            style: this.fillStyle,
            source: null,
        });
    }

    fill(): void {
        this.calls.push({ op: 'fill', style: this.fillStyle, source: null });
    }

    drawImage(source: unknown): void {
        this.calls.push({ op: 'drawImage', style: null, source });
    }

    createPattern(source: unknown): { source: unknown; setTransform(): void } {
        return { source, setTransform: () => undefined };
    }

    getImageData(): { data: Uint8ClampedArray } {
        return { data: new Uint8ClampedArray(4) };
    }

    clearRect(): void {}
    beginPath(): void {}
    moveTo(): void {}
    lineTo(): void {}
    stroke(): void {}
    rect(): void {}
    save(): void {}
    restore(): void {}
    setTransform(): void {}
    resetTransform(): void {}
    translate(): void {}
}

class StubCanvas {
    width = 0;
    height = 0;
    context = new StubContext(this);

    getContext(): StubContext {
        return this.context;
    }
}

let canvases: StubCanvas[] = [];

beforeEach(() => {
    canvases = [];
    vi.stubGlobal('document', {
        createElement: () => {
            const canvas = new StubCanvas();
            canvases.push(canvas);

            return canvas;
        },
    });
});

afterEach(() => {
    vi.unstubAllGlobals();
});

const allCalls = (): Call[] =>
    canvases.flatMap((canvas) => canvas.context.calls);

// endregion

// region Fixtures

const inks: ZoneMapInks = {
    base: [1, 1, 1],
    unknown: [2, 2, 2],
    hatch: [3, 3, 3],
    neutral: [4, 4, 4],
    tile: [5, 5, 5],
    tileEdge: [6, 6, 6],
};

const box: ZoneBoxData = { x: 0, y: 0, width: 1, height: 1 };

const entry = (
    overrides: Partial<ZoneTerrainElementData>,
): ZoneTerrainElementData => ({
    elementId: null,
    colour: null,
    name: null,
    state: null,
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

const terrainOf = (
    palette: ZoneTerrainElementData[],
    overrides: Partial<ZoneTerrainData> = {},
): ZoneTerrainData => ({
    box,
    palette,
    element: [0],
    mass: [100],
    temperature: [293.15],
    previousTemperature: null,
    previousSeconds: null,
    ...overrides,
});

const overlay: OverlayPalette = {
    temperature: Array.from({ length: 9 }, (_, index): Rgba => [
        index * 20,
        10,
        200,
        255,
    ]),
    heatFlow: [
        { colour: [0, 0, 255, 255], value: -1 },
        { colour: [128, 128, 128, 255], value: 0 },
        { colour: [255, 0, 0, 255], value: 1 },
    ],
};

function paint(
    terrain: ZoneTerrainData,
    layer: ZoneMapLayer = 'materials',
    temperatureMode: TemperatureMode = 'absolute',
): StubCanvas {
    const canvas = new StubCanvas();
    canvases.push(canvas);

    paintZoneMap(canvas as unknown as HTMLCanvasElement, {
        box: terrain.box,
        terrain,
        tiles: null,
        cellPx: 10,
        dpr: 1,
        layer,
        inks,
        onSpriteLoad: () => undefined,
        temperatureMode,
        palette: overlay,
        assets: NO_RENDER_ASSETS,
        fluidsAnimated: false,
        showGrid: false,
    });

    return canvas;
}

// endregion

describe('paintZoneMap', () => {
    it('paints a fallback for a palette entry without render data', () => {
        const canvas = paint(terrainOf([entry({ elementId: 'ModRock' })]));
        const calls = canvas.context.calls;
        const neutral = calls.findIndex(
            (call) => call.style === rgbCss(inks.neutral),
        );

        expect(neutral).toBeGreaterThanOrEqual(0);
        expect(typeof calls[neutral + 1]?.style).toBe('object');
        expect(calls.filter((call) => call.op === 'drawImage')).toEqual([]);
    });

    it('paints the render colour flat when a solid texture is missing', () => {
        paint(
            terrainOf([
                entry({
                    elementId: 'Granite',
                    state: 'solid',
                    renderColour: '#11223380',
                    terrainTexture: '/assets/game/terrain/granite.webp',
                }),
            ]),
        );

        expect(allCalls().map((call) => call.style)).toContain('rgb(17 34 51)');
    });

    it('paints temperature cells with the absolute colour', () => {
        const canvas = paint(terrainOf([entry({})]), 'temperature');

        expect(canvas.context.calls.map((call) => call.style)).toContain(
            rgbaCss(overlay.temperature[3]!),
        );
    });

    it('paints static fluids under the solids so their edges stay on top', () => {
        const canvas = paint(
            terrainOf(
                [
                    entry({
                        elementId: 'Granite',
                        state: 'solid',
                        renderColour: '#112233ff',
                    }),
                    entry({
                        elementId: 'Oxygen',
                        state: 'gas',
                        renderColour: '#aabbcc80',
                    }),
                ],
                {
                    box: { x: 0, y: 0, width: 2, height: 1 },
                    element: [0, 1],
                    mass: [100, 2],
                    temperature: [293.15, 293.15],
                },
            ),
        );
        const calls = canvas.context.calls;
        const gasFill = calls.findIndex(
            (call) => call.style === 'rgb(170 187 204 / 0.5019607843137255)',
        );
        const solidDraw = calls.findIndex((call) => call.op === 'drawImage');

        expect(gasFill).toBeGreaterThanOrEqual(0);
        expect(solidDraw).toBeGreaterThan(gasFill);
    });

    it('splits the base and the solids into two canvases', () => {
        const base = new StubCanvas();
        const solids = new StubCanvas();
        canvases.push(base, solids);
        const terrain = terrainOf(
            [
                entry({
                    elementId: 'Granite',
                    state: 'solid',
                    renderColour: '#112233ff',
                }),
                entry({
                    elementId: 'Oxygen',
                    state: 'gas',
                    renderColour: '#aabbcc80',
                }),
            ],
            {
                box: { x: 0, y: 0, width: 2, height: 1 },
                element: [0, 1],
                mass: [100, 2],
                temperature: [293.15, 293.15],
            },
        );

        paintZoneMap(base as unknown as HTMLCanvasElement, {
            box: terrain.box,
            terrain,
            tiles: null,
            cellPx: 10,
            dpr: 1,
            layer: 'materials',
            inks,
            onSpriteLoad: () => undefined,
            temperatureMode: 'absolute',
            palette: overlay,
            assets: NO_RENDER_ASSETS,
            fluidsAnimated: false,
            showGrid: true,
            solidsCanvas: solids as unknown as HTMLCanvasElement,
        });

        const gas = 'rgb(170 187 204 / 0.5019607843137255)';
        const styles = (canvas: StubCanvas): unknown[] =>
            canvas.context.calls.map((call) => call.style);

        expect(styles(base)).toContain(gas);
        expect(styles(solids)).not.toContain(gas);
        expect(base.context.calls.some((call) => call.op === 'drawImage')).toBe(
            false,
        );
        expect(
            solids.context.calls.some((call) => call.op === 'drawImage'),
        ).toBe(true);
        expect(styles(solids)).toContain(rgbCss(inks.hatch));
        expect([solids.width, solids.height]).toEqual([
            base.width,
            base.height,
        ]);
    });

    it('paints nothing for heat flow before a second reading', () => {
        const canvas = paint(terrainOf([entry({})]), 'temperature', 'heatFlow');
        const solidFills = canvas.context.calls.filter(
            (call) => typeof call.style === 'string',
        );

        expect(solidFills).toEqual([]);
    });
});

describe('substance texture patterns', () => {
    it('has a pattern for every substance texture family', () => {
        const families: SubstanceTexture[] = [
            'None',
            'Magma',
            'MoltenMetal',
            'Polluted',
            'Oil',
            'Thick',
            'Sap',
            'CrystalFragments',
            'Ovolene',
            'Mucus',
            'Ink',
            'PollutedBrine',
        ];

        expect(Object.keys(SUBSTANCE_TEXTURE_PATTERNS).sort()).toEqual(
            [...families].sort(),
        );
    });
});
