import { describe, expect, it } from 'vitest';
import {
    absoluteColour,
    adaptiveColour,
    heatFlowColour,
    legendStops,
    parseRenderJson,
    stateChangeColour,
    temperatureRange,
    type OverlayPalette,
    type Rgba,
} from './zoneTemperature';

const blue: Rgba = [0, 0, 255, 255];
const grey: Rgba = [128, 128, 128, 255];
const red: Rgba = [255, 0, 0, 255];

const palette: OverlayPalette = {
    temperature: Array.from({ length: 9 }, (_, index): Rgba => [
        index * 10,
        0,
        0,
        255,
    ]),
    heatFlow: [
        { colour: red, value: 1 },
        { colour: blue, value: -1 },
        { colour: grey, value: 0 },
    ],
};

describe('absolute scale', () => {
    it('gives exactly the 20 °C stop colour at 20 °C', () => {
        expect(absoluteColour(293.15, palette)).toEqual({
            colour: palette.temperature[3],
            band: 3,
        });
    });

    it('blends halfway between stops', () => {
        // 25 °C is halfway between the 20 °C and 30 °C stops.
        expect(absoluteColour(298.15, palette)).toEqual({
            colour: [35, 0, 0, 255],
            band: 3,
        });
    });

    it('clamps below absolute zero and above 11800 °C', () => {
        expect(absoluteColour(-5, palette).colour).toEqual(
            palette.temperature[0],
        );
        expect(absoluteColour(50000, palette).colour).toEqual(
            palette.temperature[8],
        );
    });
});

describe('adaptive scale', () => {
    it('handles a uniform zone without NaN', () => {
        const result = adaptiveColour(300, { min: 300, max: 300 }, palette);

        expect(result.colour).toEqual(palette.temperature[0]);
        expect(result.colour.every(Number.isFinite)).toBe(true);
    });

    it('puts 40 % of the span in the first band', () => {
        expect(adaptiveColour(340, { min: 300, max: 400 }, palette)).toEqual({
            colour: palette.temperature[1],
            band: 1,
        });
    });
});

describe('heat flow', () => {
    it('maps cooling, stable and heating', () => {
        expect(heatFlowColour(-0.05, palette)).toEqual(blue);
        expect(heatFlowColour(0, palette)).toEqual(grey);
        expect(heatFlowColour(0.1, palette)).toEqual(red);
    });
});

describe('state change', () => {
    it('is red for water near boiling', () => {
        const [r, g, b, a] = stateChangeColour(372, 'liquid', 273.15, 372.15);

        expect(r).toBe(255);
        expect(Math.abs(g - 42)).toBeLessThanOrEqual(2);
        expect(b).toBe(0);
        expect(a).toBe(255);
    });

    it('clamps a gas to the cold half', () => {
        expect(stateChangeColour(900, 'gas', 100, 1000)).toEqual(
            stateChangeColour(175, 'liquid', 100, 250),
        );
    });

    it('uses the game defaults for missing transitions', () => {
        expect(stateChangeColour(9000, 'solid', null, null)).toEqual(
            stateChangeColour(9000, 'solid', 0, 10000),
        );
    });
});

describe('temperatureRange', () => {
    it('keeps the plain min..max and ignores unknown cells', () => {
        expect(temperatureRange([308.15, null, 308.15])).toEqual({
            min: 308.15,
            max: 308.15,
        });
        expect(temperatureRange([null])).toBeNull();
        expect(temperatureRange(null)).toBeNull();
    });
});

describe('parseRenderJson', () => {
    const entry = (value: number, color: string | null) => ({
        colorName: 'x',
        value,
        color,
    });

    it('parses #rrggbbaa thresholds and sorts the heat flow', () => {
        const parsed = parseRenderJson({
            temperatureThresholds: [entry(0, '#ff000080')],
            heatFlowThresholds: [entry(1, '#00ff00ff'), entry(-1, '#0000ffff')],
            maskAtlas: {},
        });

        expect(parsed?.temperature).toEqual([[255, 0, 0, 128]]);
        expect(parsed?.heatFlow.map((stop) => stop.value)).toEqual([-1, 1]);
    });

    it('rejects a null colour or a malformed document', () => {
        expect(
            parseRenderJson({
                temperatureThresholds: [entry(0, null)],
                heatFlowThresholds: [],
            }),
        ).toBeNull();
        expect(parseRenderJson(null)).toBeNull();
        expect(parseRenderJson({})).toBeNull();
    });
});

describe('legendStops', () => {
    it('lists the nine absolute stops in °C', () => {
        const stops = legendStops('absolute', palette, null);

        expect(stops).toHaveLength(9);
        expect(stops[0]?.label).toBe('-273 °C');
        expect(stops[8]?.label).toBe('11800 °C');
    });

    it('spans the zone for the adaptive scale', () => {
        const stops = legendStops('adaptive', palette, {
            min: 300,
            max: 400,
        });

        expect(stops[0]?.label).toBe('27 °C');
        expect(stops).toHaveLength(8);
    });

    it('names the heat flow and state change stops with lang keys', () => {
        expect(
            legendStops('heatFlow', palette, null).map((stop) => stop.labelKey),
        ).toEqual([
            'zones.map.heat_flow.cooling',
            'zones.map.heat_flow.stable',
            'zones.map.heat_flow.heating',
        ]);
        expect(
            legendStops('stateChange', palette, null).map(
                (stop) => stop.labelKey,
            ),
        ).toEqual([
            'zones.map.state_change.cold',
            'zones.map.state_change.hot',
        ]);
        expect(legendStops('absolute', palette, null)[0]?.labelKey).toBeNull();
    });
});
