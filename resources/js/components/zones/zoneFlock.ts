import type {
    ZoneCritterData,
    ZoneEggData,
    ZoneEntityData,
    ZoneFeedingData,
    ZoneFeedingLineData,
    ZonePopulationData,
} from '@/types/App/Data/data.d';

// region Groups

export type FlockStage = 'adult' | 'baby' | 'wild';

/** The critters of one prefab at one stage: one band of the flock. */
export type FlockGroup = {
    /** `${prefabId}:${stage}`, the rule the zone problems use too. */
    key: string;
    prefabId: string;
    name: string;
    sprite: string | null;
    stage: FlockStage;
    critters: ZoneCritterData[];
    /** Every bad status of the group with how many critters carry it, most carried first. */
    groupAlerts: { name: string; count: number }[];
};

/** A baby stays a baby whether tame or not; an adult is wild until tamed. */
export function flockStage(
    critter: Pick<ZoneCritterData, 'baby' | 'tame'>,
): FlockStage {
    if (critter.baby) {
        return 'baby';
    }

    return critter.tame ? 'adult' : 'wild';
}

/** One group per prefab and stage, the largest first (then by key, so live reloads keep the order). */
export function groupFlock(critters: ZoneCritterData[]): FlockGroup[] {
    const groups = new Map<string, FlockGroup>();

    for (const critter of critters) {
        const stage = flockStage(critter);
        const key = `${critter.prefabId}:${stage}`;
        let group = groups.get(key);

        if (group === undefined) {
            group = {
                key,
                prefabId: critter.prefabId,
                name: critter.name,
                sprite: critter.sprite,
                stage,
                critters: [],
                groupAlerts: [],
            };
            groups.set(key, group);
        }

        group.critters.push(critter);
    }

    for (const group of groups.values()) {
        group.groupAlerts = countBadStatuses(group.critters);
    }

    return [...groups.values()].sort(
        (a, b) =>
            b.critters.length - a.critters.length || a.key.localeCompare(b.key),
    );
}

function countBadStatuses(
    critters: ZoneCritterData[],
): { name: string; count: number }[] {
    const counts = new Map<string, number>();

    for (const critter of critters) {
        for (const status of critter.statuses) {
            if (status.bad) {
                counts.set(status.name, (counts.get(status.name) ?? 0) + 1);
            }
        }
    }

    return [...counts.entries()]
        .map(([name, count]) => ({ name, count }))
        .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name));
}

// endregion

// region Problems

/** The element id of a flock band: a critter problem links to it. */
export function bandId(groupKey: string): string {
    return `zone-band-${groupKey}`;
}

/** The placeholder glyph of an entity row: critter problems draw like critters. */
export function entitySpriteKind(
    kind: ZoneEntityData['kind'],
): 'plant' | 'building' | 'critter' {
    return kind === 'plant' || kind === 'building' ? kind : 'critter';
}

// endregion

// region Alerts

/** A bad status carried by every critter of the group: shown once on the band, never on each vignette. */
export function isGroupAlert(group: FlockGroup, statusName: string): boolean {
    return group.groupAlerts.some(
        (alert) =>
            alert.name === statusName && alert.count === group.critters.length,
    );
}

/** The bad statuses of one critter that the whole group does not share. */
export function individualAlerts(
    group: FlockGroup,
    critter: ZoneCritterData,
): string[] {
    return critter.statuses
        .filter((status) => status.bad && !isGroupAlert(group, status.name))
        .map((status) => status.name);
}

// endregion

// region Metrics

export type FlockMetric = 'fertility' | 'growth' | 'stomach' | 'age';

export const FLOCK_METRICS: FlockMetric[] = [
    'fertility',
    'growth',
    'stomach',
    'age',
];

/** The gauge a band shows first: growth for babies (they do not breed), fertility otherwise. */
export function defaultMetric(stage: FlockStage): FlockMetric {
    return stage === 'baby' ? 'growth' : 'fertility';
}

