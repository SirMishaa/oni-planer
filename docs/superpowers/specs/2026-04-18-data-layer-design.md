# Data Layer Design — ONI Planner

## Overview

This spec covers the data layer for the ONI Planner: a production chain calculator for Oxygen Not Included (all DLCs). The data layer is the foundation that all calculators (farm, ranch, geyser, food, cooling, rocket) depend on.

**Scope:** Game data extraction pipeline + database schema. Does not cover the planner UI or calculator logic.

---

## Tech Stack

- **Backend:** Laravel 13, PHP 8.5, SQLite (dev) / MySQL (prod)
- **Frontend:** Inertia.js + Vue
- **Localisation:** `spatie/laravel-translatable` — JSON columns `{"en": "Water", "fr": "Eau"}`
- **Game data source:** Game files directly (`game-data/OxygenNotIncluded_Data/`)
- **DLC coverage:** Base game + expansion1 (Spaced Out) + dlc2 + dlc3 + dlc4

---

## Extraction Pipeline

Game data lives in two forms that require different extraction strategies:

### 1. YAML files (parsed directly by PHP)

Located in `game-data/OxygenNotIncluded_Data/StreamingAssets/`:

| File | Content |
|------|---------|
| `elements/gas.yaml` | Gaseous elements |
| `elements/liquid.yaml` | Liquid elements |
| `elements/solid.yaml` | Solid elements |
| `elements/special.yaml` | Special elements |
| `strings/strings_template.pot` | English strings (gettext format) |

### 2. Assembly-CSharp.dll (C# AOT binary extractor)

Building configs, recipes, critter configs, and plant configs are compiled into `Managed/Assembly-CSharp.dll`. A dedicated C# tool handles extraction:

```
dotnet-extractor/          ← C# project, compiled as .NET 8 AOT single binary
  → json-cache/
      buildings.json
      recipes.json
      critters.json
      plants.json
      geyser_types.json
      rocket_components.json
      duplicant_traits.json
```

The extractor outputs structured JSON that maps cleanly onto the DB schema after transformer processing.

### Full pipeline

```
game-data/StreamingAssets/elements/*.yaml  ─────┐
game-data/StreamingAssets/strings/*.pot    ─────┤
game-data/StreamingAssets/dlc/             ─────┼──→ php artisan game:import ──→ DB
json-cache/buildings.json                  ─────┤
json-cache/recipes.json                    ─────┤
json-cache/critters.json                   ─────┤
json-cache/plants.json                     ─────┤
json-cache/...                             ─────┘
       ↑
  ./dotnet-extractor (run first, outputs json-cache/)
```

### Import command

```
php artisan game:import [--game-path=game-data/]
```

Steps:
1. Run `dotnet-extractor` → populates `json-cache/`
2. Truncate all game data tables (destructive, full reimport)
3. Parse element YAML files → `elements` + state-specific extension tables
4. Parse `.pot` strings → resolve `localizationID` → populate `name`/`description` JSON columns
5. Import `json-cache/` → buildings, recipes, critters, plants, geysers, rockets, duplicant traits
6. Output report: X elements, Y buildings, Z recipes, etc.

### French localisation

The French translation is distributed via Steam Workshop (not bundled with the game). Source TBD — likely downloadable via SteamCMD using the Workshop item ID. English is imported first; French is added when the source is confirmed.

### CI/CD (planned)

```yaml
# GitHub Actions
- uses: steamcmd to download ONI (App ID: 457140, requires Steam credentials in secrets)
- run: ./dotnet-extractor
- run: php artisan game:import
```

The game is lightweight enough to cache between runs.

---

## Database Schema — 27 Tables

### Elements — Class Table Inheritance

All external FKs (recipes, geysers, critter diets, etc.) reference `elements`. State-specific data lives in 1:1 extension tables, making it easy to add state-specific properties without touching the base table.

**`elements`** — identity table, all FKs point here
```
id, element_id (string, unique)
state enum(gas, liquid, solid, special)
molar_mass (float)
toxicity (float)
material_category (string)        -- "RawMineral", "Metal", "Unbreathable"…
tags (json)
low_temp_transition_target (FK → elements, nullable)
high_temp_transition_target (FK → elements, nullable)
name (json)                       -- {"en": "Water", "fr": "Eau"}
description (json, nullable)
dlc_id (string, nullable)
is_disabled (bool)
```

