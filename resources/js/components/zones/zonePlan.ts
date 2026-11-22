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
};

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

/** Targets still standing in the game that the plan removes (an applied replace already took its target out). */
export function removedFootprints(plan: ZonePlanData): RemovedFootprint[] {
    return plan.changes.flatMap((change) =>
        (change.kind === 'remove' || change.kind === 'replace') &&
        change.state !== 'applied' &&
        change.target !== null
            ? [
                  {
                      x: change.target.x,
                      y: change.target.y,
                      width: change.target.width,
                      height: change.target.height,
                      changeId: change.id,
                  },
              ]
            : [],
    );
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
