import { SubstanceTexture } from '../Enums/data.d';
import { CritterOverOccupancyData } from './GameRules/data.d';
export type BuildingCapacityData = {
    limit: number | null;
    max: number | null;
    stored: number | null;
    unit: 'kg' | 'critters';
};
export type BuildingData = {
    building_id: string;
    name: string;
    category: string;
    sub_group: string | null;
    width: number;
    height: number;
    power_consumption: number | null;
    power_generation: number | null;
    dlc_id: string | null;
    mod: ModData | null;
};
export type BuildingMaterialData = {
    element_id: string | null;
    material_category: string | null;
    amount: number;
};
export type BuildingSettingsData = {
    filter: string[] | null;
    capacity: BuildingCapacityData | null;
};
export type BuildingShowData = {
    building_id: string;
    name: string;
    category: string;
    sub_group: string | null;
    width: number;
    height: number;
    power_consumption: number | null;
    power_generation: number | null;
    heat_generation: number | null;
    construction_time: number | null;
    description: string | null;
    effect: string | null;
    dlc_id: string | null;
    materials: BuildingMaterialData[];
    mod: ModData | null;
};
export type CodexArticleData = {
    codex_id: string;
    category: string;
    parent_codex_id: string | null;
    title: string;
    icon_asset: string | null;
    content: any[];
    image: string | null;
    links: CodexLinkData[];
};
export type CodexLinkData = {
    type: string;
    key: string;
    label: string;
    url: string | null;
};
export type CodexListItemData = {
    codex_id: string;
    category: string;
    parent_codex_id: string | null;
    title: string;
    image: string | null;
};
export type ColonyStatusData = {
    state: 'never' | 'no_colony' | 'ok' | 'newer_schema';
    colonyName: string | null;
    cycle: number | null;
    lastSeenAt: string | null;
    schema: number | null;
    echo: { channel: 'uplink'; event: '.client-colony.state' };
};
export type CritterDietData = {
    consumed: ElementRefData;
    produced: ElementRefData | null;
    amount_per_cycle: number;
    conversion_ratio: number | null;
};
export type CritterFamilyData = {
    slug: string;
    name: string;
    plural_name: string | null;
    description: string | null;
    image: string | null;
    variants: CritterVariantData[];
    mod: ModData | null;
};
export type CritterFamilyListItemData = {
    slug: string;
    name: string;
    plural_name: string | null;
    image: string | null;
    image_idle: string | null;
    variant_count: number;
    eats_summary: string | null;
    makes_summary: string | null;
    dlc_id: string | null;
    variant_previews: CritterVariantPreviewData[];
    min_temp: number;
    max_temp: number;
    mod: ModData | null;
};
export type CritterMorphData = {
    morph_id: string;
    is_base: boolean;
    name: string;
    min_temp: number;
    max_temp: number;
    calories_per_cycle: number;
    incubation_time: number;
    lifespan: number;
    overcrowding_threshold: number;
    diets: CritterDietData[];
    outputs: CritterOutputData[];
};
export type CritterOutputData = {
    kind: string;
    element_id: string;
    label: string;
    icon: string | null;
    url: string | null;
    amount_per_cycle: number;
};
export type CritterVariantData = {
    critter_id: string;
    name: string;
    image: string | null;
    image_idle: string | null;
    adult: CritterMorphData;
    baby: CritterMorphData | null;
};
export type CritterVariantPreviewData = {
    critter_id: string;
    name: string;
    image: string;
};
export type CrowdingMismatchData = {
    zoneName: string;
    regionId: string;
    prefabId: string;
    field: 'occupied' | 'overcrowded' | 'confined' | 'modifier';
    game: number | boolean;
    planner: number | boolean;
    at: string;
};
export type ElementData = {
    element_id: string;
    name: string;
    state: string;
    material_category: string;
    mod: ModData | null;
};
export type ElementRefData = {
    element_id: string;
    name: string;
    icon: string | null;
    url: string | null;
};
export type ElementShowData = {
    element_id: string;
    name: string;
    description: string | null;
    state: string;
    material_category: string;
    molar_mass: number;
    toxicity: number;
    tags: string[];
    low_temp_transition_target: string | null;
    high_temp_transition_target: string | null;
    thermal: ThermalPropertyData | null;
    mod: ModData | null;
};
export type ModData = {
    workshop_id: string;
    title: string;
};
export type RanchCatalogData = {
    families: CritterFamilyData[];
    elements: Record<string, ElementRefData>;
};
export type ThermalPropertyData = {
    specific_heat_capacity: number;
    thermal_conductivity: number;
    low_temp: number | null;
    high_temp: number | null;
    default_temperature: number;
};
export type UplinkMessageData = {
    id: number;
    event: string | null;
    colonyGuid: string | null;
    colonyName: string | null;
    cycle: number | null;
    size: number;
    outcome: 'stored' | 'refused';
    reason: string | null;
    sectionErrors: string[];
    receivedAt: string;
};
export type ZoneBoxData = {
    x: number;
    y: number;
    width: number;
    height: number;
};
export type ZoneBuildingData = {
    instanceId: string | null;
    x: number;
    y: number;
    width: number;
    height: number;
    orientation: string | null;
    prefabId: string;
    name: string;
    sprite: string | null;
    hangsFromCeiling: boolean;
    spriteHeight: number;
    flipped: boolean;
    spriteRect: ZoneSpriteRectData | null;
};
export type ZoneCaptureData = {
    id: number;
    url: string;
    takenAt: string;
    cycle: number;
    trigger: string;
    matchesLayout: boolean;
    width: number;
    height: number;
};
export type ZoneCaptureRequestData = {
    status: string;
    reason: string | null;
    issuedAt: string;
};
export type ZoneCountsData = {
    plants: number;
    buildings: number;
    problems: number;
    critters: number;
    eggs: number;
};
export type ZoneCritterData = {
    instanceId: string;
    prefabId: string;
    name: string;
    sprite: string | null;
    worldSprite: string | null;
    cell: number;
    x: number;
    y: number;
    facing: unknown;
    navType: string;
    moving: boolean;
    speed: number;
    path: {
        cell: number;
        navType: string;
    }[];
    aquatic: boolean;
    tame: boolean;
    baby: boolean;
    ageCycles: number | null;
    lifespanCycles: number | null;
    caloriesRatio: number | null;
    fertilityRatio: number | null;
    breeding: {
        egg: string;
        name: string;
        weight: number;
    }[];
    happiness: number | null;
    happinessState: string | null;
    stomach: {
        food: string;
        calories: number;
    }[];
    nextPoop: string | null;
    hungry: boolean;
    statuses: {
        id: string;
        name: string;
        bad: boolean;
    }[];
    regionId: string | null;
};
export type ZoneCrowdingRegionData = {
    id: string;
    kind: string;
    cellCount: number;
    occupied: number;
    eggOccupied: number;
    counts: Record<string, number>;
    states: Record<
        string,
        {
            overcrowded: boolean;
            cramped: boolean;
            confined: boolean;
            modifier: number;
        }
    >;
};
export type ZoneData = {
    id: number;
    guid: string;
    name: string;
    worldId: number;
    version: number;
    cellCount: number;
    orphaned: boolean;
    layoutAt: string | null;
    slowStateAt: string | null;
    fastStateAt: string | null;
    colonyGuid: string;
    fastSessionId: string | null;
    fastGameSeconds: number | null;
};
export type ZoneEggData = {
    instanceId: string;
    prefabId: string;
    name: string;
    sprite: string | null;
    cell: number;
    incubation: number | null;
    viability: number | null;
    tame: boolean;
    spawns: string | null;
    location: string | null;
    holderId: string | null;
    regionId: string | null;
    incubationCycles: number | null;
};
export type ZoneEntityData = {
    kind: 'plant' | 'building' | 'critter_group' | 'critter';
    instanceId: string | null;
    prefabId: string;
    name: string;
    sprite: string | null;
    x: number | null;
    y: number | null;
    state: 'growing' | 'grown' | 'wilting' | 'operational' | 'not_operational';
    problems: string[];
    growth: number | null;
    secondsUntilHarvest: number | null;
    autoHarvest: boolean | null;
    neededItemSprite: string | null;
    groupKey: string | null;
    count: number | null;
};
export type ZoneFeedingData = {
    groupKey: string;
    count: number;
    foods: ZoneFeedingLineData[];
    otherFoods: number;
    produces: ZoneFeedingLineData[];
    availableKg: number | null;
    deficitKgPerCycle: number | null;
    autonomyCycles: number | null;
    derived: boolean;
    blockedByFilter: boolean | null;
    untickedFoods: ZoneFeedingLineData[];
};
export type ZoneFeedingLineData = {
    resource: string;
    name: string;
    perCycle: number;
    kind: 'item' | 'plant' | 'prey' | 'dropping' | 'egg';
    inZone: number | null;
};
export type ZoneListItemData = {
    id: number;
    name: string;
    worldId: number;
    cellCount: number;
    orphaned: boolean;
    problemCount: number | null;
    problemsPartial: boolean;
    updatedAt: string | null;
};
export type ZoneMapData = {
    gridWidth: number;
    box: ZoneBoxData;
    zoneCells: Array<[number, number]>;
    tiles: ZoneTileData[] | null;
    buildings: ZoneBuildingData[] | null;
    temperatureBands: string[];
};
export type ZonePlantData = {
    x: number;
    y: number;
    instanceId: string | null;
    prefabId: string;
    name: string;
    sprite: string | null;
    growth: number | null;
    secondsUntilHarvest: number | null;
    wilting: boolean;
    grown: boolean;
};
export type ZonePopulationData = {
    regionId: string;
    kind: string;
    cellCount: number;
    occupied: number;
    eggOccupied: number;
    overcrowded: boolean;
    cramped: boolean;
    confined: boolean;
    states: { id: 'confined' | 'overcrowded' | 'cramped'; name: string }[];
    perType: CritterOverOccupancyData[];
    types: {
        prefabId: string;
        name: string;
        count: number;
        personalSpace: number | null;
    }[];
};
export type ZoneShowData = {
    zone: ZoneData;
    map: ZoneMapData | null;
    entities: ZoneEntityData[];
    problems: ZoneEntityData[];
    borderEntities: ZoneEntityData[];
    counts: ZoneCountsData;
    plantsKnown: boolean;
    buildingStatesKnown: boolean;
    captures: ZoneCaptureData[];
    captureRequest: ZoneCaptureRequestData | null;
    linkSeenAt: string | null;
    population: ZonePopulationData[] | null;
    critters: ZoneCritterData[] | null;
    eggs: ZoneEggData[] | null;
    mapTerrain: ZoneTerrainData | null;
    mapPlants: ZonePlantData[] | null;
    crittersAt: string | null;
    feeding: ZoneFeedingData[] | null;
    liveProps: Record<string, string[]>;
    echo: { channel: 'uplink'; event: '.zone.updated' };
    fastRelay: {
        channel: 'uplink-data';
        event: '.client-zone.fast';
        schema: number;
        tripsEvent: '.client-zone.trips';
        tripsSchema: number;
    };
};
export type ZoneSpriteRectData = {
    x: number;
    y: number;
    w: number;
    h: number;
};
export type ZoneTerrainData = {
    box: ZoneBoxData;
    palette: ZoneTerrainElementData[];
    element: number[] | null;
    mass: (number | null)[] | null;
    temperature: (number | null)[] | null;
    previousTemperature: (number | null)[] | null;
    previousSeconds: number | null;
};
export type ZoneTerrainElementData = {
    elementId: string | null;
    colour: string | null;
    name: string | null;
    state: 'solid' | 'liquid' | 'gas' | 'special' | null;
    renderColour: string | null;
    substanceTexture: SubstanceTexture | null;
    usesCaustics: boolean;
    glows: boolean;
    metallic: boolean;
    opaqueLiquid: boolean;
    scrollSpeed: number | null;
    maxMass: number | null;
    terrainTexture: string | null;
    lowTemp: number | null;
    highTemp: number | null;
};
export type ZoneTileData = {
    x: number;
    y: number;
    prefabId: string;
    connections: number;
};