**`element_thermal_properties`** — 1:1 with elements, all states
```
element_id (PK + FK → elements)
specific_heat_capacity (float)
thermal_conductivity (float)
low_temp (float, nullable, Kelvin)
high_temp (float, nullable, Kelvin)
default_temperature (float, Kelvin)
light_absorption_factor (float)
radiation_absorption_factor (float)
radiation_per_1000_mass (float)
```

**`element_gas_properties`** — 1:1 with gas elements
```
element_id (PK + FK → elements)
flow (float)
default_pressure (float, kg)
gas_surface_area_multiplier (float)
is_breathable (bool)
is_toxic (bool)
```

**`element_liquid_properties`** — 1:1 with liquid elements
```
element_id (PK + FK → elements)
flow (float)
liquid_surface_area_multiplier (float)
```

**`element_solid_properties`** — 1:1 with solid elements
```
element_id (PK + FK → elements)
solid_surface_area_multiplier (float)
hardness (int, nullable)
is_ore (bool)
is_metal (bool)
is_refined_metal (bool)
```

**`element_special_properties`** — 1:1 with special elements
```
element_id (PK + FK → elements)
-- Reserved for future special-case properties (Vacuum, Neutronium…)
```

**`food_properties`** — 1:1 with food elements (foods are elements with extra props)
```
element_id (PK + FK → elements)
calories (float, kcal)
quality enum(bland, good, great, excellent)
can_rot (bool)
```

---

### Duplicants

**`duplicant_base_stats`** — singleton, updated on every game:import
```
id
oxygen_consumption_gs (float)      -- 100 g/s = 60 kg/cycle
co2_production_gs (float)          -- 2 g/s = 1.2 kg/cycle
calories_per_cycle (int)           -- 1000 kcal
mass_kg (float)                    -- 30 kg
bladder_fill_per_cycle (float)     -- 1.0 (100%)
```

**`duplicant_traits`**
```
id, trait_id (string, unique)
is_positive (bool)
name (json), description (json)
dlc_id (string, nullable)
```

**`duplicant_trait_effects`**
```
id
trait_id (FK → duplicant_traits)
stat (string)                      -- "calories_per_cycle", "oxygen_consumption_gs"
modifier (float)
modifier_type enum(multiply, add)
```

---

### Geysers

**`geyser_types`** — 25 types (base + DLCs). All yield/timing values are min/max because each in-game instance is randomised within fixed bounds.
```
id, geyser_id (string, unique)
type enum(geyser, vent, volcano, fissure)
element_id (FK → elements)
temperature (float, Kelvin)
max_pressure (float, kg)
min_yield_rate, max_yield_rate (float, kg/s)
min_eruption_duration, max_eruption_duration (float, seconds)
min_eruption_period, max_eruption_period (float, seconds)
dormancy_min_cycles, dormancy_max_cycles (float)
name (json)
dlc_id (string, nullable)
```

---

### Buildings, Recipes & Construction

**`buildings`**
```
id, building_id (string, unique)
category (string)
power_consumption (float, nullable, W)
power_generation (float, nullable, W)
heat_generation (float, kDTU/s)
width, height (int, tiles)
construction_time (float, seconds)
tags (json)
name (json), description (json)
dlc_id (string, nullable)
```

**`recipes`**
```
id
building_id (FK → buildings)
duration (float, seconds)
fabricators (json)
name (json)
```

**`recipe_items`**
```
id
recipe_id (FK → recipes)
element_id (FK → elements)
amount (float)
role enum(input, output)
```

**`building_construction_materials`**
```
id
building_id (FK → buildings)
amount (float, kg)
material_category (string, nullable)   -- "RawMineral", "Metal" — player chooses within category
element_id (FK → elements, nullable)   -- set only when a specific element is required
-- Constraint: exactly one of material_category or element_id is set, never both, never neither.
```

---

### Critters & Morphs

`critters` groups species. All real data (diet, outputs, stats) lives on `critter_morphs`. Every critter has at least one morph with `is_base = true`. Variants (Stone Hatch, Smooth Hatch, Sage Hatch) are additional morphs under the same critter.

**`critters`** — species grouping only
```
id, critter_id (string, unique)
name (json)
dlc_id (string, nullable)
```

**`critter_morphs`**
```
id, morph_id (string, unique)
critter_id (FK → critters)
is_base (bool)
min_temp, max_temp (float, Kelvin)
calories_per_cycle (float)
incubation_time (float, seconds)
lifespan (float, seconds)
overcrowding_threshold (int)
name (json)
```

