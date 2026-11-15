/**
 * Verdict of a zone. Unknown and partial never read as "all fine":
 * - `issues`: at least one problem among what was read (critter alerts count even when no plant or building was);
 * - `unknown`: nothing was read and nothing is wrong;
 * - `partial`: no problem seen, but plants or buildings were not read;
 * - `ok`: both were read and nothing is wrong.
 */
export type ZoneVerdict = 'ok' | 'issues' | 'partial' | 'unknown';

export function zoneVerdict(
    problemCount: number,
    plantsKnown: boolean,
    buildingStatesKnown: boolean,
): ZoneVerdict {
    if (problemCount > 0) {
        return 'issues';
    }

    if (!plantsKnown && !buildingStatesKnown) {
        return 'unknown';
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
