import type {
    ZoneBoxData,
    ZoneCritterData,
    ZoneEggData,
    ZoneEntityData,
} from '@/types/App/Data/data.d';
import { layerIndex } from './zoneMapGeometry';

// region Map entity

/** Something drawn over the map and selectable: a building, a plant, a critter or an egg. */
export type MapEntity = {
    key: string;
    instanceId: string | null;
    kind: 'plant' | 'building' | 'critter' | 'egg';
    prefabId: string | null;
    name: string;
    sprite: string | null;
    /** The cell it stands on (its bottom-left cell for a footprint), in world cells. */
    x: number;
    y: number;
    width: number;
    height: number;
    /** Built on a ceiling: the render hangs from the top of the footprint. */
    hanging: boolean;
    /** Drawn mirrored (orientation FlipH, or a critter facing left). */
    flipped: boolean;
    /** Where the render is drawn, in cells, when it is not the footprint (a stair's art overhangs it, a critter stands between cells). */
    drawBox: { x: number; y: number; width: number; height: number } | null;
    growth: number | null;
    secondsUntilHarvest: number | null;
    state: ZoneEntityData['state'] | null;
    problems: string[];
};

// endregion

// region Critters and eggs

/** Side of an egg on the map, in cells: smaller than a critter, as in the game. */
export const EGG_SIZE_CELLS = 0.6;

/** Eggs in these places are inside a building (a storage bin, an incubator): not drawn on the floor. */
const HIDDEN_EGG_LOCATIONS = new Set(['storage', 'incubator']);

/**
 * Critters and eggs of the box as map entities. A critter's `x, y` is the
 * game position (centre of its body, its feet), so its one-cell render is
 * centred on `x` and stands on `y`. It is drawn with its in-world sprite (frame 0 of
 * the anim the game draws it with), else its portrait. The game authors that art
 * facing right and mirrors it for a critter facing left (Facing → FlipX), so a
 * critter facing left is flipped. An egg lies on the floor of its cell.
 */
export function critterEntities(
    critters: readonly ZoneCritterData[],
    eggs: readonly ZoneEggData[],
    box: ZoneBoxData,
    gridWidth: number,
): MapEntity[] {
    const base = {
        width: 1,
        height: 1,
        hanging: false,
        growth: null,
        secondsUntilHarvest: null,
        state: null,
        problems: [],
    };

    const critterRows = critters.map((critter): MapEntity => ({
        ...base,
        key: `c${critter.instanceId}`,
        instanceId: critter.instanceId,
        kind: 'critter',
        prefabId: critter.prefabId,
        name: critter.name,
        sprite: critter.worldSprite ?? critter.sprite,
        x: Math.floor(critter.x),
        y: Math.floor(critter.y),
        flipped: critter.facing === 'left',
        drawBox: { x: critter.x - 0.5, y: critter.y, width: 1, height: 1 },
    }));

    const eggRows = eggs
        .filter((egg) => !HIDDEN_EGG_LOCATIONS.has(egg.location ?? ''))
        .map((egg): MapEntity => {
            const x = egg.cell % gridWidth;
            const y = Math.floor(egg.cell / gridWidth);

            return {
                ...base,
                key: `e${egg.instanceId}`,
                instanceId: egg.instanceId,
                kind: 'egg',
                prefabId: egg.spawns,
                name: egg.name,
                sprite: egg.sprite,
                x,
                y,
                flipped: false,
                drawBox: {
                    x: x + (1 - EGG_SIZE_CELLS) / 2,
                    y,
                    width: EGG_SIZE_CELLS,
                    height: EGG_SIZE_CELLS,
                },
            };
        });

    return [...eggRows, ...critterRows].filter(
        (entity) => layerIndex(entity.x, entity.y, box) !== null,
    );
}

// endregion