**`critter_morph_diets`**
```
id
morph_id (FK → critter_morphs)
consumed_element_id (FK → elements)
amount_per_cycle (float, kg)
produced_element_id (FK → elements, nullable)   -- direct conversion output
conversion_ratio (float, nullable)
```

**`critter_morph_outputs`**
```
id
morph_id (FK → critter_morphs)
element_id (FK → elements)
amount_per_cycle (float, kg)
output_type enum(egg, dropping, resource)
```

---

### Plants, Variants & Mutations

Same pattern as critters. `plants` groups species; `plant_variants` holds all real data. Mutations (Spaced Out Botanical Analyzer) are modifiers on variant stats.

**`plants`** — species grouping only
```
id, plant_id (string, unique)
name (json)
dlc_id (string, nullable)
```

**`plant_variants`**
```
id, variant_id (string, unique)
plant_id (FK → plants)
is_base (bool)
min_temp, max_temp (float, Kelvin)
min_pressure, max_pressure (float, kg)
atmosphere_element_id (FK → elements, nullable)
light_required (bool)
growth_time (float, seconds)
name (json)
```

**`plant_variant_inputs`**
```
id
variant_id (FK → plant_variants)
element_id (FK → elements)
amount_per_cycle (float, kg)
input_type enum(irrigation, fertilizer)
```

**`plant_variant_outputs`**
```
id
variant_id (FK → plant_variants)
element_id (FK → elements)
amount_per_harvest (float, kg)     -- amount produced each time growth_time elapses
output_type enum(food, resource)
```

**`plant_mutations`**
```
id, mutation_id (string, unique)
name (json)
```

**`plant_mutation_effects`**
```
id
mutation_id (FK → plant_mutations)
stat (string)         -- "growth_time", "yield", "water_consumption"…
modifier (float)
modifier_type enum(multiply, add)
```

**`plant_variant_mutations`** — pivot: which mutations are discoverable per variant
```
variant_id (FK → plant_variants)
mutation_id (FK → plant_mutations)
```

---

### Rocketry (Spaced Out)

**`rocket_engines`**
```
id, engine_id (string, unique)
fuel_element_id (FK → elements, nullable)
oxidizer_element_id (FK → elements, nullable)
max_range (float, km)
fuel_consumption_rate (float, kg/s)
oxidizer_consumption_rate (float, nullable, kg/s)
exhaust_temperature (float, Kelvin)
name (json)
dlc_id (string, nullable)
```

**`rocket_modules`**
```
id, module_id (string, unique)
module_type enum(cargo_solid, cargo_liquid, cargo_gas, cargo_bio, utility, command)
mass (float, tonnes)
capacity (float, tonnes)
power_consumption (float, nullable, W)
name (json)
dlc_id (string, nullable)
```

---

## Table Count Summary

| Domain | Tables |
|--------|--------|
| Elements (CTI) | elements, element_thermal_properties, element_gas_properties, element_liquid_properties, element_solid_properties, element_special_properties, food_properties |
| Duplicants | duplicant_base_stats, duplicant_traits, duplicant_trait_effects |
| Geysers | geyser_types |
| Buildings | buildings, recipes, recipe_items, building_construction_materials |
| Critters | critters, critter_morphs, critter_morph_diets, critter_morph_outputs |
| Plants | plants, plant_variants, plant_variant_inputs, plant_variant_outputs, plant_mutations, plant_mutation_effects, plant_variant_mutations |
| Rocketry | rocket_engines, rocket_modules |
| **Total** | **27 tables** |

---

## Key Design Decisions

- **Destructive reimport** — `game:import` truncates all tables before importing. No incremental upsert. Game data is not manually edited.
- **Class Table Inheritance for elements** — `elements` is the identity table; all FKs point here. State-specific properties live in 1:1 extension tables for extensibility.
- **Species + variant pattern** — `critters`/`plants` are grouping-only. `critter_morphs`/`plant_variants` hold all real data, each with `is_base` flag.
- **Geysers use min/max ranges** — every in-game geyser instance has randomised values within fixed bounds. The planner lets users input their actual observed values.
- **`spatie/laravel-translatable`** — `name`/`description` columns stored as JSON for EN/FR support. EN from `.pot` file; FR from Steam Workshop (source TBD).
- **`dotnet-extractor` is a separate C# AOT binary** — single self-contained executable, no .NET runtime required on the host. Outputs to `json-cache/` which `game:import` then reads.

---

## Out of Scope (this spec)

- Planner UI and calculator logic
- User accounts and saved configurations
- Research tree / tech tree
- Duplicant skills affecting building efficiency
- Disease / germ system