function lifeShare(critter: ZoneCritterData): number | null {
    return critter.ageCycles === null ||
        critter.lifespanCycles === null ||
        critter.lifespanCycles <= 0
        ? null
        : critter.ageCycles / critter.lifespanCycles;
}

/** A metric from 0 to 1, null when the game did not send it or it does not apply (growth of an adult). */
export function metricValue(
    critter: ZoneCritterData,
    metric: FlockMetric,
): number | null {
    const value = {
        fertility: critter.fertilityRatio,
        growth: critter.baby ? lifeShare(critter) : null,
        stomach: critter.caloriesRatio,
        age: lifeShare(critter),
    }[metric];

    return value === null ? null : Math.min(1, Math.max(0, value));
}

/** Highest first, unknown values last; ties keep a stable order across live reloads. */
export function sortByMetric(
    critters: ZoneCritterData[],
    metric: FlockMetric,
): ZoneCritterData[] {
    return [...critters].sort((a, b) => {
        const left = metricValue(a, metric);
        const right = metricValue(b, metric);

        if (left === null || right === null) {
            return (
                Number(left === null) - Number(right === null) ||
                a.instanceId.localeCompare(b.instanceId)
            );
        }

        return right - left || a.instanceId.localeCompare(b.instanceId);
    });
}

// endregion

// region Facts

export type HappinessState = 'happy' | 'neutral' | 'glum' | 'miserable';

const HAPPINESS_STATES: HappinessState[] = [
    'happy',
    'neutral',
    'glum',
    'miserable',
];

/** Critters per happiness state, in the game's order, states nobody is in left out. */
export function happinessCounts(
    critters: ZoneCritterData[],
): { state: HappinessState; count: number }[] {
    return HAPPINESS_STATES.map((state) => ({
        state,
        count: critters.filter((critter) => critter.happinessState === state)
            .length,
    })).filter((entry) => entry.count > 0);
}

/** Median of the known values, null when none is known. */
export function median(values: (number | null)[]): number | null {
    const known = values
        .filter((value) => value !== null)
        .sort((a, b) => a - b);

    if (known.length === 0) {
        return null;
    }

    const middle = Math.floor(known.length / 2);

    return known.length % 2 === 1
        ? known[middle]
        : (known[middle - 1] + known[middle]) / 2;
}

/** Youngest and oldest age in cycles, null when no age is known. */
export function ageRange(
    critters: ZoneCritterData[],
): { min: number; max: number } | null {
    const ages = critters
        .map((critter) => critter.ageCycles)
        .filter((age) => age !== null);

    return ages.length === 0
        ? null
        : { min: Math.min(...ages), max: Math.max(...ages) };
}

/** Fertility is frozen (×−1) in a cramped or confined region: the critter keeps its value but lays no egg. */
export function fertilityFrozen(
    critter: Pick<ZoneCritterData, 'regionId'>,
    population: ZonePopulationData[] | null,
): boolean {
    const region = (population ?? []).find(
        (candidate) => candidate.regionId === critter.regionId,
    );

    return region !== undefined && (region.cramped || region.confined);
}

/** Next-egg odds as shares of their total, most likely first. */
export function eggOdds(
    critter: Pick<ZoneCritterData, 'breeding'>,
): { egg: string; name: string; share: number }[] {
    const total = critter.breeding.reduce((sum, odd) => sum + odd.weight, 0);

    return total <= 0
        ? []
        : critter.breeding
              .map((odd) => ({
                  egg: odd.egg,
                  name: odd.name,
                  share: odd.weight / total,
              }))
              .sort((a, b) => b.share - a.share);
}

// endregion

// region Eggs

export type EggGroup = {
    key: string;
    prefabId: string;
    location: string;
    eggs: ZoneEggData[];
};

