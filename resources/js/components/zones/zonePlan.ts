import type {
    ZoneBoxData,
    ZonePlanChangeData,
    ZonePlanData,
} from '@/types/App/Data/data.d';
import { localToCell } from './zoneMapGeometry';

/** A footprint the plan takes out of the game, drawn as a ghost in suggestion mode. */
export type RemovedFootprint = {
    x: number;
    y: number;
    width: number;
    height: number;
    changeId: number;
    /** The same change builds over part of it: drawn without hatching, so the new building shows. */
    overlapped: boolean;
};

type Rect = { x: number; y: number; width: number; height: number };

/** The footprint a replace builds; null for a remove. */
function placedRect(change: ZonePlanChangeData): Rect | null {
    return change.kind === 'replace' &&
        change.x !== null &&
        change.y !== null &&
        change.width !== null &&
        change.height !== null
        ? {
              x: change.x,
              y: change.y,
              width: change.width,
              height: change.height,
          }
        : null;
}

function covers(outer: Rect, inner: Rect): boolean {
    return (
        outer.x <= inner.x &&
        outer.y <= inner.y &&
        outer.x + outer.width >= inner.x + inner.width &&
        outer.y + outer.height >= inner.y + inner.height
    );
}

function overlaps(a: Rect, b: Rect): boolean {
    return (
        a.x < b.x + b.width &&
        b.x < a.x + a.width &&
        a.y < b.y + b.height &&
        b.y < a.y + a.height
    );
}

const PLAN_INSTANCE = /^plan:(\d+)$/;

/** The change id of a planned instance (`plan:12` → 12); null for a real one. */
export function planChangeId(instanceId: string | null): number | null {
    const match = PLAN_INSTANCE.exec(instanceId ?? '');

    return match ? Number(match[1]) : null;
}

/** World cell under a pointer given in CSS pixels from the stage's top-left corner (game y grows upward). */
export function snapToCell(
    pointerX: number,
    pointerY: number,
    cellPx: number,
    box: ZoneBoxData,
): { x: number; y: number } {
    return localToCell(
        Math.floor(pointerX / cellPx),
        Math.floor(pointerY / cellPx),
        box,
    );
}

/**
 * Targets still standing in the game that the plan removes (an applied replace already took its
 * target out). A replace that builds over its whole target (MeshTile → Tile) has no ghost: the
 * new building is what shows there.
 */
export function removedFootprints(plan: ZonePlanData): RemovedFootprint[] {
    return plan.changes.flatMap((change): RemovedFootprint[] => {
        if (
            (change.kind !== 'remove' && change.kind !== 'replace') ||
            change.state === 'applied' ||
            change.target === null
        ) {
            return [];
        }

        const target = change.target;
        const placed = placedRect(change);

        if (placed && covers(placed, target)) {
            return [];
        }

        return [
            {
                x: target.x,
                y: target.y,
                width: target.width,
                height: target.height,
                changeId: change.id,
                overlapped: placed !== null && overlaps(placed, target),
            },
        ];
    });
}

/** How a change stands out on the map: a conflict first, then applied, then never validated (another layer than buildings). */
export type PlanChangeStatus =
    | 'pending'
    | 'applied'
    | 'conflicted'
    | 'unchecked';

export function planChangeStatus(change: ZonePlanChangeData): PlanChangeStatus {
    if (change.state === 'conflicted' || change.state === 'applied') {
        return change.state;
    }

    return change.checked ? 'pending' : 'unchecked';
}
