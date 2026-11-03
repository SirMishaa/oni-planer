# OniExtract export — field audit

Companion to `2026-11-03-oniextract-data-pipeline-design.md`. Input of Task 3 (mod extensions)
and of the reader tasks (6–12).

- **Export audited:** `Documents/Klei/OxygenNotIncluded/export` (fork build 43ce7c2+, game
  U59-744825, French UI). Read in place.
- **Oracle:** current SQLite DB (`database/database.sqlite`, imported 2026-10-31 from
  `json-cache/*.json`, Cecil extractor) and `dotnet-extractor/Extractors/*.cs`.
- **Game files:** `game-data/OxygenNotIncluded_Data` (YAML, `strings_template.pot`,
  `Managed/Assembly-CSharp.dll` decompiled with `ilspycmd` to settle attributions).
- **Mods:** 51 enabled (`mods/mods.json`); 19 contribute buildings (`building.json` `mods`);
  only Ronivan's Legacy – Industrial Revolution (`3557584850`, assembly
  `RonivansLegacy_ChemicalProcessing`) is supported. Other mods whose content or
  changes reach the export: `[Vanilla + DLC] Pacu morphs` (2020793956, adds 3 critters),
  `Adjustable Coolers` (2163720966, patches AirConditioner/LiquidConditioner).

Status legend: **ok** = present, value matches the DB or the DB value is wrong (see
Differences); **missing** = not in the export, needs a mod extension or a constant;
**differs** = present but in another shape or meaning, the reader task has to decide.

## Key findings

1. The brief's mappings hold, with four corrections:
   - `critter_morphs.is_base` means "adult" (not a baby), not `OriginalCreature`. All 111
     matched morphs: `is_base == (babyMonitorDef == null)`. `OriginalCreature` disagrees on 53
     (all non-original adult morphs, and babies of original species carry the tag).
   - A baby has no `{babyId}BaseTrait`. It shares its adult's trait id, so use
     `traits[Id = {babyMonitorDef.adultPrefab}BaseTrait]`. `db.json traits` holds 57 ids
     twice (adult and baby registrations). `CaloriesDelta` and `AgeMax` are identical between
     the two copies, so take the first.
   - Plant temperatures in the DB are the **lethal** pair (40 of 50 rows) or garbage. The
     brief maps them to the warning pair (`temperatureWarningLow/High`), which is the growth
     range the game shows. Recommend warning; the change is deliberate.
   - The old extractor has **no scale/molt outputs**: the DB only has `egg` outputs (52 rows).
     The export has `scaleGrowthMonitorDef` (enough to compute shearing) and
     `moltDropperMonitorDef` (no rate: molt timing is a delegate).
2. **`power_generation` is missing.** `GeneratorWattageRating` is not in `building.json`
   (`energyGenerator` only has the fuel formula). This adds one extension to the brief's list.
3. **Per-building DLC is present** (`kPrefabID.requiredDlcIds`, filled for 141 defs). The
   spec's "requiredDlcIds null" no longer applies, so no extension is needed.
4. **The export has no mod attribution outside buildings.** Entities (critters, items, eggs,
   seeds, foods) and recipes need a source-mod field. Without one, unsupported Pacu morphs
   would be imported as base content. Recipes Ronivan adds to base fabricators could not be
   badged or removed: 58 of the 230 base-fabricator recipes involve Ronivan materials,
   and they mix added, changed and alternative-material recipes.
5. **Ronivan re-enables YAML-disabled game elements at runtime.** It ships
   `ElementLoader_CollectElementsFromYAML_Patch`. Seven elements are `isDisabled: true` in the
   game YAML but used by export recipes: Brick, Cement, Electrum, Syngas, Propane,
   PhosphateNodules and Yellowcake. It also changes values on them (Brick `highTemp`
   1683→2000, Electrum `highTemp` 1337→1295.86, Cement `thermalConductivity` 8→3.11).
   A YAML-only importer drops these elements and shows stale values, so runtime
   `Element.disabled` is required.
6. **Name keys need no extension.** Buildings and elements follow the `{UPPER(id)}` rule.
   `po_string.json` is a key → localized-text dump of the game strings (keys without the
   `STRINGS.` prefix). Reverse-looking up an entity's `nameString` in it gives the key for
   every supported critter, plant, egg and seed, with documented tie-breaks (see Prefab
   names).
7. The old extractor's critter, plant, recipe and rocket values are wrong in many places
   (wrong constructor argument, unresolved tuning expressions, class-name-derived ids). The
   export matches the decompiled game code wherever both were checked. No difference is a
   regression of the export.

## Field table

### buildings (DB 349 → export 663 defs, 555 buildable)

