import type { ZoneBoxData, ZoneTerrainData } from '@/types/App/Data/data.d';
import { cellToLocal, layerIndex } from './zoneMapGeometry';

/** Gas mass, in kilograms, at which a cell shows its full tint. */
export const GAS_FULL_TINT_KG = 2;

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
    if (mass === null) {
        return 0;
    }

    if (maxMass === null || maxMass <= 0) {
        return 1;
    }

    return clamp01(mass / maxMass);
}

export function gasStrength(mass: number | null): number {
    return mass === null ? 0 : clamp01(mass / GAS_FULL_TINT_KG);
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

    for (let row = 0; row < source.height; row++) {
        for (let column = 0; column < source.width; column++) {
            const index = row * source.width + column;
            const palette = elements[index] ?? -1;
            const state = terrain.palette[palette]?.state;

            if (state !== 'liquid' && state !== 'gas') {
                continue;
            }

            const x = source.x + column;
            const y = source.y + row;
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
                cells.push({
                    ...local,
                    palette,
                    kind: 'gas',
                    amount: gasStrength(mass),
                    surface: false,
                });

                continue;
            }

            const aboveIndex = layerIndex(x, y + 1, source);
            const sameAbove =
                aboveIndex !== null && elements[aboveIndex] === palette;

            cells.push({
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
            });
        }
    }

    return cells;
}

// endregion
