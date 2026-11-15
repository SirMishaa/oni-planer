export type AcceptedFoodData = {
    resource: string;
    kgPerCycle: number;
    produces: string | null;
    producedKgPerCycle: number | null;
    kind: 'item' | 'plant' | 'prey';
};
export type CritterOverOccupancyData = {
    prefabId: string;
    count: number;
    personalSpace: number;
    toRemove: number;
    canFix: boolean;
    derived: boolean;
};
export type FeedingBalanceData = {
    groupKey: string;
    accepts: AcceptedFoodData[];
    availableKg: number;
    mainResource: string | null;
    deficitKgPerCycle: number | null;
    autonomyCycles: number | null;
    derived: boolean;
};
export type InventoryItemData = {
    resource: string;
    kg: number;
    placeKind: string;
    placeId: string;
    zoneId: number | null;
};
export type OccupancyInputData = {
    cellCount: number;
    isPond: boolean;
    critters: OccupantData[];
    eggs: OccupantData[];
};
export type OccupancyVerdictData = {
    cellCount: number;
    occupied: number;
    eggOccupied: number;
    free: number;
    overcrowded: boolean;
    cramped: boolean;
    confined: boolean;
    happinessModifier: number;
    perType: CritterOverOccupancyData[];
};
export type OccupantData = {
    prefabId: string;
    personalSpace: number;
    aquatic: boolean;
    confinementImmune: boolean;
};
export type ResourceFlowData = {
    resource: string;
    perCycle: number;
    unit: 'kg' | 'count';
    direction: 'consumed' | 'produced';
    sourceKind: 'critter_group' | 'plant' | 'building';
    sourceId: string;
    zoneId: number | null;
    accepts: AcceptedFoodData[];
};
