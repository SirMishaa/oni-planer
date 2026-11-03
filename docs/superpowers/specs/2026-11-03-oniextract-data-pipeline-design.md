# OniExtract Data Pipeline — Design

**Date:** 2026-11-03
**Status:** Approved (design); pending implementation plan

## Why

The site is heading towards a blueprint editor (place buildings tile by tile, plumbing /
power / automation overlays, copy-paste, later driven by Claude over MCP and loaded from the
user's save). An editor needs things our current pipeline cannot give reliably:

- every buildable building, including automation (logic gates, sensors), batteries,
  high-wattage wires — the Cecil IL extractor misses **91 buildable base-game buildings**
  (349 imported vs 358 buildable base + 200 modded in the game);
- port positions (liquid/gas/power/logic/conveyor input-output offsets), build location
  rules, permitted rotations, footprint, overlay — mostly computed at runtime, so IL
  heuristics are fragile or impossible;
- world-scale building sprites and the 16 connection-state sprites of every tile, pipe,
  wire and rail;
- the user's modded buildings — notably **Ronivan's Legacy – Industrial Revolution**
  (145 buildings), which the user considers important.

The in-game export mod **OniExtract2024** (the fork maintained by blueprintnotincluded,
which powers blueprintnotincluded.org) reads all of this from the running game. The
user forked it: `git@github.com:SirMishaa/OniExtract2024.git`, cloned at
`~/dev/OniExtract2024` (`origin` = user fork, `bni` = upstream
`blueprintnotincluded/OniExtract2024`). A first export was run on 2026-11-03 against
game build U59-744825 and verified (see "Verified facts").

This is step 1 of a larger roadmap:

1. **This document** — the mod export becomes the game-data source.
2. UI redesign (Impeccable) on top of the complete data — one or two sections, including
   the shell and the blueprint section's design; no unimplemented features.
3. The blueprint system, then MCP tools for Claude.
4. Save import (separate project).
5. Sharing blueprints (personal library first, shareable later).

## Goals

- `game:import` reads the mod export as its primary source. One source per kind of data,
  no duplicated extraction.
- Buildings come entirely from the export: all buildable buildings (base + DLC + mods),
  with footprint, ports, build rules, rotations, overlay, build-menu category and
  subcategory, power, materials.
- Modded buildings appear **everywhere** on the site (building pages, search, recipes…)
  with a **mod badge** naming the mod.
- Editor assets are imported: world-scale building renders, connection sprites with their
  declared cell geometry (`connection_sprite_cells.json`), placement rects
  (`uiImageRect`), utility indicators.
- Every other domain (critters, plants, geysers, recipes, elements, duplicants, rockets)
  moves to the export **wherever the export provides the fields we display**. The user's
  rule: "if the mod does it, I prefer the mod".
- Gaps in the export are closed by **extending the user's fork of the mod** (small C#
  additions reading already-computed runtime objects), not by keeping IL heuristics.
- The C# Cecil extractor shrinks to what neither the mod nor game files can provide — ideally
  nothing. It must not stay as a parallel source for data the mod already exports.
- The extra runtime data that the blueprint step will need is imported now: room types and
  their constraints (`db.json` `roomTypes`, building `roomConstraintTags`).

## Non-goals

- No blueprint editor, no new pages, no UI redesign. Existing pages keep working on the new
  data; the only UI addition is the mod badge.
- No save parsing.
- Translations pipeline is unchanged for base-game strings (`strings_template.pot` + the FR
  `.po`): game strings stay keyed by `STRINGS.*` keys and served through Laravel
  translations.
- Codex stays read from the game's YAML files (it is file data, not runtime data).
- Critter/plant animated sprites stay on the Python kanim pipeline (`scripts/kanim`). The
  mod exports static images only.

## Anti-goals (a failure even if it "works")

- Two pipelines feeding the same table, or the C# extractor silently remaining the real
  source for a domain.