| column | export source | formula | status |
|---|---|---|---|
| building_id | `building.json bBuildingDefList[].name` | — | ok (3 DB ids absent: `Telescope`, `MissionControl`, `CosmicResearchCenter`, base-game-only, expected in a Spaced Out export) |
| category | `buildingAndSubcategoryDataPairs` (key = category name, `[].Key` = building, `[].Value` = subcategory) | category name → DB label: `hvac`→`HVAC`, `hep`→`HEP`, others ucfirst. Not in pairs and in `rocketModuleMenu` (32) → `Rocketry`. Otherwise `Other` | differs: 75 DB values wrong (old map). Subcategory is new (see Building editor fields) |
| power_consumption | `energyConsumer.baseWattageRating` (= `BuildingDef.EnergyConsumptionWhenActive`, only when the building has an `EnergyConsumer`) | >0 else null | ok (5 diffs, see Differences) |
| power_generation | — | `BuildingDef.GeneratorWattageRating` | **missing** → extension |
| heat_generation | — | `BuildingDef.SelfHeatKilowattsWhenActive` (old value); exhaust is a separate field | **missing** → extension |
| width / height | `widthInCells` / `heightInCells` | — | ok (0 diffs) |
| construction_time | — | `BuildingDef.ConstructionTime` | **missing** → extension |
| tags | `kPrefabID.tags[].Name` | — | ok (DB always `[]`) |
| name / description / effect | mod or game strings | `STRINGS.BUILDINGS.PREFABS.{UPPER(id)}.NAME/DESC/EFFECT` | ok: base 459/463 (exceptions `AtomicGarden`, `CrewCapsule` deprecated; `StaterpillarGas/LiquidConnector` not buildable; all `MISSING.` in game too). Ronivan 144/145 (`AIO_BigNuclearReactor`, deprecated, no key anywhere) |
| dlc_id | `kPrefabID.requiredDlcIds[0]` | null when empty. One def has two ids (`EXPANSION1_ID`, `DLC3_ID`); the column takes one | ok (15 diffs, old bug) |
| (mod) | `mod`, `modTitle` per entry (absent = base) | English title from `mods/Steam/{id}/mod.yaml title` | ok, no extension (export `modTitle` is localized) |

Editor fields for Task 6, all present per def: `buildLocationRule`, `permittedRotations`,
`sceneLayer`, `objectLayer`, `viewMode`, `isFoundation`, `isKAnimTile`, `isUtility`,
`dragBuild`, `utilities[]` (`type`, `offset`, `isSecondary`), `powerInputOffset`,
`powerOutputOffset`, `materialCategory[]`/`materialMass[]`, `uiImageRect`, `deprecated`,
`debugOnly`, `showInBuildMenu`.

### elements (DB 196 → export 266) and element_* properties

Plan keeps YAML (game `StreamingAssets/elements/{gas,liquid,solid,special}.yaml` plus
`mods/Steam/3557584850/elements/*.yaml`). Ronivan's YAML uses the same schema, with
`state:` per element (Solid 32, Liquid 13, Gas 9) instead of one file per state. It always
has `localizationID` and `dlcId: ''`, and `isDisabled` is false for all 54.

| column | source | status |
|---|---|---|
| element_id, molar_mass, toxicity, material_category, tags, low/high_temp_transition_target, dlc_id, description | YAML (`elementId`, `molarMass`, `toxicity`, `materialCategory`, `tags`, `*TransitionTarget`, `dlcId`, `localizationID`→`.DESC`) | ok. `tags`: runtime `elements.json oreTags` = YAML tags + state + category + mod-added tags (`WallMachine*`, `AIO_*`; Brick gains `BuildableRaw`; Propane/Bitumen lose `HideFromCodex`). Prefer `oreTags` |
| name | YAML `localizationID` (Ronivan: equals `STRINGS.ELEMENTS.{UPPER(id)}.NAME` for 54/54, DESC present for 54/54) | ok |
| is_disabled | YAML `isDisabled` | **differs**: runtime differs for Ronivan-enabled elements (finding 5) → extension `Element.disabled` |
| thermal: specific_heat_capacity, thermal_conductivity, low_temp, high_temp, default_temperature | YAML, and the same fields in `elements.json` | ok. Use `elements.json` (runtime): 3 values differ from YAML (finding 5) |
| thermal: light/radiation_absorption_factor, radiation_per_1000_mass | YAML only | ok (runtime may differ for patched elements, see recommended extension) |
| gas: flow, default_pressure, gas_surface_area_multiplier, is_breathable, is_toxic | YAML only | ok |
| liquid: flow, liquid_surface_area_multiplier | YAML only | ok |
| solid: solid_surface_area_multiplier, hardness, is_ore/is_metal/is_refined_metal | YAML (`hardness` also in `elements.json`); flags from tags | ok |

Coverage: every one of the 266 `elements.json` ids is in game YAML (212, including 16
`isDisabled`) or Ronivan YAML (54). There is no overlap, no id from another mod, and no
YAML id missing from the export. All 196 DB ids are in the export.

### recipes (DB 115 / json-cache 118 → export 451) and recipe_items

