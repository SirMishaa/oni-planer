/** An sRGB colour with alpha, 0 to 255 per channel. */
export type Rgba = [number, number, number, number];

export type TemperatureMode =
    | 'absolute'
    | 'adaptive'
    | 'heatFlow'
    | 'stateChange';

export type OverlayPalette = {
    temperature: Rgba[];
    heatFlow: { colour: Rgba; value: number }[];
};

export type TemperatureRange = { min: number; max: number };

/** Gaps between the absolute temperature thresholds (K), from 0 K: `SimDebugView.NormalizedTemperature`. */
export const ABSOLUTE_INTERVALS_K = [273.15, 10, 10, 10, 7, 63, 1700, 10000];

/** Share of the zone's min..max span between adaptive thresholds. */
export const ADAPTIVE_INTERVALS = [0.4, 0.05, 0.05, 0.05, 0.05, 0.2, 0.2];

/** Heat flow (K per second) shown with the first or last heat flow colour. */
export const HEAT_FLOW_FULL_SCALE_K_PER_S = 0.05;

/** Game defaults for an element without a state transition. */
const DEFAULT_LOW_TEMP_K = 0;
const DEFAULT_HIGH_TEMP_K = 10000;
/** Width (K) of the state change warning around a transition. */
const STATE_CHANGE_WINDOW_K = 150;

// region Palette

export function parseRgba(hex: string | null | undefined): Rgba | null {
    const match = /^#?([0-9a-f]{8})$/i.exec(hex ?? '');

    if (!match) {
        return null;
    }

    const value = Number.parseInt(match[1] ?? '', 16);

    return [
        (value >>> 24) & 255,
        (value >>> 16) & 255,
        (value >>> 8) & 255,
        value & 255,
    ];
}

function parseThresholds(
    entries: unknown,
): { colour: Rgba; value: number }[] | null {
    if (!Array.isArray(entries)) {
        return null;
    }

    const parsed: { colour: Rgba; value: number }[] = [];

    for (const entry of entries as { value?: unknown; color?: unknown }[]) {
        const colour = parseRgba(
            typeof entry?.color === 'string' ? entry.color : null,
        );

        if (colour === null || typeof entry.value !== 'number') {
            return null;
        }

        parsed.push({ colour, value: entry.value });
    }

    return parsed;
}

/** Overlay palette from `/assets/game/render.json`; null when it is malformed or a colour is missing. */
export function parseRenderJson(json: unknown): OverlayPalette | null {
    const document = json as {
        temperatureThresholds?: unknown;
        heatFlowThresholds?: unknown;
    } | null;
    const temperature = parseThresholds(document?.temperatureThresholds);
    const heatFlow = parseThresholds(document?.heatFlowThresholds);

    if (temperature === null || heatFlow === null) {
        return null;
    }

    return {
        temperature: temperature.map((stop) => stop.colour),
        heatFlow: heatFlow.sort((a, b) => a.value - b.value),
    };
}

// endregion

// region Colours

function blend(from: Rgba, to: Rgba, amount: number): Rgba {
    const channel = (index: 0 | 1 | 2 | 3): number =>
        Math.round(from[index] + (to[index] - from[index]) * amount);

    return [channel(0), channel(1), channel(2), channel(3)];
}

/** Known temperatures (K): their min and max as they are; null when none is known. */
export function temperatureRange(
    values: readonly (number | null)[] | null,
): TemperatureRange | null {
    let min = Infinity;
    let max = -Infinity;

    for (const value of values ?? []) {
        if (value !== null && Number.isFinite(value)) {
            min = Math.min(min, value);
            max = Math.max(max, value);
        }
    }

    return min === Infinity ? null : { min, max };
}

/** Cumulative thresholds: `start`, then each interval added. */
function thresholds(start: number, intervals: readonly number[]): number[] {
    const result = [start];

    for (const interval of intervals) {
        result.push((result[result.length - 1] ?? start) + interval);
    }

    return result;
}

/** Port of `SimDebugView.NormalizedTemperature`: blend between the two surrounding threshold colours. */
export function thresholdColour(
    kelvin: number,
    start: number,
    intervals: readonly number[],
    colours: readonly Rgba[],
): { colour: Rgba; band: number } {
    const edges = thresholds(start, intervals);
    const first = colours[0] ?? [0, 0, 0, 0];
    const last = colours[Math.min(colours.length, edges.length) - 1] ?? first;

    if (!(kelvin > start)) {
        return { colour: first, band: 0 };
    }

    for (let band = 0; band < intervals.length; band++) {
        const lower = edges[band] ?? start;
        const upper = edges[band + 1] ?? lower;

        if (kelvin < upper) {
            const amount = (kelvin - lower) / (upper - lower);

            return {
                colour: blend(
                    colours[band] ?? first,
                    colours[band + 1] ?? last,
                    amount,
                ),
                band,
            };
        }
    }

    return { colour: last, band: intervals.length - 1 };
}

export function absoluteColour(
    kelvin: number,
    palette: OverlayPalette,
): { colour: Rgba; band: number } {
    return thresholdColour(
        kelvin,
        0,
        ABSOLUTE_INTERVALS_K,
        palette.temperature,
    );
}

