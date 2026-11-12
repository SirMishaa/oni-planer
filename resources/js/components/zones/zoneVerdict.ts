/**
 * Verdict of a zone. Unknown and partial never read as "all fine":
 * - `unknown`: neither the plants nor the buildings were read;
 * - `issues`: at least one problem among what was read;
 * - `partial`: no problem seen, but plants or buildings were not read;
 * - `ok`: both were read and nothing is wrong.
 */
export type ZoneVerdict = 'ok' | 'issues' | 'partial' | 'unknown';

export function zoneVerdict(
    problemCount: number,
    plantsKnown: boolean,
    buildingStatesKnown: boolean,
): ZoneVerdict {
    if (!plantsKnown && !buildingStatesKnown) {
        return 'unknown';
    }

    if (problemCount > 0) {
        return 'issues';
    }

    return plantsKnown && buildingStatesKnown ? 'ok' : 'partial';
}

/**
 * Problem mark of one map entity: `unknown` when the game sent no state for
 * it (a layout building before the fast state), never `clear`.
 */
export function entityProblemMark(
    problemCount: number,
    stateKnown: boolean,
): 'problem' | 'clear' | 'unknown' {
    if (problemCount > 0) {
        return 'problem';
    }

    return stateKnown ? 'clear' : 'unknown';
}