| column | export source | formula | status |
|---|---|---|---|
| building_id | `recipe.json recipes[].fabricators[0].Name` (always exactly 1) | — | ok |
| fabricators | `fabricators[].Name` | — | ok |
| duration | `time` | seconds | ok (34 diffs, old bug) |
| name | `customName` (localized), `nameDisplay` | — | differs: DB stores `['en' => null]` almost always. No key in export (not a regression) |
| recipe_items element_id / amount / role | `ingredients[]` / `results[]`: `material.Name`, `amount` | — | ok. 114 ingredients are "any of" (`material.Name` null, choices in `possibleMaterials` + `possibleMaterialAmounts`): Task 9 decides |
| (id) | `id` | — | not unique: `MissileFabricator_I_0_0_0_O_MissileLongRange` appears twice (two different base recipes) |
| (mod) | — | — | **missing**: 221 recipes are on Ronivan fabricators (attributable through the building). Recipes Ronivan *adds* to base fabricators (Kiln→Brick, RockCrusher/MetalRefinery Electrum, CraftingTable gaskets/drillbits…) can't be told from base recipes it *changes* (MetalRefinery recipes now also output `SolidSlag`) → extension |
| (HEP) | `consumedHEP`, `producedHEP` | — | new, available |

### critters (DB 30 critters / 113 morphs → export 119 creatures)

Selection rule: `entities.json` with tag `CreatureBrain` **and** a
`critterTemperatureMonitorDef`. This excludes `SweepBot`, `BeeHive` and `ShockWorm` and
gives 111 DB morphs + `DivergentWormBaby` and `MosquitoBaby` (DB ids `WormBaby` and
`MosquitoLarva`, derived from config class names) + 6 Pacu-morph entities (unsupported mod,
skipped). Ids are the real prefab ids: `Oilfloater*` (DB `OilFloater*`).

