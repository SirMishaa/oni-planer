import type { ZoneBoxData, ZoneTerrainData } from '@/types/App/Data/data.d';
import { cellToLocal, layerIndex } from './zoneMapGeometry';

/** Gas mass, in kilograms, at which a cell shows its full tint. */
export const GAS_FULL_TINT_KG = 2;

/**
 * Share of a fluid's own colour alpha the map shows. The game exports most
 * render colours fully opaque (water is #55bed2ff) and makes them see-through
 * in its shaders; these scales stand in for that (tunable).
 */
export const LIQUID_ALPHA_SCALE = 0.7;
export const GAS_ALPHA_SCALE = 0.55;

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
        : clamp01(mass / GAS_FULL_TINT_KG);
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
