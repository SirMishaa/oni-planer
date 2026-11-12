# Zone Map Rendering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the zone map look close to the game: textured solid terrain with the game's organic ink edges, animated transparent liquids and gases, and the game's four-mode temperature overlay, all driven by per-element data exported from the game (mods included).

**Architecture:** OniExtract's planner extras gain a `render` block per element and a new render export (terrain textures, edge mask atlas, overlay colours). `game:import` stores the element fields, `game:import-assets` publishes the images and a `render.json`. The zone presenter passes the per-element render properties, translated temperature band names and the previous slow-state temperatures. In the browser a static Canvas2D layer paints terrain (cached, repainted on data/layout change), a WebGL2 layer animates liquids and gases above it, and pure TS modules compute temperature colours and edge masks.

**Tech Stack:** C# net48 mod (OniExtract fork, `~/dev/OniExtract2024`, xunit in `OniExtract2024.Core.Tests`), Laravel 13 / PHP 8.5, Pest, spatie/laravel-data + typescript-transformer, Vue 3 + Inertia v3, Vitest, raw WebGL2 (no new dependency).

**Spec:** `docs/superpowers/specs/2026-11-12-zone-map-rendering-design.md` (commit 6c35b50). Read it before any task.

**Departures from the spec's "How" section (Claude's calls, open to veto):**
- Terrain textures and the mask atlas are exported **in game** by OniExtract (read from the loaded materials), not extracted with UnityPy: textures added by mods only exist in the running game, and the spec asks for modded textures. `scripts/extract-assets.py` and its hand-written element→texture table stay untouched (no longer used by the map).
- Render fields go into OniExtract's `planner_extras.json` (our fork's file), not upstream `BElement`/`elements.json`, to keep the upstream contract unchanged.
- Constructed tiles keep the existing connection sprites (OniExtract path 3 already renders them from the game's tile atlases, joins and tops included); no new tile-sheet export.
- State-change colours were decompiled while planning: they are a pure HSV formula (below), so nothing to export for that mode.
- Heat flow keeps the previous slow-state temperatures on the zone row (copied in the same UPDATE that stores the new slow state), so the mode works right after a page load.

## Global Constraints

- No hard-coded per-element style tables. The only hand-written mapping is substance texture family → visual pattern (closed game enum `Substance.SubstanceTexture`: None, Magma, MoltenMetal, Polluted, Oil, Thick, Sap, CrystalFragments, Ovolene, Mucus, Ink, PollutedBrine).
- Liquid/gas colour = `Substance.colour` **with alpha**, never `uiColour`. Textures are drawn as-is, never mixed into the page background.
- Absolute temperature: thresholds from 0 K stepping by 273.15, 10, 10, 10, 7, 63, 1700, 10000 K (stops −273.15, 0, 10, 20, 30, 37, 100, 1800, 11800 °C), linear blend between the two surrounding threshold colours; below the first stop → colour 0, above the last → last colour.
- Adaptive temperature: intervals 0.4, 0.05, 0.05, 0.05, 0.05, 0.2, 0.2 of the zone's min..max span, same blending.
- State change (decompiled `SimDebugView.GetStateChangeProximityColour`): hue = `(10 + (1 − n) × 171) / 360`, S = 1, V = 1, where `n = (T − lo) / (hi − lo)` clamped to **[0, 0.5]** for gases with `hi = min(lowTemp + 150, highTemp)`, **[0.5, 1]** for solids with `lo = max(highTemp − 150, lowTemp)`, **[0, 1]** for liquids (`lo = lowTemp`, `hi = highTemp`). A transition stored as null uses the game runtime defaults 0 K (low) and 10000 K (high).
- Heat flow is an approximation from two slow states; before a second reading the layer shows "waiting for a second reading".
- WebGL2 unavailable → map fully readable without animation. Animation pauses when `document.hidden` or the map is off screen, and when `prefers-reduced-motion: reduce`.
- No new JS or PHP dependency. Bun only (`bun`, `bunx`), never npm/npx. Python via `uv run` only (not needed by this plan).
- Vue SFCs `lang="ts"`; `<!-- region -->` / `// region` markers as in sibling files; Wayfinder for any route (no hand-written URLs; static `/assets/game/...` paths are fine, as `zoneMapPainter.ts` does).
- `game:import` is destructive: ask the user before running it. The in-game exports are run by the user.
- Commits: no GPG signature, no Co-Authored-By trailer (user memory overrides the harness default).
- OniExtract: never claim in-game behaviour works from a build; say what was compiled and that the export is unverified until the user runs it. Close ONI before building, or pass `-p:ModFolder=<scratch dir>`.

## Review Focus

1. **A palette element unknown to the game data** (mod element skipped at import, or `elementId` null): must render with a visible fallback (render colour if known, else the neutral ink with the "unknown" hatch), never a hole. → test in Task 7 (`paints a fallback for a palette entry without render data`).
2. **Liquid mass above `maxMass`** (pressurised liquid, common in the game) or `maxMass` null/0: fill clamps to 1, no NaN. → test in Task 6 (`clamps the fill of an over-pressurised liquid`, `fills a cell fully when max mass is unknown`).
3. **Slow-state box changed between two readings** (zone edited): heat flow must not diff misaligned arrays; presenter drops the previous sample. → test in Task 4 (`drops the previous temperatures when the box changed`).
4. **Zone with every temperature equal** in Adaptive mode (span 0): no division by zero; every cell gets the colour at the span's start. → test in Task 5 (`adaptive mode of a uniform zone`).
5. **Assets missing for a solid** (export not re-run after a game update): `game:import-assets` lists them and fails; the map draws the element's render colour flat with a visible stripe instead of a hole. → tests in Task 3 and Task 7.

---

### Task 1: OniExtract — per-element render properties

**Files:**
- Create: `~/dev/OniExtract2024/OniExtract2024.Core/RenderColor.cs`
- Create: `~/dev/OniExtract2024/OniExtract2024.Core.Tests/RenderColorTests.cs`
- Modify: `~/dev/OniExtract2024/OniExtract2024/planner/PlannerExtrasModel.cs` (add `PlannerElementRender`, field `render` on `PlannerElement`)
- Modify: `~/dev/OniExtract2024/OniExtract2024/planner/ExportPlannerExtras.cs` (`ReadElement`)
- Modify: `~/dev/OniExtract2024/docs/EXPORT_SCHEMA.md` (document `planner_extras.json` `elements.*.render`)

Work on a branch `feat/render-export` in the OniExtract repo. Use planner extras (our fork's file), not `BElement`, so the upstream `elements.json` contract stays untouched.

**Interfaces:**
- Produces in `planner_extras.json`, for every element: `render: { color: "#rrggbbaa", substanceTexture: string, usesCaustics: bool, glows: bool, metallic: bool, opaqueLiquid: bool, scrollSpeed: float, maxMass: float, terrainTexture: string|null }`. `render` is omitted (not null) when `element.substance` is null.

- [ ] **Step 1: Write the failing test** — `RenderColorTests.ToHex_packs_rgba_lowercase`: `RenderColor.ToHex(0x55, 0xbe, 0xd2, 0xff)` == `"#55bed2ff"`; `ToHex(0, 0, 0, 0)` == `"#00000000"`.
- [ ] **Step 2: Run** `dotnet test OniExtract2024.Core.Tests/OniExtract2024.Core.Tests.csproj --filter RenderColorTests` — expect a compile failure (type missing).
- [ ] **Step 3: Implement** `public static string RenderColor.ToHex(byte r, byte g, byte b, byte a)` in `OniExtract2024.Core` (netstandard2.0, no Unity).
- [ ] **Step 4: Run** the same test — PASS.
- [ ] **Step 5: Fill `render` in `ReadElement`** from `e.substance`: `colour` (Color32, through `RenderColor.ToHex`), `Texture.ToString()`, `LiquidCaustics`, `Glows`, `Metalic`, `IsOpaqueLiquid`, `e.maxMass`. `scrollSpeed`: read once per export from the loaded `LiquidShaderProperties` (`Resources.FindObjectsOfTypeAll<LiquidShaderProperties>()[0]`, private `TextureScrollSpeed` through `HarmonyLib.Traverse`), 0 when the family has no entry. `terrainTexture`: `e.IsSolid ? e.substance.material?.mainTexture?.name : null`. Wrap each element in the existing per-element try/catch.
- [ ] **Step 6: Compile check** — build the mod with `-p:ModFolder=<scratch dir>` (see repo CLAUDE.md for the MSBuild/WSL command); expect success. In-game output is **unverified** at this point.
- [ ] **Step 7: Commit** (OniExtract repo) `feat: export element render properties in planner extras`.

### Task 2: OniExtract — terrain textures, edge mask atlas and overlay colours

**Files:**
- Create: `~/dev/OniExtract2024/OniExtract2024/planner/ExportRenderAssets.cs`
- Create: `~/dev/OniExtract2024/OniExtract2024/planner/PlannerRenderModel.cs`
- Modify: `~/dev/OniExtract2024/OniExtract2024/ExportGameData.cs` (call it after `WritePlannerExtras`)
- Modify: `~/dev/OniExtract2024/docs/EXPORT_SCHEMA.md`, `~/dev/OniExtract2024/tools/Test-Export.ps1` (spot checks below)

**Interfaces:**
- Produces, under the export root (`export/`, beside `database/`):
  - `render/terrain/{textureName}.png` — one per distinct `terrainTexture` of Task 1, readable copy via `AnimTool.GetReadableCopy`.
  - `render/mask_atlas.png` — `GroundRenderer.masks.maskAtlas.texture` (`World.Instance.groundRenderer`, private field `masks`, through `Traverse`).
  - `database/planner_render.json`:
    ```json
    {
      "temperatureThresholds": [{"colorName": "…", "value": 0.0, "color": "#rrggbbaa"}],
      "heatFlowThresholds":    [{"colorName": "…", "value": 0.0, "color": "#rrggbbaa"}],
      "maskAtlas": {"width": 0, "height": 0, "fallbackBiome": "…",
                    "items": [{"name": "Sandstone/0111_a", "uvBox": [x, y, z, w]}]}
    }
    ```
    Thresholds come from `SimDebugView.Instance.temperatureThresholds` / `heatFlowThresholds`, colours through `GlobalAssets.Instance.colorSet.GetColorByName(colorName)`. `fallbackBiome` = `((SubWorld.ZoneType)3).ToString().ToLower()` (the biome `WorldChunk.Rebuild` falls back to). `uvBox` copied verbatim from `TextureAtlas.Item.uvBox` (x, y, z, w as the game stores them; the site reorders them like `GroundMasks.Initialize`).
- The export prunes `render/` before writing (same rule as other paths: no stale files from disabled mods).

- [ ] **Step 1: Implement** `ExportRenderAssets.WriteAll()` and the DTOs; log `OniExtract: render assets, N terrain textures, M mask items.` to `Player.log`.
- [ ] **Step 2: Add spot checks to `Test-Export.ps1`**: `planner_render.json` has ≥ 9 temperature thresholds and 3 heat-flow thresholds; every `render.terrainTexture` in `planner_extras.json` has `render/terrain/<name>.png`; `render/mask_atlas.png` exists; `maskAtlas.items` non-empty.
- [ ] **Step 3: Compile check** as in Task 1. Report it compiled and that the export is unverified.
- [ ] **Step 4: Commit** `feat: export terrain textures, ground mask atlas and overlay colours`.

### Task 3: Human checkpoint — re-export, then import element render data and assets

The user installs the rebuilt mod (restart ONI fully), runs **Export Game Data** (and Export Building Images, Export Connection Sprites to keep the export complete), then `.\tools\Test-Export.ps1`. Wait for them. Then `php artisan game:sync-export` mirrors it (it copies the whole tree, `render/` included).

**Files:**
- Create: `app/Enums/SubstanceTexture.php`
- Create: migration `add_render_properties_to_elements_table` (`php artisan make:migration --no-interaction`)
- Modify: `app/Models/Element.php` (fillable, casts, `renderColorHex(): ?string`)
- Modify: `app/Services/GameImport/OniExport/ElementReader.php` (phpstan shape + record fields)
- Modify: `app/Services/GameImport/ElementImporter.php` (`importRecords`, `importYaml` sets the new columns null/false)
- Modify: `app/Console/Commands/GameImportAssetsCommand.php`
- Modify: `tests/Fixtures/OniExport/database/planner_extras.json` (add `render` to Water, Oxygen, SandStone; leave one element without `render`), create `tests/Fixtures/OniExport/database/planner_render.json`, `tests/Fixtures/OniExport/render/terrain/sand_stone.png` (tiny PNG), `tests/Fixtures/OniExport/render/mask_atlas.png`
- Test: `tests/Feature/GameImport/OniExport/ElementReaderTest.php`, `tests/Feature/GameImport/GameImportAssetsCommandTest.php` (create if absent; check sibling tests for the export-path setup)

**Interfaces:**
- `enum App\Enums\SubstanceTexture: string` — the 12 game cases, value = case name. Regenerate TS types (`php artisan list | rg typescript` to find the transform command) so `resources/js/types` exports `SubstanceTexture`.
- Columns on `elements` (all nullable unless stated): `render_color` unsignedInteger (0xRRGGBBAA), `substance_texture` string, `uses_caustics` bool default false, `glows` bool default false, `is_metallic` bool default false, `is_opaque_liquid` bool default false, `liquid_scroll_speed` float, `max_mass` float, `terrain_texture` string.
- `Element::renderColorHex(): ?string` → `#rrggbbaa` lowercase.
- `ElementReader` maps an unknown `substanceTexture` to `null` (via `SubstanceTexture::tryFrom`) and collects it; `ElementReader::unknownSubstanceTextures(): list<string>` lets `game:import` warn.
- `game:import-assets` publishes: `{assets}/terrain/{texture}.webp`, `{assets}/terrain/mask_atlas.webp`, `{assets}/render.json` (copy of `planner_render.json`). After converting it checks every solid element with a `terrain_texture` has its webp, and every solid has a `terrain_texture`; it prints the missing ones and returns FAILURE when any is missing.

- [ ] **Step 1: Write failing tests** in `ElementReaderTest`:
  - `reads the element render properties from planner extras`: Water → `render_color` = 0x55bed2ff (from fixture `"#55bed2ff"`), `substance_texture` = `None`, `max_mass` = 1000.0; SandStone → `terrain_texture` = `sand_stone`; `Element::renderColorHex()` of Water = `#55bed2ff`.
  - `stores null render properties for an element without a render block`.
  - `drops an unknown substance texture family and reports it`: fixture element with `"substanceTexture": "Glitter"` → `substance_texture` null and `unknownSubstanceTextures()` = `['Glitter']`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/GameImport/OniExport/ElementReaderTest.php` — FAIL.
- [ ] **Step 3: Implement** enum, migration, model, reader, importer. Run migration on the dev DB (`php artisan migrate --no-interaction`; additive, not destructive).
- [ ] **Step 4: Run** the tests — PASS.
- [ ] **Step 5: Write failing tests** in `GameImportAssetsCommandTest`:
  - `publishes terrain textures, the mask atlas and render.json` (fixture export, temp assets path via `config()`): files exist, `render.json` equals the fixture.
  - `fails and lists solids without a terrain texture asset`: a solid with `terrain_texture` = `granite` and no PNG → exit code FAILURE, output contains `granite`.
- [ ] **Step 6: Run** — FAIL. **Step 7: Implement** in the command (reuse `convert()` / `isStale()`). **Step 8: Run** — PASS.
- [ ] **Step 9: Ask the user before running `php artisan game:import`**, then run it and `php artisan game:import-assets`. Expected: no missing terrain texture, no unknown texture family. Report counts.
- [ ] **Step 10:** `vendor/bin/pint --dirty --format agent`, commit `feat: import element render properties and terrain assets`.

### Task 4: Zone presenter — render properties, band names, previous temperatures

**Files:**
- Create: migration `add_previous_slow_temperature_to_zones_table`
- Modify: `app/Models/Zone.php`, `app/Actions/Uplink/StoreZoneState.php`
- Modify: `app/Data/ZoneTerrainElementData.php`, `app/Data/ZoneTerrainData.php`, `app/Data/ZoneMapData.php`
- Modify: `app/Services/Zones/ZonePresenter.php` (`terrain()`, `paletteElements()` columns)
- Test: `tests/Feature/Uplink/ZoneActionsTest.php`, `tests/Unit/Services/ZonePresenterTest.php`

**Interfaces:**
- `zones.previous_slow_temperature` json nullable: `{box: {x,y,width,height}, temperature: list<float|null>, at: ISO 8601}`. `StoreZoneState::handle('slow', …)` sets it from the zone's current `slow_state` (`box`, `temperature`) and `slow_state_at` **in the same `update()`** (the zone is already loaded: no extra query). Fast states leave it alone.
- `ZoneTerrainElementData` gains (all nullable / defaulted, TS regenerated): `renderColour: ?string` (`#rrggbbaa`), `substanceTexture: ?SubstanceTexture`, `usesCaustics: bool`, `glows: bool`, `metallic: bool`, `opaqueLiquid: bool`, `scrollSpeed: ?float`, `maxMass: ?float`, `terrainTexture: ?string` (URL `/assets/game/terrain/{name}.webp` or null), `lowTemp: ?float`, `highTemp: ?float` (kelvin, null = no transition).
- `ZoneTerrainData` gains `previousTemperature: ?list<float|null>` and `previousSeconds: ?float` (seconds between the two readings); both null when no previous reading, when its box differs from the current box, or when its length differs.
- `ZoneMapData` gains `temperatureBands: list<string>` — 8 names, translated through `GameText::translate()` from `STRINGS.UI.OVERLAYS.TEMPERATURE.` + `EXTREMECOLD, VERYCOLD, COLD, TEMPERATE, HOT, VERYHOT, EXTREMEHOT, MAXHOT` (band i = interval i of the absolute/adaptive scale).

- [ ] **Step 1: Write failing tests:**
  - `ZoneActionsTest` › `keeps the previous slow temperatures when a new slow state arrives`: store slow A (temps [280, 290]) then slow B → `previous_slow_temperature.temperature` = [280, 290], `.box` = A's box.
  - `ZonePresenterTest` › `gives each palette entry its render properties`: Water entry → `renderColour` `#55bed2ff`, `maxMass` 1000.0, `substanceTexture` `SubstanceTexture::None`; SandStone → `terrainTexture` `/assets/game/terrain/sand_stone.webp`.
  - `ZonePresenterTest` › `gives the previous temperatures and the seconds between readings` (30 s apart → `previousSeconds` 30.0).
  - `ZonePresenterTest` › `drops the previous temperatures when the box changed`.
  - `ZonePresenterTest` › `names the eight temperature bands in the current locale` (fr: index 3 = `Tempéré`, needs the fr catalog fixture already used by GameText tests; check siblings).
- [ ] **Step 2: Run** `php artisan test --compact --filter='ZoneActionsTest|ZonePresenterTest'` — FAIL.
- [ ] **Step 3: Implement.** Update `ZoneFactory` states if they need the new column.
- [ ] **Step 4: Run** — PASS; also `php artisan test --compact tests/Feature/Zones` to catch fallout.
- [ ] **Step 5:** pint, regenerate TS types, commit `feat: zone map render data and previous temperatures`.

### Task 5: Temperature overlay maths (TS)

**Files:**
- Create: `resources/js/components/zones/zoneTemperature.ts`
- Create: `resources/js/components/zones/zoneTemperature.test.ts`
- Modify: `resources/js/components/zones/zoneMapGeometry.ts` — remove `TEMPERATURE_STOPS`, `temperatureColour`, `MIN_TEMPERATURE_SPAN_K`, `TERRAIN_MIX`, `terrainMix`; keep `temperatureRange` without the widening (min..max as is) and move it here. Remove their tests from `zoneMapGeometry.test.ts` (obsolete behaviour; approval of this plan covers it).

**Interfaces:**
- `type TemperatureMode = 'absolute' | 'adaptive' | 'heatFlow' | 'stateChange'`
- `type OverlayPalette = { temperature: Rgba[]; heatFlow: { colour: Rgba; value: number }[] }` with `type Rgba = [number, number, number, number]` (0–255), parsed by `parseRenderJson(json: unknown): OverlayPalette | null` from `/assets/game/render.json`.
- `ABSOLUTE_INTERVALS_K = [273.15, 10, 10, 10, 7, 63, 1700, 10000]`, `ADAPTIVE_INTERVALS = [0.4, 0.05, 0.05, 0.05, 0.05, 0.2, 0.2]`.
- `thresholdColour(kelvin: number, start: number, intervals: readonly number[], colours: readonly Rgba[]): { colour: Rgba; band: number }` — port of `SimDebugView.NormalizedTemperature`; band = interval index (0..intervals.length−1).
- `absoluteColour(kelvin, palette)`, `adaptiveColour(kelvin, range: {min, max}, palette)` (span 0 → colour at index 0 blend t = 0), both returning `{ colour, band }`.
- `heatFlowColour(ratePerSecond: number, palette): Rgba` — heat-flow colours sorted by `value`; rate mapped linearly so −`HEAT_FLOW_FULL_SCALE_K_PER_S` → first, 0 → middle, +full scale → last; `HEAT_FLOW_FULL_SCALE_K_PER_S = 0.05` (tunable).
- `stateChangeColour(kelvin: number, state: 'solid'|'liquid'|'gas', lowTemp: number|null, highTemp: number|null): Rgba` — formula in Global Constraints; HSV→RGB done here.
- `legendStops(mode, palette, range): { colour: Rgba; label: string }[]` — °C labels for the legend (absolute: the 9 stops; adaptive: stops of the zone span; heat flow: cooling/stable/heating keys; state change: two ends).

- [ ] **Step 1: Write failing tests** (palette fixture = 9 distinct colours `[i*10, 0, 0, 255]`, heat flow `[{colour: blue, value: -1}, {grey, 0}, {red, 1}]`):
  - `gives exactly the 20 °C stop colour at 20 °C`: `absoluteColour(293.15, p)` → `{ colour: p.temperature[3], band: 3 }`.
  - `blends halfway between stops`: 298.15 K → midpoint of colours 3 and 4, band 3.
  - `clamps below absolute zero and above 11800 °C` → colour 0 / colour 8.
  - `adaptive mode of a uniform zone`: range {min: 300, max: 300}, 300 K → colour 0, no NaN.
  - `adaptive mode puts 40 % of the span in the first band`: range 300..400, 340 K → band 1, t = 0.
  - `heat flow maps cooling, stable and heating`: −0.05 → blue, 0 → grey, +0.1 → red.
  - `state change of water near boiling is red`: liquid, low 273.15, high 372.15, T = 372 → hue ≈ 10° (RGB ≈ [255, 42, 0] ± 1).
  - `state change of a gas is clamped to the cold half`: gas low 100, high 1000, T = 900 → hue at n = 0.5.
  - `state change uses the game defaults for missing transitions`: solid, low null, high null → same as low 0, high 10000.
- [ ] **Step 2: Run** `bunx vitest run resources/js/components/zones/zoneTemperature.test.ts` — FAIL.
- [ ] **Step 3: Implement.** **Step 4: Run** — PASS; `bun run typecheck` clean (ZoneMap.vue still imports the removed helpers: switch them to the new module in this task so the build stays green).
- [ ] **Step 5: Commit** `feat: game temperature overlay colours`.

### Task 6: Terrain edges and fluid cells (TS, pure)

**Files:**
- Create: `resources/js/components/zones/zoneTerrainEdges.ts`, `zoneTerrainEdges.test.ts`
- Create: `resources/js/components/zones/zoneFluids.ts`, `zoneFluids.test.ts`

**Interfaces (edges — ports of `GroundMasks` and `GroundRenderer.WorldChunk.Rebuild`):**
- `type UvQuad = { bl: [number, number]; br: [number, number]; tl: [number, number]; tr: [number, number] }`
- `parseMaskAtlas(items: { name: string; uvBox: [number, number, number, number] }[], biome: string): (UvQuad[] | null)[]` — 16 entries indexed by 4-bit mask; name `"<Biome>/<4 bits>…"`, biome lowercased with `_` removed; `uvBox` (x, y, z, w) → bl (x, w), br (z, w), tl (x, y), tr (z, y) as in `GroundMasks.Initialize`; missing masks generated by the rotation rule of `BiomeMaskData.GetNonNullRotationUVs`.
- `cornerQuads(solidAt: (x: number, y: number) => number | null, x: number, y: number): { order: number; mask: number }[]` — for the vertex between cells (x−1, y−1), (x, y−1), (x−1, y), (x, y): `solidAt` returns the solid element's render order (its palette index sorted by element id, mirroring `substance.idx`) or null; for each distinct order ascending, `mask = (s[2] ≥ o) << 3 | (s[3] ≥ o) << 2 | (s[0] ≥ o) << 1 | (s[1] ≥ o)` with `s = [bl, br, tl, tr]` as in the game; emit when `mask > 0` (15 = opaque full quad).
- `variation(x: number, y: number, count: number): number` — deterministic hash (stand-in for the game's Perlin), stable across repaints.

**Interfaces (fluids):**
- `liquidFill(mass: number | null, maxMass: number | null): number` — clamp(mass / maxMass, 0, 1); unknown maxMass → 1; unknown mass → 0.
- `gasStrength(mass: number | null): number` — clamp(mass / `GAS_FULL_TINT_KG`, 0, 1) with `GAS_FULL_TINT_KG = 2` (tunable).
- `fluidCells(terrain: ZoneTerrainData, box: ZoneBoxData): FluidCell[]` with `FluidCell = { column: number; row: number; palette: number; kind: 'liquid' | 'gas'; amount: number; surface: boolean }`; `surface` = liquid whose cell above is not the same liquid; a liquid with the same liquid directly above is drawn full (`amount = 1`).

- [ ] **Step 1: Write failing tests:**
  - edges: `builds 16 masks from source tiles and rotations` (fixture items for masks 0001, 0011, 0111, 1111, 0101 → all 1..15 non-null); `marks a vertex fully inside one solid as opaque` (all four = order 2 → `[{order: 2, mask: 15}]`); `layers two solids at a junction` (bl, br = order 1, tl, tr = order 3 → order 1 mask 15, order 3 mask 0b1100); `ignores non-solid corners` (all null → `[]`); `picks the same variation on every call`.
  - fluids: `clamps the fill of an over-pressurised liquid` (mass 1500, max 1000 → 1); `fills a cell fully when max mass is unknown`; `draws a liquid under the same liquid full`; `flags the top of a liquid body as surface`; `scales gas tint with mass` (1 kg → 0.5).
- [ ] **Step 2: Run** `bunx vitest run resources/js/components/zones/zoneTerrainEdges.test.ts resources/js/components/zones/zoneFluids.test.ts` — FAIL.
- [ ] **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: terrain edge masks and fluid cell model`.

### Task 7: Static terrain painter

**Files:**
- Modify: `resources/js/components/zones/zoneMapPainter.ts`
- Create: `resources/js/components/zones/zoneRenderAssets.ts` (loads and caches `/assets/game/render.json`, `/assets/game/terrain/mask_atlas.webp`, terrain textures; same once-only pattern as `loadTileGeometry`)
- Create: `resources/js/components/zones/zoneMapPainter.test.ts`
- Create: `resources/js/components/zones/substanceTexturePatterns.ts`

**Interfaces:**
- `paintZoneMap(canvas, input)` keeps its signature plus: `temperatureMode: TemperatureMode`, `palette: OverlayPalette | null`, `assets: RenderAssets`, `fluidsAnimated: boolean`, `showGrid: boolean`. Returns paint ms as today.
- Layers **growth, materials, problems**: per solid element, a pattern of its terrain texture aligned to world cells (one texture tile spans `TERRAIN_TEXTURE_CELLS = 4` cells; tunable), clipped by the edge quads of Task 6 drawn from the mask atlas (alpha mask: draw mask quad, then texture with `source-in` on an offscreen canvas per element, composite in render order). Ink outline from the mask's edge: if the atlas already carries the dark rim keep it; otherwise derive it — decide by looking at `mask_atlas.webp` and note the choice in the commit. When `fluidsAnimated` is false, liquids and gases are drawn here statically (render colour with its alpha × fill / gas strength, liquid bottom-aligned).
- Layer **temperature**: each known cell coloured by the mode (absolute/adaptive by cell, heat flow from `previousTemperature`/`previousSeconds`, state change from the palette entry's state and transitions); no textures; tiles at `TILE_ALPHA_ON_TEMPERATURE` as today. Heat flow with no previous sample paints nothing (legend shows the waiting state).
- Fallbacks: a solid without a loaded texture → flat `renderColour` (else `--ink-2`) plus a 45° stripe in `--line`; a palette entry with no render data at all → neutral ink with stripe. Unknown cells stay hatched.
- `showGrid`: 1-device-pixel lines in `--line` at every cell edge, over everything.
- `substanceTexturePatterns.ts`: `SUBSTANCE_TEXTURE_PATTERNS: Record<SubstanceTexture, FluidPattern>` (exhaustive over the generated enum so typecheck flags a new family) and `patternFor(family: SubstanceTexture | null): FluidPattern` → `DEFAULT_PATTERN` for null. `FluidPattern = { noiseScale: number; noiseStrength: number; flecks: number; streaks: boolean }` (values are the builder's; used by Tasks 7 and 8).
- Remove `paletteStyles` mixing and `temperatureStyles`.

- [ ] **Step 1: Write failing tests** (Vitest with a stub 2D context recording `fillStyle`/`drawImage` calls; check siblings for an existing canvas stub, else write a minimal one in the test):
  - `paints a fallback for a palette entry without render data` → a fill with the neutral ink and a stripe, no `drawImage`.
  - `paints the render colour flat when a solid texture is missing`.
  - `paints temperature cells with the absolute colour` (one cell at 293.15 K → fill style of colour 3).
  - `paints nothing for heat flow before a second reading`.
  - `has a pattern for every substance texture family` → `Object.keys(SUBSTANCE_TEXTURE_PATTERNS)` equals the enum's values.
- [ ] **Step 2: Run** `bunx vitest run resources/js/components/zones/zoneMapPainter.test.ts` — FAIL.
- [ ] **Step 3: Implement.** **Step 4: Run** — PASS; `bun run typecheck`.
- [ ] **Step 5: Look at it**: open a zone (Pacu farm or the 2000-cell factory zone) in the running dev server (no build needed, HMR), check textures, edges, fallbacks on each layer; screenshot with Playwright MCP.
- [ ] **Step 6: Commit** `feat: textured terrain with game edge masks`.

### Task 8: WebGL2 fluid layer

**Files:**
- Create: `resources/js/components/zones/zoneFluidRenderer.ts` (+ shader sources as template strings in the same file or `zoneFluidShaders.ts`)
- Create: `resources/js/components/zones/zoneFluidRenderer.test.ts` (pure parts only)

**Interfaces:**
- `createFluidRenderer(canvas: HTMLCanvasElement): FluidRenderer | null` — null when `getContext('webgl2')` is null or shader compile fails (caller falls back to static fluids).
- `FluidRenderer = { update(input: { cells: FluidCell[]; palette: ZoneTerrainElementData[]; box: ZoneBoxData; cellPx: number; dpr: number }): void; start(): void; stop(): void; destroy(): void }`.
- Data upload: one RGBA8 texture of `box.width × box.height` (R = palette index, G = amount × 255, B = flags: surface bit, kind bit); per-palette uniforms (colour + alpha, pattern params from `patternFor`, caustics, glow, opaque, scroll speed) packed in a small RGBA32F texture, so any palette size works.
- Liquids: colour with its alpha (opaque liquid → alpha 1, no bubbles), wavy animated surface with a highlight line on `surface` cells, pattern noise scrolled by `scrollSpeed`, caustics when `usesCaustics`, glow halo when `glows`, metallic sheen when `metallic`, suspended particles and decorative bubbles (none when opaque). Gases: tint × `amount`, drifting particles and wisps. Exact shader design, counts and amplitudes are the builder's.
- `frameBudget` helper (pure, tested): `shouldAnimate({ hidden, onScreen, reducedMotion }): boolean`.

- [ ] **Step 1: Write failing tests:** `packCells` (exported pure helper building the RGBA8 bytes) → for a 2×1 box with a surface liquid at palette 3 amount 0.5 → bytes `[3, 128, surfaceBit|liquidBit, 255, 0, 0, 0, 0]` (rounding: `Math.round`); `shouldAnimate` is false when hidden, off screen or reduced motion.
- [ ] **Step 2: Run** `bunx vitest run resources/js/components/zones/zoneFluidRenderer.test.ts` — FAIL.
- [ ] **Step 3: Implement.** **Step 4: Run** — PASS; `bun run typecheck`.
- [ ] **Step 5: Commit** `feat: animated WebGL2 liquids and gases`.

### Task 9: Zone map integration — layers, temperature modes, legend, hover card, grid

**Files:**
- Modify: `resources/js/components/zones/ZoneMap.vue`
- Modify: `resources/js/pages/Zones/Show.vue` (only if the mode selector sits in the layer bar; preferred: inside `ZoneMap.vue` under the map, beside the legend)
- Modify: `lang/en/zones.php`, `lang/fr/zones.php`
- Test: `tests/Browser/ZonePageTest.php`

**Interfaces / behaviour:**
- Stack in the stage: static canvas (Task 7) → WebGL canvas (Task 8, only on growth/materials/problems) → outline SVG → entities → hover card. The static canvas repaints only on data, layer, mode, grid or size change; the WebGL layer runs its own loop gated by `shouldAnimate` (`document.visibilitychange`, an `IntersectionObserver` on the stage, `matchMedia('(prefers-reduced-motion: reduce)')`).
- Temperature layer shows a 4-button mode selector (`aria-pressed`, like `.zone-layer`), default `absolute`; legend per mode from `legendStops` with °C labels; heat flow with `previousTemperature === null` shows `zones.map.heat_flow_waiting`.
- Grid toggle button (off by default) next to the legend; `aria-pressed`.
- Hover card: frame only the hovered cell (a 1-cell outline element, no local grid). Title = element name; lines = state (`zones.map.states.*`), mass, °C, fill % for liquids (`liquidFill` × 100, rounded); on Temperature: band name from `map.temperatureBands[band]` (absolute/adaptive), signed °C/s for heat flow.
- New lang keys (en + fr): `zones.map.modes.{absolute,adaptive,heat_flow,state_change}`, `zones.map.heat_flow_waiting`, `zones.map.heat_flow.{cooling,stable,heating}`, `zones.map.state_change.{cold,hot}`, `zones.map.grid`, `zones.map.fill`, `zones.map.states.{solid,liquid,gas,special}`.

- [ ] **Step 1: Write failing browser tests** in `ZonePageTest`:
  - extend `draws a 2000-cell zone`: after `click(__('zones.layers.temperature'))`, `click(__('zones.map.modes.state_change'))`, `assertPresent('canvas[data-paint-ms]')`, `click(__('zones.map.modes.heat_flow'))`, `assertSee(__('zones.map.heat_flow_waiting'))`, `assertNoJavaScriptErrors()`.
  - `toggles the grid`: `click(__('zones.map.grid'))` → button `aria-pressed="true"`, no JS errors.
- [ ] **Step 2: Run** `php artisan test --compact tests/Browser/ZonePageTest.php` — FAIL.
- [ ] **Step 3: Implement.** **Step 4: Run** — PASS; also `bunx vitest run resources/js/components/zones` and `bun run typecheck`, `bun run test:lint` (fix with `bun run lint`).
- [ ] **Step 5: Look at it** on the user's Pacu farm in the dev server: every layer, every temperature mode, hover on water/solid/gas, tab hidden → CPU idle (Performance panel or `requestAnimationFrame` count), WebGL disabled (`webgl2` context forced null via devtools override) → static fluids. Screenshots to the user.
- [ ] **Step 6:** pint (lang files), commit `feat: zone map layers, temperature modes and hover card`.

### Task 10: Docs

**Files:**
- Modify: pages listed by `php artisan larecipe:affected --uncommitted --base=main` (expected: `product/colony`, `development/game-data-import`, `development/data-model`).

- [ ] **Step 1:** Run `php artisan larecipe:affected --uncommitted --base=main`.
- [ ] **Step 2:** Update each page with the `larecipe-update-docs` skill (common case first; the new export files, the render columns, the temperature modes, the WebGL fallback). Docs migrations only through the LaRecipe MCP author tools.
- [ ] **Step 3:** `php artisan larecipe:validate` — clean.
- [ ] **Step 4:** Show the user the docs diff and wait for approval (review mode `required`), then commit `docs: zone map rendering`.

---

Out of this plan (spec "Left to the builder" / later): Impeccable visual polish pass once the renderer works; critters on the map; zone borders.