| column | export source | formula | status |
|---|---|---|---|
| critters.critter_id | adult prefab id; baby → `babyMonitorDef.adultPrefab.Name` | — | ok |
| critters.species_tag | — | `CreatureBrain.species.Name` | **missing** → extension |
| critters.variant_family | — | derivable from species tag: strip `Species` (`HatchSpecies`→`Hatch`). Exceptions: `BeetaSpecies`→`Bee`; `GlomSpecies`/`ParrotFishSpecies`/`PufferFishSpecies` → null today | differs (rule) |
| critters.dlc_id | `kPrefabID.requiredDlcIds[0]` | — | ok (8 diffs `DLC5`→`DLC5_ID`, old bug) |
| critters.name / description | `po_string.json` reverse lookup of `nameString` (see Prefab names) | — | ok |
| critter_morphs.is_base | `babyMonitorDef` | `is_base = babyMonitorDef == null` | ok (**not** `OriginalCreature`) |
| min_temp / max_temp | `critterTemperatureMonitorDef.temperatureColdUncomfortable` / `…HotUncomfortable` | — | ok (adults 0 diffs; babies DB 0, export has values) |
| calories_per_cycle | `db.json traits[Id={adult}BaseTrait].SelfModifiers[AttributeId=CaloriesDelta].Value` | `-Value × 600`; absent → 0 | ok (Hatch −1166.67 → 700000 ✓) |
| lifespan | same trait, `AgeMax` | — | ok (babies via adult trait: 52/52 match) |
| incubation_time | `items.json eggs[name=fertilityMonitorDef.eggPrefab].incubatorMonitorDef.baseIncubationRate` | `100 / (rate × 600)` | ok (Hatch 20 ✓) |
| overcrowding_threshold | `overcrowdingMonitorDef.spaceRequiredPerCreature` | — | ok |
| diets: consumed_element_id | `creatureCalorieMonitorDef.diet.infos[].consumedTags[].Name` | one row per tag | ok |
| diets: amount_per_cycle | `infos[].caloriesPerKg` | `calories_per_cycle / caloriesPerKg` | ok (Hatch Sand 140 ✓) |
| diets: produced_element_id / conversion_ratio | `infos[].producedElement.Name` / `producedConversionRate` | — | ok |
| outputs `egg` | `fertilityMonitorDef.eggPrefab`, `baseFertileCycles` | `1 / baseFertileCycles` | ok (0 rate diffs) |
| egg morph chances | `fertilityMonitorDef.initialBreedingWeights[]` | — | new (no DB column) |
| outputs scale (new) | `scaleGrowthMonitorDef` | `dropMass × defaultGrowthRate × 600` kg/cycle of `itemDroppedOnShear` (Drecko 0.25, DreckoPlastic 50) | new |
| outputs molt (new) | `moltDropperMonitorDef.onGrowDropID`, `massToDrop` | no rate in data (delegate) | new, rate missing (not a regression) |
| (kanim) | — | `KBatchedAnimController.AnimFiles[0].name` | missing (optional: today's critter pipeline uses `scripts/kanim/mapping.py` heuristics) |
| (mod) | — | — | **missing** → extension (Pacu morphs) |

### plants (DB 53 → export 54 tagged `Plant`)

Ids: `BasicFabricPlant`, `SeaTree`, `WaterCups` (DB `BasicFabricMaterialPlant`, `SeaTreeRoot`,
`WaterCupsPlant` are class-name derived). `SuperWormPlant` is new (base game).

| column | export source | formula | status |
|---|---|---|---|
| plant_id / variant_id | `name` | variant = plant (DB never had real variants) | ok |
| min_temp / max_temp | `temperatureVulnerable.temperatureWarningLow/High` | — | differs: DB holds lethal (decision above) |
| min_pressure / max_pressure | `pressureVulnerable.pressureWarning_Low/High` | `±3.40282347e38` (not pressure-sensitive) → null | differs: DB holds (`pressureLethal_Low`, `pressureWarning_Low`) |
| atmosphere_element_id | `pressureVulnerable.safe_atmospheres[]` | list, the column is single | differs (DB all null): Task 11 decides |
| light_required | `illuminationVulnerable` | `!= null && !prefersDarkness` | ok (DB all false) |
| growth_time | `cropVal.cropDuration` | `/ 600` cycles | ok (DB all 0) |
| inputs | `irrigationDef/fertilizationDef.consumedElements[]` (`tag.Name`, `massConsumptionRate`) | `× 600` kg/cycle | ok (DB empty) |
| outputs | `cropVal` (`cropId`, `numProduced`), `seedInfo` (`seedId`, `newSeedsProduced`) | — | ok (DB empty) |
| dlc_id | `kPrefabID.requiredDlcIds[0]` | — | ok (DB `DLC5` vs `DLC5_ID`) |
| name / description | `po_string.json` reverse lookup | — | ok |
| (kanim) | — | `KBatchedAnimController.AnimFiles[0].name` | **missing** → extension (asset worklist reads it today from `json-cache/plants.json`) |

### geyser_types (DB 27 → export 35)

| column | export source | formula | status |
|---|---|---|---|
| geyser_id | `geyser.json geysers[].geyserType.id` | — | ok |
| type | — | id rule of the old extractor (`volcano`/`molten_*` → volcano, `vent` → vent, else geyser) | ok (rule) |
| element_id | `geyserType.element` (SimHash) → `tags.json SimHashes` reverse | — | ok |
| temperature, max_pressure | `temperature`, `maxPressure` | — | ok |
| min/max_yield_rate | `minRatePerCycle` / `maxRatePerCycle` | — | ok |
| min/max_eruption_duration | `minIterationPercent` / `maxIterationPercent` | — | ok |
| min/max_eruption_period | `minIterationLength` / `maxIterationLength` | — | ok |
| dormancy_min/max_cycles | `minYearLength` / `maxYearLength` (seconds, as the DB stores them) | — | ok |
| name | `nameStringKey.String` | — | ok |
| dlc_id | `geyserType.requiredDlcIds[0]` / `DlcID` | — | ok |
| (kanim) | `anim` | — | ok |
| (mod) | — | `nameStringKey` found in a supported mod's `.pot` | ok, derivable: the 8 new geysers are Ronivan's (`AmmoniaGeyser`, `AmmoniumWaterGeyser`, `HotMercuryGasVent`, `MoltenSaltGeyser`, `PhosphorusGeyser`, `RawGasVent`, `SourWaterGeyser`, `NitrogenVent`) |

All 27 DB geysers: 0 differences on every column (element, values, dlc, name key, kanim).

### duplicant_base_stats / duplicant_traits / duplicant_trait_effects

| column | export source | formula | status |
|---|---|---|---|
| oxygen_consumption_gs | `traits[Id=MinionBaseTrait] AirConsumptionRate` (0.1) | `× 1000` | ok (100) |
| co2_production_gs | `entities[Minion].oxygenBreather.O2toCO2conversion` (0.02) | `AirConsumptionRate × conv × 1000` | ok (2) |
| calories_per_cycle | `MinionBaseTrait CaloriesDelta` (−1666.67) | `−v × 600 / 1000` kcal | ok (1000) |
| mass_kg | `entities[Minion].primaryElement.Mass` | — | ok (30) |
| bladder_fill_per_cycle | `MinionBaseTrait BladderDelta` (0.1667 %/s) | `v × 600 / 100` | ok (1) |
| trait_id | `db.json traits[].Id` | exclude `*BaseTrait` (critters), `{plantId}Original` (54), `StartWithBooster_*` (10) → exactly the DB's 132 | ok (132/132) |
| is_positive | `PositiveTrait` | — | ok (5 diffs, old bug) |
| name | `STRINGS.DUPLICANTS.TRAITS.{UPPER(id)}.NAME` | — | ok: 118/132. The 14 exceptions (`None`, `Stinky`, `Ellie`, `Joshua`, `Liam`, `Minnow`, `Claustrophobic`, `PrefersWarmer/Colder`, `SensitiveFeet`, `Fashionable`, `Climacophobic`, `SolitarySleeper`, `Workaholic`) use the `po_string` reverse lookup. json-cache had no keys at all |
| dlc_id | — | DB all null | ok |
| effects stat / modifier / modifier_type | `SelfModifiers[]`: `AttributeId`, `Value`, `IsMultiplier` | — | ok (DB has 17 traits with effects, most with wrong sign/scale, e.g. Ellie `AirConsumptionRate` 0.45 vs −0.045) |

### rocket_engines (DB 10 → 7 in export) / rocket_modules (DB 24 → 14 in export)

Absent: `BiodieselEngine`, `HydrogenEngine`, `SteamEngine`, `CommandModule`, `GasCargoBay`,
`LiquidCargoBay`, `LiquidFuelTank`, `OxidizerTank`, `OxidizerTankLiquid`, `SolidCargoBay`,
`SpecialCargoBay`, `RoboPilotCommandModule`. These are base-game-only rocket parts, expected
in a Spaced Out export. `SolidCargoBayCluster` is the class name; the real id is
`CargoBayCluster`. 12 `isRocketModule` defs are in the export but not in the DB
(`CargoBayCluster`, `SugarEngine`, `BatteryModule`, `HabitatModule*`, `ScoutModule`, …).

| column | export source | status |
|---|---|---|
| engine_id / module_id | building def `name` with `isRocketModule` | ok |
| fuel_element_id | `rocketEngineCluster.fuelTag.Name` | ok (BiodieselEngineCluster `RefinedLipid`→`AIO_BioFuel`, Ronivan) |
| oxidizer_element_id | `rocketEngineCluster.requireOxidizer` (bool) | differs: Spaced Out engines take any oxidizer |
| max_range | — | missing, no Spaced Out source (range = fuel / `rocketModulePerformance.fuelKilogramPerDistance`). DB all 0 |
| fuel_consumption_rate | `rocketModulePerformance.fuelKilogramPerDistance` | ok (DB all 0) |
| oxidizer_consumption_rate | — | DB null; no source |
| exhaust_temperature | `rocketEngineCluster.exhaustTemperature` | ok (DB 0 for CO2/Steam engines, old bug) |
| module_type | `cargoBayCluster.storageType` (0 solid, 1 liquid, 2 gas) plus id rule | differs (old rule from ids) |
| mass | `materialMass[]` sum | ok (DB all 0) |
| capacity | `cargoBayCluster.MaxCapacity` else `storage.capacityKg` | ok (DB mostly 0) |
| power_consumption | `energyConsumer.baseWattageRating` | ok |
| (new) burden, enginePower, maxHeight | `rocketModulePerformance`, `rocketEngineCluster.maxHeight` | new |

### prefab_names

| prefab kind | key source | coverage |
|---|---|---|
| elements | `STRINGS.ELEMENTS.{UPPER(id)}.NAME` (game pot or mod pot) | 266/266 |
| buildings | `STRINGS.BUILDINGS.PREFABS.{UPPER(id)}.NAME` | all buildable |
| critters, babies, eggs, plants, seeds | `po_string.json` reverse lookup of `nameString` → key, prefix `STRINGS.`; keep `*NAME` keys | critters 113/113 (2 ambiguous), plants 54/54 (3 ambiguous), eggs 53/53 (5 ambiguous), seeds 47/47. None found only for the Pacu morphs (unsupported mod) |
| geysers | `nameStringKey.String` | 35/35 |
| referenced items (foods, shells, items) | game: `po_string` reverse lookup or segment search (today's `findNameKey`); Ronivan: key ending `.{UPPER(id)}.NAME` in the mod pot (group varies: `ITEMS.INDUSTRIAL_PRODUCTS`, `ITEMS.INGREDIENTS`) | Ronivan 11/20. The 9 `Mining_GuidanceDevice_*` are composed at runtime (`…MINING_DRILLBITS_GUIDANCEDEVICE_ITEM.NAME_PROGRAMMED` with `{0}` = `STRINGS.UI.MINING_SMART_DRILL_LOCATIONS.{SUFFIX}`) |

Tie-breaks, verified on the French export: critters prefer `CREATURES.SPECIES.*` keys that
don't end in `EGG_NAME`. This drops `BUILDINGS.PREFABS.STATERPILLARGENERATOR.NAME`, and in
French Oilfloater's NAME and EGG_NAME have the same text. Eggs prefer
`CREATURES.SPECIES.*.EGG_NAME` over `ITEMS.FOOD.*`. Plants prefer keys outside `SEEDS` and
the segment equal to `UPPER(id)` (`SEATREE` vs `SEATREEBRANCH`). The lookup depends on the
game language; rerun it on each export.

## Building editor fields (for Task 6)

**Category / subcategory.** `building.json buildMenuCategories[]` gives
`{category (hash), categoryName, categoryIcon}` in menu order: base, oxygen, power, food,
plumbing, hvac, refining, medical, furniture, equipment, utilities, automation,
conveyance, rocketry, hep. `buildingAndSubcategoryDataPairs` maps
`categoryName → [{Key: prefabId, Value: subcategory}]`, in menu order. It covers 568 defs,
and no building is in two categories. The other 95 defs are 32 in `rocketModuleMenu`
(rocket modules, no subcategory), deprecated, debug, POI and prop defs, and 10 buildable
mod defs with no menu entry: Ronivan `Custom{SandStone,Granite,IgneousRock,Obsidian,Brick}Tile`,
`(Inverted)Small{Gas,Liquid}Reservoir`, and the Signs mod's `GeyserTag`. Subcategories seen:
`advanced`, `archaeology`, `automated`, `batteries`, `beds`, `buildmenuports`,
`conveyancestructures`, `cooking`, `decor`, `dining`, `distributors`, `doors`,
`electrobankbuildings`, `equipment`, `exploration`, `farming`, `fittings`, `generators`,
`hygiene`, `industrialstation`, `ladders`, `lights`, `logicaudio`, `logicgates`,
`logicmanager`, `manufacturing`, `materials`, `medical`, `meteordefense`, `missiles`, `oil`,
`operations`, `organic`, `pipes`, `powercontrol`, `printingpods`, `producers`, `pumps`,
`ranching`, `recreation`, `research`, `rocketnav`, `rocketstructures`, `sanitation`, `scrubbers`, `sensors`, `storage`,
`switches`, `telescopes`, `temperature`, `tiles`, `transmissions`, `transport`,
`uncategorized`, `valves`, `washroom`, `wellness`, `wires`, `workstations`, plus the mod
subcategory `SignsTagsRibbonsSubcategory`.

**`buildLocationRule`** (enum `BuildLocationRule`, decompiled from this game build;
count = defs in this export):

| int | name | count | int | name | count |
|---|---|---|---|---|---|
| 0 | Anywhere | 205 | 10 | WireBridge | 3 |
| 1 | OnFloor | 290 | 11 | HighWattBridgeTile | 2 |
| 2 | OnFloorOverSpace | 0 | 12 | BuildingAttachPoint | 6 |
| 3 | OnCeiling | 11 | 13 | OnFloorOrBuildingAttachPoint | 5 |
| 4 | OnWall | 2 | 14 | OnFoundationRotatable | 7 |
| 5 | InCorner | 2 | 15 | BelowRocketCeiling | 0 |
| 6 | Tile | 72 | 16 | OnRocketEnvelope | 8 |
| 7 | NotInTiles | 30 | 17 | WallFloor | 1 |
| 8 | Conduit | 10 | 18 | NoLiquidConduitAtOrigin | 1 |
| 9 | LogicBridge | 2 | 19 | **OnBackWall** | 6 (`Shelf`, `Underwater*Station` ×4, `FishingStation`) |

blueprintnotincluded's `build-location-rule.ts` calls 19 `Underwater` and flags the name as
unverified. The game's name is `OnBackWall`.

**`permittedRotations`**: `0 Unrotatable` (457), `1 R90` (23), `2 R360` (110),
`3 FlipH` (66), `4 FlipV` (7). The export also ships the enum itself in
`attribute.json PermittedRotations`. Task 6's assumed literals (1 = OnFloor, 3 = FlipH)
are correct.

## Mod strings (Ronivan, `translation_template.pot` / `translations/fr.po`)

Keys are `msgctxt` = `RonivansLegacy_ChemicalProcessing.STRINGS.…`. Normalizing from the
first `STRINGS.` gives 958 keys with no overlap with the game's pot. `fr.po` translates 811
of them; 7 keys in fr.po are stale (not in the pot). The root `translation_template.pot` is
identical to `translations/translation_template.pot`.

| entity | rule | result | exceptions |
|---|---|---|---|
| buildings (145) | `STRINGS.BUILDINGS.PREFABS.{UPPER(id)}.NAME` (+ `.DESC`, `.EFFECT`) | 144 NAME; DESC and EFFECT for all 144 | `AIO_BigNuclearReactor` (deprecated, no key, shows `MISSING.` in game). No FR NAME (English fallback): `Chemical_Liquid_Boiler`, `Chemical_ChlorineCompressor`, `Chemical_AIO_ChlorinePump`, `HighPressureGasConduitRadiant`, `HighPressureLiquidConduitRadiant`, `Chemical_SourGasSweetener`, `Biochemistry_SynthesizerPhytoOil` |
| elements (54) | `STRINGS.ELEMENTS.{UPPER(id)}.NAME` (+ `.DESC`) | 54/54, equals YAML `localizationID` | none |
| items (20 found in recipes) | key ending `.{UPPER(id)}.NAME` (group not fixed) | 11/20 | 9 `Mining_GuidanceDevice_{Aquifer,Cryosphere,dlc2,dlc4,dlc5,hard_stratum,mantle,oil_reserves,soft_stratum}`: compose `NAME_PROGRAMMED` with `STRINGS.UI.MINING_SMART_DRILL_LOCATIONS.{UPPER(suffix)}` |
| geysers (8) | `geyser.json nameStringKey` | 8/8 | none |
| critters / plants | — | Ronivan adds none | — |

## Differences (DB vs export), attributed

Attribution codes:
- **OLD** = bug or limit of the Cecil extractor, confirmed against decompiled game code where noted.
- **RON** = Ronivan changes a base entity.
- **MOD** = another (unsupported) mod.
- **GAME** = game content the old extractor never covered.

### Buildings
| difference | count | attribution |
|---|---|---|
| category | 75 | OLD: its own category map (e.g. `Apothecary` "Power", `GasReservoir` "Base") |
| dlc_id | 15 | OLD: 11 × `DLC5` instead of `DLC5_ID`; 4 null → `DLC4_ID`/`DLC5_ID` (`(Ceiling)FossilSculpture`, `LargeBackwallFarm`, `WideFarmTile`) |
| power_consumption `MetalRefinery` 1200 → 800 | 1 | RON (`MetalRefineryConfig_CreateBuildingDef_Patch`) |
| `AirConditioner` 240 → 340, `LiquidConditioner` 1200 → 1700 | 2 | MOD Adjustable Coolers or RON. Both patch these configs, and game code says 240/1200. A base entity changed by a mod, shown as is |
| `IceMachine` null → 240 | 1 | OLD (wattage from a local, not a literal) |
| `UnderwaterBreathingStation` 60 → null | 1 | OLD: the def sets `EnergyConsumptionWhenActive = 60` but no `RequiresPowerInput`, so there is no consumer at runtime |
| 317 new defs | — | 145 RON, 55 from 18 unsupported mods (skipped), 117 GAME (wires, logic, sculptures, rocket cluster parts… never extracted) |

### Elements
| difference | attribution |
|---|---|
| 70 more ids (54 Ronivan, 16 YAML-disabled) | RON adds 54; 7 of the 16 disabled are used by mod recipes (finding 5) |
| Brick `highTemp` 1683 → 2000, Electrum 1337 → 1295.86, Cement conductivity 8 → 3.11 | RON (`ElementLoader_CollectElementsFromYAML_Patch`) |
| runtime `oreTags` ⊃ YAML tags | RON (WallMachine/AIO tags) + game (state and category added at load) |

### Critters (111 matched morphs)
| difference | count | attribution |
|---|---|---|
| ids `OilFloater*` → `Oilfloater*`, `WormBaby` → `DivergentWormBaby`, `MosquitoLarva` → `MosquitoBaby` | 8 | OLD (ids from class names) |
| baby temps 0 → values | 52 | OLD (babies not read) |
| `calories_per_cycle` GoldBelly/IceBelly 200000 → 1777777.8 | 2 | OLD: `BellyTuning.STANDARD_CALORIES_PER_CYCLE = 4 × carrot kcal / carrot cycles` not evaluated |
| `calories_per_cycle` Raptor 0 → 800000 | 1 | OLD |
| `calories_per_cycle` Bee/Butterfly/Glom/Mosquito/SeaFairy 0 → none | 5 | same (no CaloriesDelta = 0) |
| `overcrowding_threshold` aquatic (Pacu×3, ParrotFish, PufferFish, PrehistoricPacu, SeaHorse, SeaTurtle, Snail×2, Squid) 1 → 8/12 | 11 | OLD (`FishOvercrowdingMonitor` tuning not resolved) |
| `overcrowding_threshold` DivergentWorm 12 → 16, SquirrelHug 12 → 4 | 2 | OLD (game: `PEN_SIZE_PER_CREATURE_WORM` = TIER4 = 16, `_HUG` = TIER1 = 4) |
| `overcrowding_threshold` Bee/Butterfly/Mosquito/Raptor/SeaFairy/Staterpillar×3 0 → value | 8 | OLD |
| `incubation_time` Pacu×3, ParrotFish, Snail 15 → 5; PrehistoricPacu, SeaTurtle, Squid 60 → 20; Mosquito 4.5 → 2 | 9 | OLD: read the fertility argument instead of incubation (`ExtendEntityToFertileCreature(…, fertilityCycles, incubationCycles, …)`, e.g. Pacu 15f, 5f) |
| Chameleon incubation 0 → 10, egg output missing | 1 | OLD |
| `incubation_time` Bee/Butterfly/Glom/Moo/DieselMoo/SeaFairy 0 → none | 6 | same (no egg) |
| diet `amount_per_cycle` 0 → value | 74 | OLD (calories or caloriesPerKg unresolved; Raptor prey, Squirrel plants, Stego fruits…) |
| diet `conversion_ratio` 0 → value | 24 | OLD |
| diet `produced_element_id` | 20 | OLD (e.g. HatchHard GoldAmalgam → Gold in DB; game `MetalDiet(Carbon)` → Carbon), and RON for the products below |
| diet rows only in export | 20 morphs | OLD for tag-built diets (Hatch/HatchVeggie foods, Pacu seeds, Staterpillar metals, Moo GasGrass/PlantFiber, GlassDeer/WoodDeer plants). RON adds `SolidSlag`/`SolidBiomass` to Crab×3 and HatchVeggie (product `AmmoniumSalt`), `ArgentiteOre`/`AurichalciteOre` to HatchMetal (→ `SolidSilver`) and HatchHard (→ `SolidBorax`), and `AIO_*` and Ronivan metals to Staterpillar×3 |
| diet row only in DB: Moo `Carbon` | 1 | OLD (read an unused constant `CONSUME_ELEMENT`) |
| dlc_id `DLC5` → `DLC5_ID` | 8 | OLD |
| new creatures `PacuAlgae`, `PacuBeta`, `PacuPlate` (+ babies, eggs) | 6 | MOD Pacu morphs (skip) |
| babies have diets in export, none in DB | — | OLD (babies skipped) |

### Plants (50 matched)
| difference | attribution |
|---|---|
| temps: DB = lethal pair (40) or wrong (10: Clam, CritterTrapPlant, DewPalm, FilterPlant, FlyTrapPlant, PlanktonCoral, SaltPlant, SwampHarvestPlant, ToePlant, TubeWorm) | OLD |
| pressure: DB = (lethal_low, warning_low) | OLD |
| growth_time 0, no inputs/outputs | OLD |
| ids `BasicFabricMaterialPlant`/`SeaTreeRoot`/`WaterCupsPlant` | OLD (class names) |
| `SuperWormPlant` new | GAME |
| dlc `DLC5` → `DLC5_ID` | OLD |

### Geysers
All 27 identical on every column. 8 new: RON.

### Recipes (118 json-cache rows: 62 match an export recipe exactly, 56 hold `Unknown` placeholders)
| difference | count | attribution |
|---|---|---|
| duration (e.g. cooking 0 → 50, ChemicalRefinery SaltWater 80 → 40, AdvancedCraftingTable 4 → 60) | 34 | OLD (time of the neighbouring recipe or a tuning multiplier; game `ChemicalRefineryConfig`: SaltWater `time = 40f`) |
| input amounts (e.g. SuperCoolant 100/100/100 → 1/49.5/49.5) | 23 | OLD |
| output amounts | 12 | OLD |
| 56 rows with `Unknown` ids | 56 | OLD |
| 333 new recipes | — | 221 on Ronivan fabricators (RON); the rest are base recipes never extracted (GAME) or added by RON to base fabricators (not separable without the extension) |
| MetalRefinery ore recipes output `SolidSlag` | 15 | RON changes base recipes (and adds the Electrum/Argentite ones) |

### Duplicants / rockets
See the field tables. All differences are OLD, except BiodieselEngineCluster fuel (RON) and
the absent base-game-only rocket parts (export DLC scope).

## Mod extensions required

These are the inputs of Task 3 (`planner_extras.json`). Member paths are checked against
the decompiled `Assembly-CSharp` of this build.

| # | key in `planner_extras.json` | runtime source | why |
|---|---|---|---|
| 1 | `buildings.<id>.constructionTime` | `BuildingDef.ConstructionTime` | `buildings.construction_time` |
| 2 | `buildings.<id>.selfHeatKilowattsWhenActive` | `BuildingDef.SelfHeatKilowattsWhenActive` | `buildings.heat_generation` |
| 3 | `buildings.<id>.exhaustKilowattsWhenActive` | `BuildingDef.ExhaustKilowattsWhenActive` | heat (new) |
| 4 | `buildings.<id>.generatorWattageRating` | `BuildingDef.GeneratorWattageRating` | `buildings.power_generation`. **Not in the brief's list.** Iterate `Assets.BuildingDefs` |
| 5 | `entities.<id>.speciesTag` | `CreatureBrain.species.Name` (component on the prefab) | `critters.species_tag`, `variant_family` |
| 6 | `entities.<id>.kanim` | `KBatchedAnimController.AnimFiles[0].name` | plant kanim for the asset worklist (required); critters optional |
| 7 | `entities.<id>.mod` / `modTitle` | assembly of the `IEntityConfig` / `IMultiEntityConfig` passed to `EntityConfigManager.RegisterEntity` / `RegisterEntities` (Harmony postfix), mapped through `Global.Instance.modManager.mods[].loaded_mod_data.dlls`, the same way as `building/ModSourceTracker.cs` | supported-mods filter and badge for critters, eggs, seeds, items, foods (Pacu morphs must be skipped; Ronivan items badged) |
| 8 | `recipes.<id>.mod` / `modTitle` (or a `mod` field per recipe in a list, since `id` is not unique) | in the `ComplexRecipe` constructor, walk `new StackTrace()`. The first frame whose declaring assembly is not the game's (`Assembly-CSharp*`), Harmony, or the BCL identifies the mod; map it through `loaded_mod_data.dlls`. Base recipes changed by a transpiler or postfix keep the game frame, so they stay base | badge and remove recipes Ronivan *adds* to base fabricators. The fabricator is not enough (finding 4) |
| 9 | `elements.<id>.disabled` | `ElementLoader.elements[i].disabled` (`Element.disabled`) | Ronivan re-enables 7 YAML-disabled elements (finding 5) |

Recommended in the same loop as #9 (cheap, closes the YAML staleness for elements Ronivan
patches; Task 3 decides): `elements.<id>` runtime `toxicity`, `flow`,
`solidSurfaceAreaMultiplier`, `liquidSurfaceAreaMultiplier`, `gasSurfaceAreaMultiplier`,
`lightAbsorptionFactor`, `radiationAbsorptionFactor`, `radiationPer1000Mass`,
`defaultValues.pressure`, `dlcId`. These are all fields of `Element`. With them, the YAML
is only needed for `localizationID`.

### Not needed (derivable from the current export or mod files)

- Building DLC: `kPrefabID.requiredDlcIds`.
- Mod English title: `mods/Steam/<id>/mod.yaml` `title` (the export's `modTitle` is localized).
- Critter lifespan and calories: `db.json traits[{adult}BaseTrait]`.
- Egg incubation: `items.json eggs[].incubatorMonitorDef`.
- Duplicant base stats: `MinionBaseTrait` + `entities[Minion]`.
- Entity name keys: `po_string.json` reverse lookup.
- Geyser mod: name key in the mod pot.
- Building category/subcategory: `buildingAndSubcategoryDataPairs`.

### Optional (not a regression, skip unless cheap)

- Critter `kanim` (covered by #6 if every prefab is exported).
- Molt rate: no data path, a delegate.
- Explicit entity name key: robustness against language-dependent ties.
