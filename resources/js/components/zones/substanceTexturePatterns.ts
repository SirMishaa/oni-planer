import type { SubstanceTexture } from '@/types/App/Enums/data.d';

/**
 * How a liquid or gas texture family looks on the map: noise size (cells
 * per noise period) and strength (0 to 1), suspended flecks per cell, and
 * whether the noise stretches into streaks.
 */
export type FluidPattern = {
    noiseScale: number;
    noiseStrength: number;
    flecks: number;
    streaks: boolean;
};

export const DEFAULT_PATTERN: FluidPattern = {
    noiseScale: 4,
    noiseStrength: 0.08,
    flecks: 0,
    streaks: false,
};

/**
 * The only hand-written visual mapping: the game's closed `Substance.SubstanceTexture`
 * enum (reused by mods) to a pattern. Exhaustive, so a new family fails typecheck.
 */
export const SUBSTANCE_TEXTURE_PATTERNS: Record<
    SubstanceTexture,
    FluidPattern
> = {
    None: DEFAULT_PATTERN,
    Magma: { noiseScale: 2, noiseStrength: 0.45, flecks: 0.2, streaks: true },
    MoltenMetal: {
        noiseScale: 3,
        noiseStrength: 0.25,
        flecks: 0.1,
        streaks: true,
    },
    Polluted: {
        noiseScale: 6,
        noiseStrength: 0.3,
        flecks: 0.5,
        streaks: false,
    },
    Oil: { noiseScale: 3, noiseStrength: 0.35, flecks: 0, streaks: true },
    Thick: {
        noiseScale: 5,
        noiseStrength: 0.25,
        flecks: 0.2,
        streaks: false,
    },
    Sap: { noiseScale: 4, noiseStrength: 0.3, flecks: 0.1, streaks: true },
    CrystalFragments: {
        noiseScale: 8,
        noiseStrength: 0.15,
        flecks: 0.8,
        streaks: false,
    },
    Ovolene: {
        noiseScale: 4,
        noiseStrength: 0.2,
        flecks: 0.3,
        streaks: false,
    },
    Mucus: {
        noiseScale: 3,
        noiseStrength: 0.3,
        flecks: 0.15,
        streaks: true,
    },
    Ink: { noiseScale: 5, noiseStrength: 0.35, flecks: 0, streaks: true },
    PollutedBrine: {
        noiseScale: 6,
        noiseStrength: 0.25,
        flecks: 0.6,
        streaks: false,
    },
};

export function patternFor(family: SubstanceTexture | null): FluidPattern {
    return family === null
        ? DEFAULT_PATTERN
        : (SUBSTANCE_TEXTURE_PATTERNS[family] ?? DEFAULT_PATTERN);
}
