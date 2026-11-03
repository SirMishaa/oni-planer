# OniExtract Data Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the in-game OniExtract2024 mod export (plus supported mods' own files) the single source of game data and building/connection sprites, with mod content badged everywhere, and retire the Cecil IL extractor.

**Architecture:** A sync command copies the Windows export and the supported mods' files into `game-data/`. Per-domain *readers* (`app/Services/GameImport/OniExport/*Reader.php`) turn export JSON into plain record arrays; the existing importers gain an `importRecords(array)` entry point and persist them (new columns where the editor needs them). Mod strings come from each mod's own `translation_template.pot` / `translations/<locale>.po`. A small additive extension in the user's mod fork exports the few fields the export lacks.

**Tech Stack:** Laravel 13 / PHP 8.5, Pest, SQLite, spatie/laravel-translatable, spatie/laravel-data, Inertia v3 + Vue 3 (TS), GD (WebP). Mod: C# net48, Harmony, dotnet 10 SDK on WSL.

**Spec:** `docs/superpowers/specs/2026-11-03-oniextract-data-pipeline-design.md`

## Global Constraints

- Mod fork: `~/dev/OniExtract2024` (`origin` = `git@github.com:SirMishaa/OniExtract2024.git`, `bni` = upstream). Additions stay additive and isolated (new files/classes; one call added in `ExportGameData.cs`).
- Windows export: `/mnt/c/Users/misha/Documents/Klei/OxygenNotIncluded/export`. Windows mods: `/mnt/c/Users/misha/Documents/Klei/OxygenNotIncluded/mods/Steam/<workshopId>`.
- Local copies (gitignored, under existing `/game-data/` rule): `game-data/oniextract/current/`, `game-data/mods/<workshopId>/`.
- Supported mods start as exactly `['3557584850']` (Ronivan's Legacy – Industrial Revolution).
- Values are as in the user's modded game. Mod-caused differences vs the old C# output are expected, not regressions.
- Display names always come from `STRINGS.*` keys, never from export `nameString` (localized, sometimes mis-encoded).
- Mod string keys are normalized by dropping everything before `STRINGS.` (`RonivansLegacy_ChemicalProcessing.STRINGS.BUILDINGS.PREFABS.MINING_MINERALDRILL.NAME` → `STRINGS.BUILDINGS.PREFABS.MINING_MINERALDRILL.NAME`).
- Locale fallback for mod strings: mod `<locale>.po` translation if present, else the mod's English.
- `game:import` stays destructive; it must validate inputs **before** truncating.
- Never commit game art/export; `public/assets/game/` and `game-data/` stay gitignored.
- Do not touch the kanim render pipeline outputs (`public/assets/game/critters|plants`); no re-render.
- Code conventions: `declare(strict_types=1)`, `final` classes, typed signatures, PHPDoc array shapes, `<!-- region -->` markers in Vue templates, `lang="ts"` on every SFC, Wayfinder for URLs. Run `vendor/bin/pint --dirty --format agent` before each PHP commit.
- Package manager: `bun`/`bunx` only.
- Commits: conventional messages, **no** `Co-Authored-By`, no GPG signing (`git -c commit.gpgsign=false commit …`).

## Review Focus

1. **Unsupported mod content referenced by supported/base content** (a base recipe using an element from a mod not in the list, a Hatch diet item from a skipped mod): the reference is skipped and counted in the import report — never a crash or FK error. Test in Task 9 (recipes) and Task 10 (critters).
2. **Export missing or partial** (no `database/`, or building images never run): `game:import` aborts with a clear message **before** truncating any table. Test in Task 14.
3. **Mod string without translation for the locale**: French catalog falls back to the mod's English; never stores export `nameString`. Test in Task 5.
4. **A mod removed from the supported list**: its content disappears on next import while base content and other mods are untouched; the report lists skipped mods with entity counts. Test in Task 4.
5. **Export from a different game build than the synced game strings** (`buildVersion` mismatch between export and `game-data`): import warns loudly but proceeds. Test in Task 14.

---

## Phase 0 — Mod fork

### Task 1: WSL-buildable mod project + install script

**Files:**
- Create: `~/dev/OniExtract2024/tools/wsl/OniExtract2024.Wsl.csproj`
- Create: `~/dev/OniExtract2024/tools/wsl/build-and-install.sh`
- Modify: `~/dev/OniExtract2024/README.md` (short "Build on Linux/WSL" section)

**Interfaces:**
- Produces: `tools/wsl/build-and-install.sh [--no-install]` → builds `bin/Release/net48/OniExtract2024.dll` and copies `OniExtract2024.dll`, `PLib.dll`, `OniExtract2024/mod.yaml`, `OniExtract2024/mod_info.yaml` into `$ONI_MODS_DEV/OniExtract2024_dev/` (default `/mnt/c/Users/$USER_WIN/Documents/Klei/OxygenNotIncluded/mods/dev`, overridable by env).

- [ ] **Step 1:** Create the SDK-style csproj: `TargetFramework net48`, `LangVersion latest`, `GenerateAssemblyInfo false`, `EnableDefaultCompileItems false`, `PackageReference Microsoft.NETFramework.ReferenceAssemblies 1.0.3 (PrivateAssets all)`, `Compile` = `../../OniExtract2024/**/*.cs` and `../../OniExtract2024.Core/**/*.cs` (excluding `obj/**`, `bin/**`), references (Private=false) to `$(GameLibsFolder)` `0Harmony.dll`, `Assembly-CSharp.dll`, `Assembly-CSharp-firstpass.dll`, `Newtonsoft.Json.dll`, `UnityEngine*.dll`, `Unity.TextMeshPro.dll`, and `../../packages/PLib.4.15.0/lib/net471/PLib.dll`. `GameLibsFolder` default `/mnt/d/SteamLibrary/steamapps/common/OxygenNotIncluded/OxygenNotIncluded_Data/Managed`, overridable via `-p:` / env. (A working copy of this file exists at `/tmp/claude-1000/-home-mishaa-dev-ony-planner/7bd695d4-5ff4-44af-821f-5ad18fc7caa6/scratchpad/oniextract-build/OniExtract2024.csproj` while that session lives.)
- [ ] **Step 2:** Write `build-and-install.sh` (`set -euo pipefail`, `dotnet build -c Release`, copy the four files, print the destination).
- [ ] **Step 3:** Run `tools/wsl/build-and-install.sh` → expect `Build succeeded.` and the four files listed in the dev mods folder.
- [ ] **Step 4:** Commit in the fork: `build: add WSL build and install script`.

### Task 2: Field audit (decides the mod extension list)

**Files:**
- Create: `docs/superpowers/specs/2026-11-03-oniextract-field-audit.md` (ony-planner repo)

**Interfaces:**
- Consumes: current DB, `json-cache/*.json`, `game-data/oniextract/current` (copy the Windows export there by hand for now; Task 3 automates it), mod folder `mods/Steam/3557584850`.
- Produces: for every field written by the current importers (`BuildingImporter`, `ElementImporter`, `RecipeImporter`, `CritterImporter`, `PlantImporter`, `GeyserImporter`, `DuplicantImporter`, `RocketImporter`, `PrefabNameImporter`), a row: `table.column | export source path (or game/mod file) | formula | status (ok / missing / differs)`. A "Differences" section explaining each value difference on the full critter, plant, geyser, recipe sets (attributed to a mod, a game change, or an old-extractor bug). A final "Mod extensions required" list — **this list is the input of Task 3 (Phase 0 part)**.

Known mappings to confirm (from the spec session):
- critter `calories_per_cycle` = `-SelfModifiers[AttributeId=CaloriesDelta].Value × 600` of `db.json traits[Id={critterId}BaseTrait]`; `lifespan` = `AgeMax` modifier of the same trait; `incubation_time` = `100 / (items.json eggs[].incubatorMonitorDef.baseIncubationRate × 600)`; egg output `amount_per_cycle` = `1 / fertilityMonitorDef.baseFertileCycles`, morph chances from `initialBreedingWeights`; diet `amount_per_cycle` = `calories_per_cycle / infos[].caloriesPerKg`, `produced_element_id` = `infos[].producedElement.Name`, `conversion_ratio` = `producedConversionRate`; `min_temp`/`max_temp` = `critterTemperatureMonitorDef.temperatureColdUncomfortable/HotUncomfortable`; `overcrowding_threshold` = `overcrowdingMonitorDef.spaceRequiredPerCreature`; `is_base` = tag `OriginalCreature`; baby → adult via `babyMonitorDef.adultPrefab`. Scale/molt outputs: replicate the formulas in `dotnet-extractor/Extractors/CritterExtractor.cs` from `scaleGrowthMonitorDef` / `moltDropperMonitorDef`.
- plant: `growth_time` = `cropVal.cropDuration / 600`, temps = `temperatureVulnerable.temperatureWarningLow/High`, pressure = `pressureVulnerable`, atmosphere = `safe_atmospheres`, light = `illuminationVulnerable`, inputs = `irrigationDef/fertilizationDef.consumedElements[].massConsumptionRate × 600`, output = `cropVal`.
- geyser: `geyser.json` `geyserType` fields; name key `nameStringKey.String`; kanim `anim`.
- elements: game `StreamingAssets/elements/*.yaml` **plus each supported mod's `elements/*.yaml`** (same schema). This deviates from the spec's "elements move onto the export": the YAML carries fields `elements.json` lacks (toxicity, flow, pressure, surface multipliers, absorption, radiation, `localizationID`, `dlcId`), and mods ship YAML too. The audit confirms every `elements.json` id is covered by game + supported-mod YAML (ids from unsupported mods are expected to be missing).
- Expected missing → extensions: building `constructionTime`, `selfHeatKilowattsWhenActive`, `exhaustKilowattsWhenActive`; entity species tag (`CreatureBrain.species`); entity kanim (first `KBatchedAnimController.AnimFiles` name) for plants; duplicant base stats if absent from `db.json`.

- [ ] **Step 1:** Write a throwaway script in the session scratchpad (not the repo) that loads both sides and prints the rows/diffs.
- [ ] **Step 2:** Write the audit document from its output.
- [ ] **Step 3:** Commit: `docs(pipeline): field audit of the OniExtract export`.

### Task 3: Mod extension — `planner_extras.json`

**Files:**
- Create: `~/dev/OniExtract2024/OniExtract2024/planner/ExportPlannerExtras.cs`
- Modify: `~/dev/OniExtract2024/OniExtract2024/ExportGameData.cs` (one added block calling it, same pattern as `ExportGeyser`)

**Interfaces:**
- Produces `export/database/planner_extras.json`:
  ```json
  {
    "buildings": { "<prefabId>": { "constructionTime": 30.0, "selfHeatKilowattsWhenActive": 0.5, "exhaustKilowattsWhenActive": 0.25 } },
    "entities":  { "<prefabId>": { "speciesTag": "HatchSpecies", "kanim": "hatch_kanim" } },
    "buildVersion": "…", "dlcs": [ … ]
  }
  ```
  plus any field the audit's "Mod extensions required" list adds, each under the owning `buildings`/`entities` map or a new top-level key. Class `ExportPlannerExtras : BaseExport` with `ExportFileName = "planner_extras"`.
- Consumed by Tasks 6, 10, 11.

- [ ] **Step 1:** Implement: iterate `Assets.BuildingDefs` (`def.ConstructionTime`, `def.SelfHeatKilowattsWhenActive`, `def.ExhaustKilowattsWhenActive`) and `Assets.Prefabs` (`CreatureBrain.species.Name` when present; `KBatchedAnimController.AnimFiles[0].name` when present). Null-safe; skip prefabs without either.
- [ ] **Step 2:** Run `tools/wsl/build-and-install.sh` → `Build succeeded.`
- [ ] **Step 3:** Commit in the fork: `feat: export planner extras (construction time, heat, species, kanim)`; `git push origin master`.
- [ ] **Step 4 (manual — user):** Restart ONI, load a colony, Esc → *Export Game Data*, *Export Building Images*, *Export Connection Sprites*, quit without saving. Confirm `export/database/planner_extras.json` exists and `Hatch` has `speciesTag`. **Stop and wait for the user here.**

---

## Phase 1 — Laravel plumbing

### Task 4: Config, sync command, mods table, `ModCatalog`

**Files:**
- Create: `config/game.php`
- Create: `app/Console/Commands/GameSyncExportCommand.php`
- Create: `app/Models/Mod.php`, `database/factories/ModFactory.php`, migration `create_mods_table`
- Create: migration `add_mod_id_to_game_data_tables` (nullable FK `mod_id` → `mods.id`, `nullOnDelete`) on `buildings`, `elements`, `recipes`, `critters`, `critter_morphs`, `plants`, `plant_variants`, `geyser_types`
- Create: `app/Services/GameImport/OniExport/OniExport.php`
- Create: `app/Services/GameImport/OniExport/ModCatalog.php`
- Create: `tests/Fixtures/OniExport/` (export slice + `mods/3557584850/` slice: `mod.yaml`, `translation_template.pot`, `translations/fr.po`, `elements/ChemicalProcessing_IO_Elements.yaml` trimmed to 2 elements)
- Test: `tests/Feature/GameImport/OniExport/ModCatalogTest.php`, `tests/Feature/GameImport/GameSyncExportCommandTest.php`

**Interfaces:**
- `config('game.export_source')` (env `ONI_EXPORT_SOURCE`, default Windows export path), `config('game.mods_source')` (env `ONI_MODS_SOURCE`, default Windows `mods/Steam`), `config('game.export_path')` = `base_path('game-data/oniextract/current')`, `config('game.mods_path')` = `base_path('game-data/mods')`, `config('game.supported_mods')` = `['3557584850']`.
- `php artisan game:sync-export` — mirrors `export_source` into `export_path` (delete stale files) and, for each supported mod, copies `mod.yaml`, `translation_template.pot`, `translations/*.po`, `elements/*.yaml` into `mods_path/<id>/`. Fails (non-zero) if `export_source/database/building.json` is missing.
- `final readonly class OniExport { public function __construct(public string $path) {} public function json(string $file): array; public function buildVersion(): string; public function hasBuildingImages(): bool; }` — `json('building.json')` reads `database/<file>`; throws `OniExportMissing` (new exception class in the same namespace) when absent.
- `final class ModCatalog { public static function fromExport(OniExport $export, string $modsPath, list<string> $supported): self; public function modIdFor(string $prefabId): ?int /* mods.id or null for base */; public function isSkipped(string $prefabId): bool /* belongs to an unsupported mod */; public function persist(): void /* upserts Mod rows: workshop_id, static_id, title (English from mod.yaml) */; public function skippedReport(): array /* list<array{workshop_id: string, title: string, entities: int}> */; public function potPaths(): list<string>; public function poPaths(string $locale): list<string>; public function elementYamlPaths(): list<string>; }`. Prefab ownership comes from `building.json mods[].buildings`; for non-building prefabs (elements, items, entities, recipes) ownership = prefab id appears in a supported mod's `elements/*.yaml` or its `translation_template.pot` key set (`STRINGS.*.<UPPER(id)>.NAME`) — the audit confirms this rule; anything owned by an unsupported mod listed in `building.json mods` is skipped.
- `Mod` model `#[GameData]`, columns: `id`, `workshop_id` (unique string), `static_id`, `title`, timestamps.

- [ ] **Step 1: Failing tests** — `ModCatalogTest`: `it('maps a supported mod building to its mod')` (`Mining_MineralDrill` → mod with title `Ronivans Legacy - Industrial Revolution`); `it('treats base prefabs as base')` (`Electrolyzer` → `null`); `it('skips prefabs of unsupported mods and reports them')` (fixture includes `Stairs` from mod `2012810337` not supported → `isSkipped` true, `skippedReport()` contains `['workshop_id' => '2012810337', 'title' => 'Stairs', 'entities' => …]`); `it('drops content of a mod removed from the supported list')` (supported `[]` → Ronivan building skipped, `Electrolyzer` still base). `GameSyncExportCommandTest`: `it('mirrors the export and supported mod files')` using temp dirs via config overrides; `it('fails when the export has no building.json')`.
- [ ] **Step 2:** Run `php artisan test --compact tests/Feature/GameImport/OniExport/ModCatalogTest.php tests/Feature/GameImport/GameSyncExportCommandTest.php` → FAIL (classes missing).
- [ ] **Step 3:** Implement config, migrations (`php artisan make:migration … --no-interaction`), model + factory, `OniExport`, `OniExportMissing`, `ModCatalog`, command.
- [ ] **Step 4:** Re-run the tests → PASS. Run `php artisan migrate`.
- [ ] **Step 5:** Commit: `feat(import): sync OniExtract export and supported mods`.

### Task 5: Mod strings in resolver and translation catalogs

**Files:**
- Modify: `app/Services/GameImport/StringResolver.php` (constructor `__construct(string $potFilePath, list<string> $modPotPaths = [])`)
- Modify: `app/Services/GameImport/GameTranslationWriter.php` (`write(string $potPath, string $translationsDir, string $outputDir, list<string> $modPotPaths = [], array $modPoPathsByLocale = [])`, `$modPoPathsByLocale` is `array<string, list<string>>`)
- Create: `app/Services/GameImport/ModStringKey.php` (`final class ModStringKey { public static function normalize(string $key): string }` — returns substring from the first `STRINGS.`; unchanged if absent)
- Test: `tests/Feature/GameImport/StringResolverTest.php`, `tests/Feature/GameImport/GameTranslationWriterTest.php`

**Interfaces:**
- Catalog precedence, per locale `L`: base English ← mod English (normalized) ← base `L.po` ← mod `L.po` (normalized). `en.json` = base English ← mod English.
- Consumes `ModCatalog::potPaths()` / `poPaths($locale)` (Task 4).

- [ ] **Step 1: Failing tests** — `it('resolves a mod building name from the mod pot')` (`STRINGS.BUILDINGS.PREFABS.MINING_MINERALDRILL.NAME` resolves to the English text from the fixture pot); `it('writes mod strings into the french catalog with english fallback')` (fixture: one key translated in `fr.po`, one not → `fr.json` has the French text for the first and the English text for the second); `it('keeps the base french translation when a mod overrides a base key in english')`.
- [ ] **Step 2:** Run the two test files → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Re-run → PASS.
- [ ] **Step 5:** Commit: `feat(i18n): load supported mod strings with english fallback`.

---

## Phase 2 — Domains

Every reader lives in `app/Services/GameImport/OniExport/`, is `final readonly`, takes `OniExport` and `ModCatalog` in its constructor, exposes `records(): list<array<string, mixed>>`, and returns records already filtered of skipped-mod prefabs, each with a `mod_id` key (`?int`). Importers gain `importRecords(array $records): void`; their existing `import(string $path)` keeps working by decoding the file and delegating (keeps current tests valid). Fixture slices for each reader go in `tests/Fixtures/OniExport/database/` (trimmed real JSON).

### Task 6: Buildings (+ editor fields)

**Files:**
- Create: `app/Services/GameImport/OniExport/BuildingReader.php`
- Create: `app/Enums/BuildLocationRule.php`, `app/Enums/PermittedRotations.php` (int-backed, case names = game enum names, values from the game's `BuildLocationRule` / `PermittedRotations` enums — read them from `Assembly-CSharp.dll` with the existing Cecil tooling or decompiler; upstream reference `~/dev/blueprintnotincluded/lib/src/enums/build-location-rule.ts` and `permitted-rotations.ts`, MIT)
- Create: migration `add_editor_columns_to_buildings_table`
- Modify: `app/Models/Building.php` (fillable, casts, `mod()` BelongsTo)
- Modify: `app/Services/GameImport/BuildingImporter.php` (`importRecords`), construction materials filled from `materialCategory`/`materialMass`
- Modify: `app/Services/BuildingSubGroupResolver.php` → delete if `subcategory` replaces it (update its call sites)
- Test: `tests/Feature/GameImport/OniExport/BuildingReaderTest.php`

**Interfaces:**
- New `buildings` columns: `subcategory` (string), `ports` (json `list<array{type: string, x: int, y: int, secondary: bool}>`), `build_location_rule` (string, enum name), `permitted_rotations` (string, enum name), `object_layer` (string, from root `objectLayerNames`), `scene_layer` (int), `overlay` (string|null; `viewMode` mapped like `~/dev/blueprintnotincluded/app/api/batch/convert-export-2024.ts` `viewMode` mapping), `is_foundation`, `is_tile` (`isKAnimTile`), `is_utility`, `drag_build`, `replaceable` (bools), `placement_rect` (json `{x,y,w,h}`|null from `uiImageRect`), `mod_id`.
- Record mapping: `building_id` = `name`; only `showInBuildMenu && !deprecated && !debugOnly`; `category` = key of `buildingAndSubcategoryDataPairs` containing the id; `subcategory` = its value; `name_localization_id` = `STRINGS.BUILDINGS.PREFABS.{UPPER(id)}.NAME`; `power_consumption` = `energyConsumer.baseWattageRating`; `power_generation` = `energyGenerator` wattage; `width`/`height`; `construction_time` and `heat_generation` (= self + exhaust kW) from `planner_extras.json`; `tags` = `tags[].Name`; `dlc_id` = first non-empty of `kPrefabID.requiredDlcIds`; materials = zip(`materialCategory`, `materialMass`).
- Category labels: if the UI's category labels break because values change (`base`, `oxygen`, `power`… instead of today's), map display labels from `STRINGS.UI.BUILDCATEGORIES.{UPPER}.NAME`; keep URLs/filters working.

- [ ] **Step 1: Failing tests** — `it('reads electrolyzer ports and size')` (`ports` = `[['type'=>'LiquidInput','x'=>0,'y'=>0,'secondary'=>false],['type'=>'PowerInput','x'=>1,'y'=>0,'secondary'=>false],['type'=>'LogicInput','x'=>1,'y'=>1,'secondary'=>false]]`, `width` 2, `height` 2, `power_consumption` 120.0); `it('reads flush toilet placement rules')` (`build_location_rule` `'OnFloor'`, `permitted_rotations` `'FlipH'`); `it('attaches ronivan buildings to their mod')` (`Mining_MineralDrill` `mod_id` = the persisted mod's id); `it('skips buildings hidden from the build menu')`; `it('takes construction time and heat from planner extras')`. Plus importer test: `it('imports building records with editor columns')`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement migration, enums, reader, importer changes, subcategory replacement.
- [ ] **Step 4:** Run the new tests and `tests/Feature/BuildingsIndexTest.php tests/Feature/BuildingShowTest.php tests/Feature/GameImport/BuildingImporterTest.php` → PASS.
- [ ] **Step 5:** Commit: `feat(import): buildings from OniExtract with ports and placement rules`.

### Task 7: Room types

**Files:**
- Create: `app/Models/RoomType.php` (`#[GameData]`), factory, migration `create_room_types_table`
- Create: `app/Services/GameImport/OniExport/RoomTypeReader.php`, `app/Services/GameImport/RoomTypeImporter.php`
- Test: `tests/Feature/GameImport/OniExport/RoomTypeReaderTest.php`

**Interfaces:**
- `room_types`: `room_type_id` (unique), `category`, `name_key`, `requirements` (json, the export's primary/additional constraints for the room type, kept structured: list of `{description_key|null, tags: list<string>, min: int|null, max: int|null}` — derive from `db.json roomTypes[]`, inspect its shape first), `size_min`/`size_max` (int|null), `bonus_effect` (string|null), `priority` (int). Building tags matching `building.json roomConstraintTags` stay in `buildings.tags`.

- [ ] **Step 1: Failing test** — `it('imports the washroom room type with its constraints')` (fixture `db.json` slice: `PlumbedBathroom` has a requirement whose tags include `FlushToiletType`).
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit: `feat(import): room types from OniExtract`.

### Task 8: Elements (game + supported mod YAML)

**Files:**
- Modify: `app/Services/GameImport/ElementImporter.php` (`importYaml(string $yamlPath, ?string $state = null, ?int $modId = null)` — mod YAML files mix states, so state comes from each entry's `state` field when `$state` is null)
- Test: `tests/Feature/GameImport/ElementImporterTest.php`

**Interfaces:**
- Consumes `ModCatalog::elementYamlPaths()` and the mod's `Mod.id`.

- [ ] **Step 1: Failing test** — `it('imports mod elements from the mod yaml with the mod id')` (fixture mod YAML element `PhosphorBronze` → `state` `solid`, `mod_id` set, name resolved through the mod pot key `STRINGS.ELEMENTS.PHOSPHORBRONZE.NAME`).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS (also existing `ElementImporterTest`).
- [ ] **Step 5:** Commit: `feat(import): import supported mod elements`.

### Task 9: Recipes

**Files:**
- Create: `app/Services/GameImport/OniExport/RecipeReader.php`
- Modify: `app/Services/GameImport/RecipeImporter.php` (`importRecords`, `mod_id`)
- Test: `tests/Feature/GameImport/OniExport/RecipeReaderTest.php`

**Interfaces:**
- Record = current json-cache shape (`building_id` = first fabricator, `duration` = `time`, `fabricators`, `name_localization_id`, `inputs`/`outputs` = `[{element_id: material.Name, amount}]`) + `mod_id` (set when the fabricator is a mod building).

- [ ] **Step 1: Failing tests** — `it('reads the katairite electrobank recipe')` (inputs `[['element_id'=>'Katairite','amount'=>200.0]]`, outputs `[['element_id'=>'EmptyElectrobank','amount'=>1.0]]`, `duration` 60.0); `it('skips a recipe whose fabricator belongs to an unsupported mod')`; `it('drops ingredients from unsupported mods and counts them')` (reader exposes `skippedReferences(): int`).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS.
- [ ] **Step 5:** Commit: `feat(import): recipes from OniExtract`.

### Task 10: Critters

**Files:**
- Create: `app/Services/GameImport/OniExport/CritterReader.php`
- Modify: `app/Services/GameImport/CritterImporter.php` (`importRecords`, `mod_id`)
- Test: `tests/Feature/GameImport/OniExport/CritterReaderTest.php`

**Interfaces:**
- Record = current `json-cache/critters.json` shape (fields listed in Task 2) built with the Task 2 formulas; `species_tag` from `planner_extras.json`; `variant_family` derived from the species tag the same way the current pipeline does (`HatchSpecies` → `Hatch`); `name_localization_id` = the key found by `StringResolver::findNameKey` / `STRINGS.CREATURES.SPECIES.*` as today.

- [ ] **Step 1: Failing tests** — `it('reads hatch ranching data')` (`calories_per_cycle` 700000, `lifespan` 100, `incubation_time` 20, `min_temp` 283.15, `max_temp` 313.15, `overcrowding_threshold` 12, `is_base` true, egg output `amount_per_cycle` ≈ 0.016667, diet `Sand` → `amount_per_cycle` 140, `produced_element_id` `Carbon`, `conversion_ratio` 0.5); `it('links a baby to its adult')` (`HatchBaby.parent_critter_id` = `Hatch`); `it('drops diet items from unsupported mods')`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS (and `tests/Feature/GameImport/CritterImporterTest.php`, `tests/Feature/Critters/*`).
- [ ] **Step 5:** Commit: `feat(import): critters from OniExtract`.

### Task 11: Plants and geysers

**Files:**
- Create: `app/Services/GameImport/OniExport/PlantReader.php`, `GeyserReader.php`
- Modify: `PlantImporter.php`, `GeyserImporter.php` (`importRecords`, `mod_id`)
- Test: `tests/Feature/GameImport/OniExport/PlantReaderTest.php`, `GeyserReaderTest.php`

**Interfaces:**
- Records = current json-cache shapes (`plants.json`, `geyser_types.json`), `kanim` from `planner_extras.json` (plants) / `anim` (geysers).

- [ ] **Step 1: Failing tests** — `it('reads bristle blossom growth')` (`PrickleFlower`: `growth_time` 6.0, `min_temp` 278.15, `max_temp` 303.15, input `Water` ≈ 20 kg/cycle, output `PrickleFruit`); `it('reads the steam geyser')` (`element_id` `Steam`, `temperature` 383.15, `min_yield_rate` 1000, `max_yield_rate` 2000, `max_pressure` 5, `kanim` `geyser_gas_steam_kanim`).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS.
- [ ] **Step 5:** Commit: `feat(import): plants and geysers from OniExtract`.

### Task 12: Duplicants and rockets

**Files:**
- Create: `app/Services/GameImport/OniExport/DuplicantReader.php`, `RocketReader.php`
- Modify: `DuplicantImporter.php`, `RocketImporter.php` (`importRecords`)
- Test: `tests/Feature/GameImport/OniExport/DuplicantReaderTest.php`, `RocketReaderTest.php`

**Interfaces:**
- Duplicant traits from `db.json traits` (duplicant trait groups), effects from `SelfModifiers`; base stats source per the audit (Task 2/3). Rocket engines/modules from building defs `rocketEngineCluster` / `cargoBayCluster` and `rocketModuleMenu`. Records keep current json-cache shapes.

- [ ] **Step 1: Failing tests** — one per reader asserting a known record (values taken from the current DB for an unchanged base entity, e.g. a common trait and `CO2Engine`).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS.
- [ ] **Step 5:** Commit: `feat(import): duplicants and rockets from OniExtract`.

---

## Phase 3 — Wire-up, assets, retire, badge

### Task 13: Assets import

**Files:**
- Create: `app/Console/Commands/GameImportAssetsCommand.php`
- Create: `app/Services/GameImport/OniExport/AssetConverter.php`
- Test: `tests/Feature/GameImport/GameImportAssetsCommandTest.php`

**Interfaces:**
- `php artisan game:import-assets [--force]`: for each imported building, `export/ui_image/<id>.png` → `public/assets/game/buildings/<id>.webp` (GD `imagewebp`, quality 90, alpha kept); connection sprites `export/connection_sprites/<id>/<0-15>.png` → `public/assets/game/connections/<id>/<n>.webp`; copy `connection_sprite_cells.json` → `public/assets/game/connections/cells.json`. Skips files whose source mtime ≤ destination mtime unless `--force`. Never touches `critters/`, `plants/`, `geysers/`. Element icons: keep existing files; only fill missing ones from `ui_image/<elementId>.png`.
- `AssetConverter::pngToWebp(string $source, string $destination, int $quality = 90): void`.

- [ ] **Step 1: Failing tests** — `it('converts building renders and connection sprites to webp')` (fixture PNGs in temp dir → files exist, `getimagesize` mime `image/webp`, same dimensions); `it('does not touch critter sprites')`; `it('only fills missing element icons')`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS.
- [ ] **Step 5:** Commit: `feat(assets): import building renders and connection sprites`.

### Task 14: `game:import` on the export; retire the C# extractor

**Files:**
- Modify: `app/Console/Commands/GameImportCommand.php` (options: drop `--cache-path`, `--skip-extractor`; add `--export-path` default `config('game.export_path')`, `--mods-path` default `config('game.mods_path')`)
- Modify: `app/Services/GameImport/PrefabNameImporter.php` (`import(array $recordsByDomain)` with keys `buildings`, `critters`, `plants`, `geysers` instead of a cache path)
- Modify: `scripts/kanim/worklist.json` generation note / `scripts/kanim/*` to read plant kanims from the DB (`plants` kanim) instead of `json-cache` — keep render code untouched
- Delete: `dotnet-extractor/`, `json-cache` usage, `scripts/extract-building-assets.py`, `scripts/building_kanim_map.txt`, `scripts/extract_building_names.py`, `scripts/building_names.json` (only if no remaining reference — `rg` first)
- Modify: `tests/Feature/GameImport/GameImportCommandTest.php`
- Modify: memory note `~/.claude/projects/-home-mishaa-dev-ony-planner/memory/project_oni_dlc_extraction.md` (pipeline section) and `project_oniextract_mod.md`

**Interfaces:**
- Order inside `handle()`: build `OniExport` + `ModCatalog` → **validate** (export present, `planner_extras.json` present, supported mod folders present; compare `buildVersion` with the game strings' build if available → `warn`) → truncate → translation catalogs (with mod pots/pos) → mods persist → elements (game + mod YAML) → buildings → room types → recipes → critters → plants → prefab names → geysers → duplicants → rockets → codex (unchanged) → report (counts + `ModCatalog::skippedReport()` + skipped references).

- [ ] **Step 1: Failing tests** — `it('aborts before truncating when the export is missing')` (seed one `Building`, point `--export-path` to an empty temp dir → exit code 1, building count still 1); `it('warns on build version mismatch')`; `it('imports the fixture export end to end')` (fixture export + mod → buildings incl. `Mining_MineralDrill` with mod, elements incl. `PhosphorBronze`, report lists skipped mod `Stairs`).
- [ ] **Step 2:** FAIL. **Step 3:** Implement, delete retired code, `rg -n 'json-cache|dotnet-extractor|skip-extractor'` → no hits outside docs/specs history.
- [ ] **Step 4:** `php artisan test --compact` → all PASS.
- [ ] **Step 5 (real data):** `php artisan game:sync-export && php artisan game:import && php artisan game:import-assets` (destructive — the user approved this pipeline; still announce before running). Expect: buildings ≈ 555 minus skipped-mod buildings, elements ≥ 212 + Ronivan's, report lists the 18 unsupported mods. Spot-check `/buildings` and `/critters` pages in the browser (Playwright screenshot) — automation buildings (`LogicGateAND`) and `Mining_MineralDrill` visible.
- [ ] **Step 6:** Commit: `refactor(import): OniExtract export replaces the Cecil extractor`.

### Task 15: Mod badge

**Files:**
- Create: `app/Data/ModData.php` (`public function __construct(public string $workshop_id, public string $title) {}`)
- Modify: `app/Data/BuildingData.php`, `BuildingShowData.php`, `ElementData.php`, `ElementShowData.php`, critter/plant data classes shown in lists/detail (`?ModData $mod = null`) and their presenters/controllers (eager-load `mod`)
- Create: `resources/js/components/ui/ModBadge.vue` (`lang="ts"`, prop `mod: { workshop_id: string; title: string }`, small pill showing "Mod" + title, `title` attribute with full mod name; follows existing badge styles in `resources/js/components/ui`)
- Modify: `resources/js/pages/Buildings/Index.vue`, `Buildings/Show.vue`, `Elements/Index.vue`, `Elements/Show.vue`, critter index/show pages — render `<ModBadge>` when `mod` is set
- Test: `tests/Feature/BuildingsIndexTest.php`, `BuildingShowTest.php`, `ElementPageTest.php`

**Interfaces:**
- Regenerate TS types (project's TypeScript transformer, `php artisan typescript:transform` or the configured equivalent) so `ModData` reaches the frontend types.

- [ ] **Step 1: Failing tests** — `it('exposes the mod on modded buildings')` (Inertia prop `buildings.*.mod.title` = `Ronivans Legacy - Industrial Revolution` for a factory-made modded building, `null` for a base one); same for building show and element show.
- [ ] **Step 2:** FAIL. **Step 3:** Implement backend + Vue. **Step 4:** PASS; `bun run typecheck` clean; browser check of one modded building page.
- [ ] **Step 5:** Commit: `feat(ui): mod badge on modded content`.