- Losing data the site shows today (lifespans, diets, egg morph chances, family names, etc.)
  because the export lacks a field.
- Regressing critter animations or re-rendering kanims (30-40 min job, and the user does not
  want animations dropped).
- Committing game art or the export to git (`game-data/`, `public/assets/game/` stay
  gitignored).
- A fork that drifts so far from upstream that a later PR to blueprintnotincluded is
  impossible: keep additions additive and isolated.

## Constraints

- The export is produced **manually by the user on Windows**: launch ONI with the mod
  enabled, load any colony, pause screen (Esc) → *Export Game Data*, *Export Building
  Images*, *Export Connection Sprites*, quit without saving. Claude cannot run the game from
  WSL. The game must be fully restarted after a mod rebuild.
- Export location: `C:\Users\misha\Documents\Klei\OxygenNotIncluded\export\`
  (`/mnt/c/Users/misha/Documents/Klei/OxygenNotIncluded/export`).
- Mod build on WSL: `dotnet` 10 SDK, target `net48` via
  `Microsoft.NETFramework.ReferenceAssemblies`, referencing game DLLs in
  `/mnt/d/SteamLibrary/steamapps/common/OxygenNotIncluded/OxygenNotIncluded_Data/Managed`,
  compiling `OniExtract2024/**/*.cs` + `OniExtract2024.Core/**/*.cs`. A working SDK-style
  wrapper exists only in the session scratchpad today; the fork needs a committed,
  WSL-buildable project (see Decisions). Install = copy `OniExtract2024.dll`, `PLib.dll`,
  `mod.yaml`, `mod_info.yaml` into `Documents\Klei\OxygenNotIncluded\mods\dev\OniExtract2024_dev\`.
- Mod license is GPL-2.0: fine as a local tool, its output is not GPL, the site is not a
  derivative. Our fork stays public GPL.
- The export runs in whatever language the game is set to (French for this user):
  `nameString` fields are localized and some text arrives with broken encoding
  (`astéroà¯des`). Display names must come from string **keys**, not from `nameString`.
- The export reflects the user's active DLCs and mods. Base-game-only buildings
  (`Telescope`, `MissionControl`, `CosmicResearchCenter`) are absent from a Spaced Out
  export; acceptable for now (the user plays Spaced Out + all DLCs).
- Laravel conventions of this repo apply (importers in `app/Services/GameImport`, models
  flagged `#[GameData]` are truncated by `game:import`, Pest tests, Pint).

## Verified facts (export of 2026-11-03, build U59-744825)

- `database/`: 13 JSON files — `building.json` (663 defs, 555 buildable), `entities.json`
  (511), `db.json` (rooms, techs, skills, traits, effects, attributes, amounts, plant
  mutations, personalities…), `elements.json`, `geyser.json` (35), `recipe.json` (451),
  `food.json`, `items.json`, `multiEntities.json`, `tags.json`, `attribute.json`,
  `uiSpriteInfo.json`, `po_string.json`.
- `building.json` root also has `mods` (19 mods with their building ids), `utilityIndicators`,
  `buildMenuCategories`, `buildingAndSubcategoryDataPairs`, `rocketModuleMenu`,
  `objectLayerNames`, `roomConstraintTags`, `requiredSkillPerkMap`.
- Per building: size, `materialCategory`/`materialMass`, `isFoundation`, `isKAnimTile`,
  `isUtility`, `dragBuild`, `deprecated`, `debugOnly`, `showInBuildMenu`,
  `buildLocationRule`, `permittedRotations`, `sceneLayer`, `objectLayer`, `replaceable`,
  `viewMode`, `energyConsumer.baseWattageRating`, `energyGenerator`, `conduitConsumer`,
  `conduitDispenser`, `utilities` (e.g. Electrolyzer: LiquidInput (0,0), PowerInput (1,0),
  LogicInput (1,1)), `elementConverters`, `storage`, `battery`, `uiImageRect`, kanim name.
