export type ViabilityCritterReserveData = {
    id: string;
    name: string;
    icon: string | null;
    tame: boolean;
    count: number;
    kcal: number | null;
};
export type ViabilityData = {
    nowCycle: number;
    verdict: 'critical' | 'tight' | 'holds' | 'unknown';
    consumptionKcalPerCycle: number;
    stockKcal: number;
    scenarios: ViabilityScenariosData;
    sources: ViabilitySourceData[];
    harvests: ViabilityHarvestData[];
    lastComputedCycle: number;
    menu: ViabilityMenuItemData[];
    eaters: ViabilityEaterData[];
    nonEaters: ViabilityNonEaterData[];
    reserves: ViabilityReservesData;
    unavailable: string[];
    unknownCrops: string[];
    autoHarvestKnown: boolean;
};
export type ViabilityEaterData = {
    id: string;
    name: string;
    kcal: number | null;
    maxKcal: number | null;
    needKcal: number;
    allowed: string[];
    forbidden: string[];
};
export type ViabilityHarvestData = {
    sourceKey: string;
    kind: 'planted' | 'wild_auto' | 'wild_manual';
    cycle: number;
    kcal: number;
};
export type ViabilityMenuItemData = {
    foodId: string;
    name: string;
    icon: string | null;
    units: number;
    kcal: number;
    forbiddenFor: string[];
};
export type ViabilityNonEaterData = {
    name: string;
    model: string | null;
};
export type ViabilityPrintingPodData = {
    cyclesUntilNextPrint: number | null;
};
export type ViabilityReservesData = {
    wildManual: ViabilitySourceData[];
    critters: ViabilityCritterReserveData[];
    printingPod: ViabilityPrintingPodData;
};
export type ViabilityScenarioData = {
    autonomy: number | null;
    firstShort: string | null;
    stockByCycle: number[];
};
export type ViabilityScenariosData = {
    none: ViabilityScenarioData;
    automatic: ViabilityScenarioData;
    all: ViabilityScenarioData;
};
export type ViabilitySourceData = {
    key: string;
    plantId: string;
    plantName: string;
    plantIcon: string | null;
    cropId: string;
    kind: 'planted' | 'wild_auto' | 'wild_manual';
    count: number;
    stopped: number;
    kcalPerCycle: number;
};