/** One group per egg prefab and location (floor, incubator, storage), the largest first. */
export function groupEggs(eggs: ZoneEggData[]): EggGroup[] {
    const groups = new Map<string, EggGroup>();

    for (const egg of eggs) {
        const location = egg.location ?? 'unknown';
        const key = `${egg.prefabId}:${location}`;
        const group = groups.get(key) ?? {
            key,
            prefabId: egg.prefabId,
            location,
            eggs: [],
        };
        group.eggs.push(egg);
        groups.set(key, group);
    }

    return [...groups.values()].sort(
        (a, b) => b.eggs.length - a.eggs.length || a.key.localeCompare(b.key),
    );
}

/** Cycles left before hatching: the part of the incubation still to go, times its total in cycles. */
export function cyclesToHatch(
    egg: { incubation: number },
    incubationCyclesTotal: number,
): number {
    return (Math.max(0, 100 - egg.incubation) / 100) * incubationCyclesTotal;
}

/** Where each egg of a group hatches on the timeline; eggs without a known incubation stay off it. */
export function eggTimeline(eggs: ZoneEggData[]): {
    placed: { egg: ZoneEggData; cycles: number }[];
    unplaced: ZoneEggData[];
    maxCycles: number;
} {
    const placed: { egg: ZoneEggData; cycles: number }[] = [];
    const unplaced: ZoneEggData[] = [];

    for (const egg of eggs) {
        if (egg.incubation === null || egg.incubationCycles === null) {
            unplaced.push(egg);
        } else {
            placed.push({
                egg,
                cycles: cyclesToHatch(
                    { incubation: egg.incubation },
                    egg.incubationCycles,
                ),
            });
        }
    }

    placed.sort((a, b) => a.cycles - b.cycles);

    return {
        placed,
        unplaced,
        maxCycles: Math.max(
            1,
            Math.ceil(placed[placed.length - 1]?.cycles ?? 0),
        ),
    };
}

// endregion

// region Feeding

/** The stock lasts long enough from this many cycles on. */
export const FEEDING_OK_CYCLES = 3;

/** Below this many cycles the stock is about to run out. */
export const FEEDING_BAD_CYCLES = 1;

export type FeedingTone = 'ok' | 'warn' | 'bad';

/** The balance chip of a band: how long the stock lasts, or the need nothing covers. */
export type FeedingChip =
    | { kind: 'stock'; cycles: number; tone: FeedingTone }
    | { kind: 'missing'; kgPerCycle: number };

/** Null with unknown stock, or for a band that only grazes or hunts (its food is not stocked). */
export function feedingChip(feeding: ZoneFeedingData): FeedingChip | null {
    if (feeding.deficitKgPerCycle !== null) {
        return { kind: 'missing', kgPerCycle: feeding.deficitKgPerCycle };
    }

    if (feeding.autonomyCycles === null) {
        return null;
    }

    const cycles = feeding.autonomyCycles;

    return {
        kind: 'stock',
        cycles,
        tone:
            cycles >= FEEDING_OK_CYCLES
                ? 'ok'
                : cycles < FEEDING_BAD_CYCLES
                  ? 'bad'
                  : 'warn',
    };
}

/** The shown foods by how they are eaten: items picked up (the main one first), plants grazed, prey hunted. */
export function feedingFoods(feeding: ZoneFeedingData): {
    items: ZoneFeedingLineData[];
    plants: ZoneFeedingLineData[];
    prey: ZoneFeedingLineData[];
} {
    const of = (kind: ZoneFeedingLineData['kind']): ZoneFeedingLineData[] =>
        feeding.foods.filter((food) => food.kind === kind);

    return { items: of('item'), plants: of('plant'), prey: of('prey') };
}

/** Decimals to show an amount per cycle with: none from 100, one from 1, two significant digits below (0.55, 0.011). */
export function amountDigits(value: number): number {
    const size = Math.abs(value);

    if (size === 0 || size >= 100) {
        return 0;
    }

    return size >= 1 ? 1 : Math.floor(-Math.log10(size)) + 2;
}

// endregion