- Images: `ui_image/` 1642 PNGs (627 buildings re-rendered at 200 px/cell, 0 clipped,
  0 aspect mismatches), `connection_sprites/` 53 connectables × 16 states, plus
  `connection_sprite_cells.json`, `ui_image_rects.json`, `building_image_report.json`.
- Render colours are pixel-identical to blueprintnotincluded's art (pause screen has no
  effect). The upstream cnctemaR `main` build had wrong tints; it is obsolete (its export is
  archived in `game-data/oniextract/main-aug/` and can be deleted).
- Critters (Hatch): full diet (consumed tags + calories, `minConsumedCaloriesBeforePooping`),
  temperatures (`critterTemperatureMonitorDef`), egg morph weights (`fertilityMonitorDef`),
  `overcrowdingMonitorDef.spaceRequiredPerCreature`. Plants (PrickleFlower): growth,
  temperature/pressure/atmosphere/light, irrigation/fertilization, crop and seed.

### Known gaps found so far (not exhaustive — the audit below completes the list)

- **Critter lifespan**: not on `ageMonitorDef`. It is an `Age.maxAttribute` modifier; likely
  reachable through the critter's base trait in `db.json` `traits` (to verify), otherwise
  export it from the mod.
- **Egg entities** (e.g. `HatchEgg`, incubation time) not found in `entities.json` — check
  `items.json` / `multiEntities.json`, otherwise export.
- **Building construction time** and **self-heat / exhaust heat** not present on building
  defs.
- **Mod strings**: modded names are not in `strings_template.pot` and not keyed in
  `po_string.json` (searched `MINERALDRILL`: no hit). The export must provide, per
  string key, the mod's English text and, when the mod ships one, the translation for the
  game's active language — correctly encoded UTF-8.

## Decisions (user)

- Use the in-game mod as the data source; keep C# only where truly needed and cheap to
  maintain.
- Gaps are closed by **extending the mod** in the user's fork; upstream PRs maybe later.
- Modded buildings everywhere on the site, with a mod badge.
- Mod string fallback: the mod's translation for the current locale if it exists, else
  English. Base-game strings keep using `.pot` + FR `.po`.
- Stop after this step; UI redesign comes before the blueprint system.
- **Values as in the user's game, mods included.** The user plays with these mods long
  term (mostly QoL, a few large additions that stay in-universe). One export, no vanilla
  run, no diff. Consequence for the audit: differences against the C# extractor caused by
  mods are expected, not regressions — the audit must attribute them (mod-added entity, or
  base entity changed by a mod) rather than flag them.
- **All mod content, not only buildings, appears everywhere with a mod badge**: elements
  (~54 from Ronivan), recipes (~221 made in mod buildings), items, foods, critters/plants if
  any. Every game-data table carries a nullable mod reference. Elements therefore move off
  the base YAML onto the export in this step (the YAML lacks mod elements).
