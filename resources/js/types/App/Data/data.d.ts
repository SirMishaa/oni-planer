export type BuildingData = {
building_id: string,
name: Record<string, string>,
category: string,
sub_group: string | null,
width: number,
height: number,
power_consumption: number | null,
power_generation: number | null,
dlc_id: string | null,
};
export type BuildingMaterialData = {
element_id: string | null,
material_category: string | null,
amount: number,
};
export type BuildingShowData = {
building_id: string,
name: Record<string, string>,
category: string,
sub_group: string | null,
width: number,
height: number,
power_consumption: number | null,
power_generation: number | null,
heat_generation: number | null,
construction_time: number | null,
description: Record<string, string>,
dlc_id: string | null,
materials: BuildingMaterialData[],
};
export type CodexArticleData = {
codex_id: string,
category: string,
parent_codex_id: string | null,
title: Record<string, string>,
icon_asset: string | null,
content: any[],
image: string | null,
links: CodexLinkData[],
};
export type CodexLinkData = {
type: string,
key: string,
label: string,
url: string | null,
};
export type CodexListItemData = {
codex_id: string,
category: string,
parent_codex_id: string | null,
title: Record<string, string>,
image: string | null,
};
export type CritterDietData = {
consumed: ElementRefData,
produced: ElementRefData | null,
amount_per_cycle: number,
conversion_ratio: number | null,
};
export type CritterFamilyData = {
slug: string,
name: string,
description: Record<string, string> | null,
image: string | null,
variants: CritterVariantData[],
};
export type CritterFamilyListItemData = {
slug: string,
name: string,
image: string | null,
variant_count: number,
eats_summary: string | null,
makes_summary: string | null,
};
export type CritterMorphData = {
morph_id: string,
is_base: boolean,
name: Record<string, string>,
min_temp: number,
max_temp: number,
calories_per_cycle: number,
incubation_time: number,
lifespan: number,
overcrowding_threshold: number,
diets: CritterDietData[],
outputs: CritterOutputData[],
};
export type CritterOutputData = {
kind: string,
element_id: string,
label: string,
icon: string | null,
url: string | null,
amount_per_cycle: number,
};
export type CritterVariantData = {
critter_id: string,
name: Record<string, string>,
image: string | null,
adult: CritterMorphData,
baby: CritterMorphData | null,
};
export type ElementData = {
element_id: string,
name: Record<string, string>,
state: string,
material_category: string,
};
export type ElementRefData = {
element_id: string,
name: string,
icon: string | null,
url: string | null,
};
export type ElementShowData = {
element_id: string,
name: Record<string, string>,
description: Record<string, string> | null,
state: string,
material_category: string,
molar_mass: number,
toxicity: number,
tags: string[],
low_temp_transition_target: string | null,
high_temp_transition_target: string | null,
thermal: ThermalPropertyData | null,
};
export type RanchCatalogData = {
families: CritterFamilyData[],
elements: Record<string, ElementRefData>,
};
export type ThermalPropertyData = {
specific_heat_capacity: number,
thermal_conductivity: number,
low_temp: number | null,
high_temp: number | null,
default_temperature: number,
};