export function adaptiveColour(
    kelvin: number,
    range: TemperatureRange,
    palette: OverlayPalette,
): { colour: Rgba; band: number } {
    const span = range.max - range.min;

    if (!(span > 0)) {
        return { colour: palette.temperature[0] ?? [0, 0, 0, 0], band: 0 };
    }

    return thresholdColour(
        kelvin,
        range.min,
        ADAPTIVE_INTERVALS.map((share) => share * span),
        palette.temperature,
    );
}

/** Heat flow colour: full cooling, none and full heating map to the first, middle and last stop. */
export function heatFlowColour(
    ratePerSecond: number,
    palette: OverlayPalette,
): Rgba {
    const stops = [...palette.heatFlow].sort((a, b) => a.value - b.value);
    const first = stops[0]?.colour ?? [0, 0, 0, 0];

    if (stops.length < 2) {
        return first;
    }

    const scaled = Math.min(
        1,
        Math.max(-1, ratePerSecond / HEAT_FLOW_FULL_SCALE_K_PER_S),
    );
    const position = ((scaled + 1) / 2) * (stops.length - 1);
    const index = Math.min(Math.floor(position), stops.length - 2);

    return blend(
        stops[index]?.colour ?? first,
        stops[index + 1]?.colour ?? first,
        position - index,
    );
}

function hsvToRgba(hue: number, saturation: number, value: number): Rgba {
    const channel = (offset: number): number => {
        const k = (offset + hue * 6) % 6;

        return Math.round(
            255 * value * (1 - saturation * Math.max(0, Math.min(k, 4 - k, 1))),
        );
    };

    return [channel(5), channel(3), channel(1), 255];
}

/** `SimDebugView.GetStateChangeProximityColour`: red at the transition, cyan far from it. */
export function stateChangeColour(
    kelvin: number,
    state: 'solid' | 'liquid' | 'gas',
    lowTemp: number | null,
    highTemp: number | null,
): Rgba {
    let low = lowTemp ?? DEFAULT_LOW_TEMP_K;
    let high = highTemp ?? DEFAULT_HIGH_TEMP_K;
    let min = 0;
    let max = 1;

    if (state === 'gas') {
        high = Math.min(low + STATE_CHANGE_WINDOW_K, high);
        max = 0.5;
    } else if (state === 'solid') {
        low = Math.max(high - STATE_CHANGE_WINDOW_K, low);
        min = 0.5;
    }

    const normalized =
        high > low
            ? Math.min(max, Math.max(min, (kelvin - low) / (high - low)))
            : min;

    return hsvToRgba((10 + (1 - normalized) * 171) / 360, 1, 1);
}

// endregion

// region Legend

function celsiusLabel(kelvin: number): string {
    return `${Math.round(kelvin - 273.15)} °C`;
}

/** A legend stop: a °C label, or a lang key (`labelKey`) for the modes without a temperature scale. */
export type LegendStop = {
    colour: Rgba;
    label: string;
    labelKey: string | null;
};

export function legendStops(
    mode: TemperatureMode,
    palette: OverlayPalette,
    range: TemperatureRange | null,
): LegendStop[] {
    if (mode === 'absolute') {
        return thresholds(0, ABSOLUTE_INTERVALS_K).flatMap((kelvin, index) => {
            const colour = palette.temperature[index];

            return colour
                ? [{ colour, label: celsiusLabel(kelvin), labelKey: null }]
                : [];
        });
    }

    if (mode === 'adaptive') {
        if (range === null) {
            return [];
        }

        const span = range.max - range.min;

        return thresholds(
            range.min,
            ADAPTIVE_INTERVALS.map((share) => share * span),
        ).flatMap((kelvin, index) => {
            const colour = palette.temperature[index];

            return colour
                ? [{ colour, label: celsiusLabel(kelvin), labelKey: null }]
                : [];
        });
    }

    if (mode === 'heatFlow') {
        const stops = [...palette.heatFlow].sort((a, b) => a.value - b.value);
        const first = stops[0];
        const last = stops[stops.length - 1];
        const middle = stops[Math.floor(stops.length / 2)];

        return first && middle && last
            ? [
                  heatFlowStop(first.colour, 'cooling'),
                  heatFlowStop(middle.colour, 'stable'),
                  heatFlowStop(last.colour, 'heating'),
              ]
            : [];
    }

    // Cyan sits at the lower transition (freezing, condensing), red at the
    // upper one (melting, boiling).
    return [
        {
            colour: stateChangeColour(0, 'liquid', 0, 1),
            label: '',
            labelKey: 'zones.map.state_change.cold',
        },
        {
            colour: stateChangeColour(1, 'liquid', 0, 1),
            label: '',
            labelKey: 'zones.map.state_change.hot',
        },
    ];
}

function heatFlowStop(
    colour: Rgba,
    name: 'cooling' | 'stable' | 'heating',
): LegendStop {
    return { colour, label: '', labelKey: `zones.map.heat_flow.${name}` };
}

// endregion
