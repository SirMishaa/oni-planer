export type ElementData = {
element_id: string,
name: Record<string, string>,
state: string,
material_category: string,
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
export type ThermalPropertyData = {
specific_heat_capacity: number,
thermal_conductivity: number,
low_temp: number,
high_temp: number,
default_temperature: number,
};
