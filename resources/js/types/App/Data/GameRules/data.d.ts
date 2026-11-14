export type CritterOverOccupancyData = {
    prefabId: string;
    count: number;
    personalSpace: number;
    toRemove: number;
    canFix: boolean;
    derived: boolean;
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