- **Supported-mods list.** A config file lists the mods whose content reaches the site
  (Ronivan's Legacy – Industrial Revolution included). Content from any other mod present
  in the export is skipped, and the import report lists those mods (id, title, entity
  count) so the user can add them. Removing a mod from the list removes its content on the
  next import (imports are destructive). Base-game content is always imported.

## Calls made by Claude (user to check)

- Field audit is completed on the current export **before** any mod C# work; all mod
  extensions are then batched so the user re-runs the export once (twice at most).
- Mod titles are exported in English by a mod extension (the export currently gives the
  localized title, e.g. "L'héritage de Ronivan – Révolution industrielle"); the badge uses
  the English title.
- Per-building DLC id is missing from building defs (`requiredDlcIds` null on
  `kPrefabID`); recovering it is part of the audit/extension work, since the site shows
  `dlc_id`.
- When a mod changes a base-game entity (stats, recipe), the site shows the modded value
  without a badge on the base entity; only mod-added entities get the badge. Tracking which
  base values a mod altered is out of scope.

## The work

### 1. Field audit (first task, drives everything else)

For every field the site imports today (json-cache schemas: `buildings`, `critters` incl.
diets/outputs/species tag, `plants` incl. inputs/outputs, `geyser_types`, `recipes`,
`duplicant_traits`, `rocket_components`, element YAML properties, prefab names), record
where it lives in the export, or "missing". Output: a table in this spec's companion plan.
Every "missing" becomes a mod extension task. The audit also compares values on a sample
(e.g. all critters' temps/diets, all recipes) between the current DB and the export and
explains any difference — the C# extractor is the regression oracle until it is removed.

### 2. Mod extensions (user fork)

- A WSL-buildable project committed in the fork (SDK-style, `net48`, paths overridable via
  the existing `Directory.Build.user.props` mechanism), plus an install step that copies
  the four files to the dev mods folder.
- New export fields for each audit gap (expected: lifespan, egg/incubation, construction
  time, heat, mod string table), placed in new isolated files/classes so upstream merges
  stay easy.
- Mod string table: for every mod-added entity (buildings, elements, items, foods,
  critters, plants, recipes), its `STRINGS.*` key(s), English text, and active-locale text
  when it differs. Plus each mod's English title.

### 3. Import side (Laravel)

- Sync step: copy the export from the Windows path into `game-data/oniextract/current/`
  (gitignored), like game files are synced today. `game:import` gets an option for the
  export path, defaulting to that folder.
- New/rewritten importers reading the export. Buildings first (they unblock the editor),
  then the other domains as the audit allows.
- Schema additions for buildings: ports (type, offset, secondary), build location rule,
  permitted rotations, object/scene layer, overlay (`viewMode`), foundation/tile/utility
  flags, drag-build, build-menu category + subcategory, material categories + masses,
  placement rect, mod id. A mods table (Steam workshop id, English title) referenced by
  every mod-added entity in every game-data table; a supported-mods config filters which
  mods are imported.
- Room types and room constraint tags imported for the blueprint step.
- Mod strings merged into the generated `lang/game/{en,fr}.json` catalogs with the
  fallback rule above.
- `game:import` stays destructive and idempotent; the existing `GameDataTables` truncation
  mechanism applies to new tables.

### 4. Assets

- World-scale building renders (`ui_image/` building PNGs) converted to WebP into
  `public/assets/game/buildings/`, replacing today's 128 px menu icons as the single building
  image. Thumbnails for lists if needed. Non-building `ui_image/` icons (items, elements)
  may replace current ones where better — builder's call per category.
- Connection sprites into `public/assets/game/connections/<prefabId>/<0-15>.webp`, with the
  cell geometry stored in the DB (or a JSON manifest).
- The Python building-icon scripts (`scripts/extract-building-assets.py`,
  `building_kanim_map.txt`) are retired. The critter/plant kanim pipeline stays; its
  worklist must keep getting kanim names (today from json-cache): source them from the
  export once critters/plants move.

### 5. Retire

- Remove every C# extractor whose domain moved, and its json-cache output. If at the end
  nothing is left, remove `dotnet-extractor/` and the `--skip-extractor` path entirely.
- Update the project memory note and any docs that describe the old pipeline.

## Mod badge (only UI change)

A small badge on building cards/rows/detail pages (and any other entity coming from a mod)
naming the mod (`mods[].title`, English). Styling follows the current UI; the redesign comes
later.

## Left to the builder

- Exact table/column names and whether ports live in a JSON column or a child table.
- WebP quality/size, thumbnails, directory layout under `public/assets/game/`.
- Order of domain migrations after buildings, guided by the audit.
- How the sync step is exposed (artisan command vs option on `game:import`).
- Test strategy: fixture slices of the real export in `tests/Fixtures`, per importer.
