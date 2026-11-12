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
};
export type ZoneCountsData = {
    plants: number;
    buildings: number;
    problems: number;
};
export type ZoneData = {
    id: number;
    name: string;
    worldId: number;
    version: number;
    cellCount: number;
    orphaned: boolean;
    layoutAt: string | null;
    slowStateAt: string | null;
    fastStateAt: string | null;
};
export type ZoneEntityData = {
    kind: 'plant' | 'building';
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
};
export type ZoneListItemData = {
    id: number;
    name: string;
    worldId: number;
    cellCount: number;
    orphaned: boolean;
    problemCount: number | null;
    updatedAt: string | null;
};
export type ZoneMapData = {
    gridWidth: number;
    box: ZoneBoxData;
    zoneCells: Array<[number, number]>;
    terrain: ZoneTerrainCellData[] | null;
    tiles: ZoneTileData[] | null;
    buildings: ZoneBuildingData[] | null;
    plants: ZonePlantData[] | null;
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
export type ZoneShowData = {
    zone: ZoneData;
    map: ZoneMapData | null;
    entities: ZoneEntityData[];
    problems: ZoneEntityData[];
    counts: ZoneCountsData;
    plantsKnown: boolean;
    buildingStatesKnown: boolean;
    echo: { channel: 'uplink'; event: '.zone.updated' };
};
export type ZoneTerrainCellData = {
    x: number;
    y: number;
    elementId: string | null;
    colour: string | null;
    mass: number | null;
    temperature: number | null;
};
export type ZoneTileData = {
    x: number;
    y: number;
    prefabId: string;
    connections: number;
};
