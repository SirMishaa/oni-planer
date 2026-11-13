import type { ZoneBoxData, ZoneTerrainData } from '@/types/App/Data/data.d';
import { cellToLocal, layerIndex } from './zoneMapGeometry';

/** Gas mass, in kilograms, at which a cell shows its full tint; below it the tint follows the square root of the mass, so a thin gas still shows. */
export const GAS_FULL_TINT_KG = 2;

/**
 * Share of a fluid's own colour alpha the map shows. The game exports most
 * render colours fully opaque (water is #55bed2ff) and makes them see-through
 * in its shaders; these scales stand in for that (tunable).
 */
export const LIQUID_ALPHA_SCALE = 0.7;
export const GAS_ALPHA_SCALE = 0.45;

/** Darkest a gas may look (0 to 255 luma): below it, the gas turns smoke grey so it reads on the dark map. */
export const GAS_MIN_LUMA = 96;

export type FluidCell = {
    column: number;
    row: number;
    palette: number;
    kind: 'liquid' | 'gas';
    amount: number;
    surface: boolean;
};

function clamp01(value: number): number {
    return Math.min(1, Math.max(0, value));
}

// region Amounts

export function liquidFill(
    mass: number | null,
    maxMass: number | null,
): number {
    if (mass === null || !Number.isFinite(mass)) {
        return 0;
    }

    if (maxMass === null || !Number.isFinite(maxMass) || maxMass <= 0) {
        return 1;
    }

    return clamp01(mass / maxMass);
}

export function gasStrength(mass: number | null): number {
    return mass === null || !Number.isFinite(mass)
        ? 0
        : Math.sqrt(clamp01(mass / GAS_FULL_TINT_KG));
}

/**
 * Opacity (0 to 1) of a fluid at full amount: opaque liquids are solid,
 * others keep their colour alpha scaled to read like the game.
 */
export function fluidAlpha(
    colourAlpha: number,
    kind: 'liquid' | 'gas',
    opaqueLiquid: boolean,
): number {
    if (kind === 'liquid' && opaqueLiquid) {
        return 1;
    }

    return (
        clamp01(colourAlpha / 255) *
        (kind === 'liquid' ? LIQUID_ALPHA_SCALE : GAS_ALPHA_SCALE)
    );
}

/**
 * The colour a gas is drawn with: its render colour, except that a near-black
 * gas (carbon dioxide is #1e1e1e) is lifted to a smoke grey of the same hue,
 * as the game shows it over its lit background.
 */
export function gasColour(
    rgb: readonly [number, number, number],
): [number, number, number] {
    const [red, green, blue] = rgb;
    const luma = 0.299 * red + 0.587 * green + 0.114 * blue;

    if (luma >= GAS_MIN_LUMA) {
        return [red, green, blue];
    }

    const lift = GAS_MIN_LUMA - luma;

    return [
        Math.round(Math.min(255, red + lift)),
        Math.round(Math.min(255, green + lift)),
        Math.round(Math.min(255, blue + lift)),
    ];
}

// endregion

// region Cells

/** Liquid and gas cells of the terrain that fall inside the map box. */
export function fluidCells(
    terrain: ZoneTerrainData,
    box: ZoneBoxData,
): FluidCell[] {
    const elements = terrain.element;

    if (!elements) {
        return [];
    }

    const cells: FluidCell[] = [];
    const source = terrain.box;
    /** Fluid cell by source index, for the solids that borrow a neighbour's fluid. */
    const fluidAt = new Map<number, FluidCell>();
    const solids: { index: number; x: number; y: number }[] = [];

    for (let row = 0; row < source.height; row++) {
        for (let column = 0; column < source.width; column++) {
            const index = row * source.width + column;
            const palette = elements[index] ?? -1;
            const state = terrain.palette[palette]?.state;

            const x = source.x + column;
            const y = source.y + row;

            if (state !== 'liquid' && state !== 'gas') {
                if (state === 'solid') {
                    solids.push({ index, x, y });
                }

                continue;
            }

            const local = cellToLocal(x, y, box);

            if (
                local.column < 0 ||
                local.row < 0 ||
                local.column >= box.width ||
                local.row >= box.height
            ) {
                continue;
            }

            const mass = terrain.mass?.[index] ?? null;

            if (state === 'gas') {
                const cell: FluidCell = {
                    ...local,
                    palette,
                    kind: 'gas',
                    amount: gasStrength(mass),
                    surface: false,
                };
                cells.push(cell);
                fluidAt.set(index, cell);

                continue;
            }

            const aboveIndex = layerIndex(x, y + 1, source);
            const sameAbove =
                aboveIndex !== null && elements[aboveIndex] === palette;

            const cell: FluidCell = {
                ...local,
                palette,
                kind: 'liquid',
                amount: sameAbove
                    ? 1
                    : liquidFill(
                          mass,
                          terrain.palette[palette]?.maxMass ?? null,
                      ),
                surface: !sameAbove,
            };
            cells.push(cell);
            fluidAt.set(index, cell);
        }
    }

    return [...cells, ...fluidBehindSolids(solids, fluidAt, source, box)];
}

/** Neighbours tried in order: the sides first, so a liquid keeps its level, then above, below and the corners. */
const NEIGHBOURS: [number, number][] = [
    [-1, 0],
    [1, 0],
    [0, 1],
    [0, -1],
    [-1, 1],
    [1, 1],
    [-1, -1],
    [1, -1],
];

/**
 * The fluid drawn behind each solid cell that touches one: a copy of its first
 * fluid neighbour, so the rounded edges of the solid show that fluid in their
 * corners and not the map background, as in the game.
 */
function fluidBehindSolids(
    solids: { index: number; x: number; y: number }[],
    fluidAt: Map<number, FluidCell>,
    source: ZoneBoxData,
    box: ZoneBoxData,
): FluidCell[] {
    const cells: FluidCell[] = [];

    for (const solid of solids) {
        const local = cellToLocal(solid.x, solid.y, box);

        if (layerIndex(solid.x, solid.y, box) === null) {
            continue;
        }

        for (const [dx, dy] of NEIGHBOURS) {
            const neighbour = layerIndex(solid.x + dx, solid.y + dy, source);
            const fluid =
                neighbour === null ? undefined : fluidAt.get(neighbour);

            if (fluid) {
                cells.push({ ...fluid, ...local });
                break;
            }
        }
    }

    return cells;
}

// endregion
