# Colony MCP Server Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A local stdio MCP server `oni` inside the app that lets Claude Code explore the synced colony (sync state, zones, compact maps, cells, entities, game objects, captures) and request a capture.

**Architecture:** `laravel/mcp` server `App\Mcp\Servers\OniServer` registered with `Mcp::local` in `routes/ai.php`; one Tool class per tool under `app/Mcp/Tools/`; text formatting and spatial logic in small services under `app/Services/Mcp/` and `app/Services/Zones/`; logic currently private in `ZonePresenter` (problems, footprint) extracted so UI and MCP share it.

**Tech Stack:** PHP 8.5, Laravel 13, laravel/mcp ^1.2 (skill `mcp-development`), Pest, Postgres.

**Spec:** `docs/superpowers/specs/2026-11-14-colony-mcp-design.md`

## Global Constraints

- Activate the `mcp-development` skill and use `search-docs` (package `laravel/mcp`) before writing MCP primitives; create them with `php artisan make:mcp-server` / `make:mcp-tool --no-interaction`.
- Local stdio only: `Mcp::local('oni', OniServer::class)`; never run `mcp:start` by hand (it hangs).
- No colony / zone state in statics, singletons or tool properties between calls (long-lived process); read the DB every call. `GameText` stays a scoped binding.
- Text output, not JSON dumps; never return raw `slow_state` / `layout` arrays.
- Derived values marked `derived`; missing values printed `unavailable`, never guessed.
- Every tool response starts with the header line from `McpHeader` (Task 1).
- Units: kg, °C (K − 273.15, one decimal), seconds / cycles (600 s per cycle).
- Coordinates: world `x = cell % gridWidth`, `y = intdiv(cell, gridWidth)`, y grows upward; lists print `w(x,y)`.
- Images only on explicit request (`image: true` / a capture id).
- Read tools carry `#[IsReadOnly]`; `request_capture` does not.
- UI behaviour unchanged: `tests/Unit/Services/ZonePresenterTest.php`, `tests/Feature/Zones/*`, `tests/Feature/ZoneCaptureControllerTest.php` stay green.
- PHP rules: `declare(strict_types=1)`, `final` classes, typed signatures, PHPDoc array shapes, curly braces; `vendor/bin/pint --dirty --format agent` before each commit.
- Commits: no GPG signature (`--no-gpg-sign`), no `Co-Authored-By`.
- Tests: Pest, factories, `OniServer::tool(Tool::class, [...])->assertOk()->assertSee(...)`; read the `testing-best-practices` skill first.

## Review Focus

1. Zone with `layout` / `slow_state` / `fast_state` null (just created, never synced) → tools print `unavailable` sections, never crash. Test in Task 4 (`zone_overview`) and Task 5 (`zone_map`).
2. Zone name ambiguous or unknown (`"AquaFarm"` matches 3 zones) → error listing the candidates, no arbitrary pick. Test in Task 3.
3. No colony at all (fresh install) → every tool returns a clear `no colony synced yet` error. Test in Task 1.
4. Box larger than the map cap (zone 65, 371 cells over a wide box) → summary + request for a `rect`, not a 2 000-char grid. Test in Task 5.
5. Capture row whose file is missing on disk (pruned / moved) → error naming the capture, not an exception. Test in Task 9.

---

### Task 1: Server skeleton, colony resolution, header, `colony_status`

**Files:**
- Create: `routes/ai.php`, `app/Mcp/Servers/OniServer.php`, `app/Mcp/Tools/ColonyStatusTool.php`, `app/Services/Mcp/McpColony.php`, `app/Services/Mcp/McpHeader.php`
- Modify: `.mcp.json` (add `"oni": {"command": "php", "args": ["artisan", "mcp:start", "oni"]}`)
- Test: `tests/Feature/Mcp/ColonyStatusToolTest.php`

**Interfaces:**
- Produces:
  - `McpColony::resolve(?string $guid): ?Colony` — given guid, or the colony with latest `last_seen_at`.
  - `McpHeader::line(Colony $colony, ?Zone $zone = null): string` — e.g. `Colony "Nova" cycle 59 · seen 4 s ago · zone fast 4 s, slow 22 s, layout 3 min` plus ` · STALE` when `last_seen_at` is older than `McpHeader::STALE_SECONDS = 60`; zone ages printed only when `$zone` given, `never` for a null timestamp.
  - `McpHeader::OFFLINE_SECONDS = 30` (used by Task 9).
  - `OniServer` with `$name = 'oni'`, `$instructions` text covering: tool flow (`colony_status` → `list_zones` → `zone_overview` → `zone_map` → `entity` / `lookup` → `zone_captures`), coordinates (world `w(x,y)`, y up, maps local with origin in header), units, glyph scheme summary (two grids T/O, legend per call), `derived` / `unavailable` meaning.
  - Every tool accepts optional `colony` (string guid) in its schema.

- [ ] **Step 1: Write the failing tests**

```php
it('reports sync health and colony summary', function (): void {
    $colony = Colony::factory()->create(['name' => 'Nova', 'cycle' => 59, 'last_seen_at' => now()->subSeconds(4), 'state' => [
        'schema' => 1, 'duplicants' => [[], [], []],
        'critters' => [['id' => 'Crab', 'worldId' => 0, 'cell' => 1], ['id' => 'Crab', 'worldId' => 0, 'cell' => 2]],
    ]]);
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);
    UplinkCommand::factory()->create(['colony_guid' => $colony->guid, 'status' => UplinkCommand::PENDING, 'expires_at' => now()->addMinute()]);

    OniServer::tool(ColonyStatusTool::class)->assertOk()
        ->assertSee('Colony "Nova" cycle 59')
        ->assertSee('seen 4 s ago')
        ->assertSee('Sodicane')
        ->assertSee('duplicants 3')
        ->assertSee('Crab 2')
        ->assertSee('commands pending 1');
});

it('flags a stale colony', function (): void {
    Colony::factory()->create(['last_seen_at' => now()->subMinutes(5)]);
    OniServer::tool(ColonyStatusTool::class)->assertOk()->assertSee('STALE');
});

it('says when no colony was synced', function (): void {
    OniServer::tool(ColonyStatusTool::class)->assertHasErrors(['No colony synced yet']);
});
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Mcp/ColonyStatusToolTest.php` — Expected: FAIL (classes missing).
- [ ] **Step 3: Implement** server, `routes/ai.php`, `McpColony`, `McpHeader`, `ColonyStatusTool` (`#[IsReadOnly]`). Status body: session (latest `UplinkSession` of the colony: started, cycle at load), one line per active zone with its three ages, counts of `UplinkCommand` by status (call `UplinkCommand::expireStale()` first), duplicants count, critters by species (`state.critters[].id`, localised via `GameText::name` with id in parentheses), plants by species (`state.plants[].id`), food totals (`state.food[]`: units, kcal = calories/1000). Missing state sections print `unavailable`.
- [ ] **Step 4: Run** the test file — Expected: PASS.
- [ ] **Step 5: Commit** `feat: oni MCP server with colony_status`.

---

### Task 2: Extract zone problems and footprint from `ZonePresenter`

**Files:**
- Create: `app/Services/Zones/ZoneProblems.php`, `app/Services/Zones/ZoneGeometry.php`
- Modify: `app/Services/Zones/ZonePresenter.php` (delegate `plantProblems`, `buildingProblems`, `problemSummary`, `splitPassages` use stays; `occupants` footprint loop uses `ZoneGeometry::footprint`)
- Test: `tests/Unit/Services/Zones/ZoneGeometryTest.php`; existing `tests/Unit/Services/ZonePresenterTest.php`, `tests/Feature/Zones/ZoneControllerTest.php` must stay green unchanged.

**Interfaces:**
- Produces:
  - `ZoneProblems::forPlant(array $plant): list<string>`; `ZoneProblems::forBuilding(array $building): list<string>` (same rules and `PROBLEM_STATUS_IDS` moved from `ZonePresenter`); `ZoneProblems::summary(Zone $zone): array{count: int|null, partial: bool}`.
  - `ZonePresenter::problemSummary()` kept as a thin delegate (the controller calls it).
  - `ZoneGeometry::footprint(int $anchorCell, int $width, int $height, int $gridWidth): list<int>` — anchor = bottom-left, fills upward (exact current `occupants` rule).
  - `ZoneGeometry::xy(int $cell, int $gridWidth): array{0: int, 1: int}`; `ZoneGeometry::cell(int $x, int $y, int $gridWidth): int`.

- [ ] **Step 1: Write failing test** `it('fills a footprint upward from the bottom-left anchor')`: `footprint(cell(114,201), 2, 2, 606)` equals `[cell(114,201), cell(115,201), cell(114,202), cell(115,202)]`; `xy(201*606+114, 606) === [114, 201]`.
- [ ] **Step 2: Run** it — FAIL.
- [ ] **Step 3: Implement** both classes; move code, no behaviour change. `ZoneProblems` takes `GameText` only if needed (it does not today: problems use `__()` and status names).
- [ ] **Step 4: Run** `php artisan test --compact tests/Unit/Services tests/Feature/Zones tests/Feature/ZoneCaptureControllerTest.php` — PASS.
- [ ] **Step 5: Commit** `refactor: share zone problem and footprint rules`.

---

### Task 3: Zone resolution and `list_zones`

**Files:**
- Create: `app/Services/Mcp/McpZones.php`, `app/Mcp/Tools/ListZonesTool.php`
- Test: `tests/Feature/Mcp/ListZonesToolTest.php`

**Interfaces:**
- Consumes: `McpColony`, `McpHeader`, `ZoneProblems::summary`.
- Produces: `McpZones::resolve(Colony $colony, string $zone): Zone` — numeric → id within the colony; else case-insensitive exact name, then unique case-insensitive substring; archived excluded; throws `App\Services\Mcp\McpLookupException` (message lists up to 10 candidates `id name`) on none / several. Tools catch it and return `Response::error($e->getMessage())`.

- [ ] **Step 1: Failing tests:**
  - `it('lists active zones with size, age and problems')`: sodicane zone named `PlantFarm/Sadicane` → sees `PlantFarm/Sadicane`, `40 cells`, `box 22x4 @ w(99,200)`, `problems 1`; archived zone not listed.
  - `it('resolves a zone by id, exact name and unique fragment')` on `McpZones` directly.
  - `it('refuses an ambiguous zone name and lists candidates')`: zones `AquaFarm/PacuOne`, `AquaFarm/PacuTwo`; `resolve($colony, 'aquafarm')` throws with both names in the message.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.** List line: `#id name · world N · cells N · box WxH @ w(x,y) · age … · problems N|unavailable · captures N` (+ `orphaned`). Read only list columns plus `layout->box` (select `layout` is acceptable; layouts are ≤ 10 KB).
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: list_zones MCP tool`.

---

### Task 4: Critters in a zone and `zone_overview`

**Files:**
- Create: `app/Services/Zones/ZoneCritters.php`, `app/Services/Mcp/ZoneTerrainSummary.php`, `app/Mcp/Tools/ZoneOverviewTool.php`
- Test: `tests/Unit/Services/Zones/ZoneCrittersTest.php`, `tests/Feature/Mcp/ZoneOverviewToolTest.php`

**Interfaces:**
- Consumes: `McpZones::resolve`, `ZoneProblems`, `McpHeader`, `GameText`.
- Produces:
  - `ZoneCritters::in(Zone $zone, Colony $colony): list<array<string, mixed>>` — colony `state.critters` whose `cell` ∈ latest `ZoneVersion.cells` (fallback `layout.cells`) and `worldId === $zone->world_id`; empty when either source missing.
  - `ZoneTerrainSummary::byElement(array $slowState): list<array{elementId: string, cells: int, mass: float, minC: float, maxC: float}>` sorted by cells desc; `ZoneTerrainSummary::cellAt(array $slowState, int $x, int $y): ?array{elementId: string, mass: float, celsius: float}` (world coords, row-major from box bottom-left).

- [ ] **Step 1: Failing tests:**
  - `ZoneCrittersTest`: zone with version cells `[10, 11]`, world 0; colony critters at cells 10 (world 0), 11 (world 1), 99 (world 0) → only the first.
  - `ZoneOverviewToolTest` on `sodicaneFarm()` + colony with one critter on a zone cell: sees `FarmStation` with `not operational`/status `Insufficient Resources: Fertilizer`, `SaltySticksPlant ×13`, `1 ready`, `Oxygen`, `Dirt`, `22.0 °C` (295.15 K), the critter line with `(derived)`, the app URL `route('zones.show', $zone)`.
  - `it('prints unavailable sections for a zone never synced')`: `Zone::factory()->create()` (no states) → assertOk, sees `layout unavailable`, `slow state unavailable`, `fast state unavailable`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.** Sections in order: header, `box / version / cells`, buildings grouped by prefab (`name (Prefab) ×n · operational a/n · active b/n · status …`, storage `Fertilizer 0 kg`), plants grouped (`×n · growth avg % · ready n · wilting n · next harvest in s`), critters (`species @ w(x,y) tame|wild age a/max cal c/max (derived)`), elements (`ZoneTerrainSummary`), problems (from `ZoneProblems`), pointers (`zone_map`, captures count, URL). Growth: reuse the `ZonePresenter::growth` formula — move it into `ZoneProblems`' sibling or a static `ZonePlants::growth(array $plant): ?float` if needed, keep UI delegate.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: zone_overview MCP tool with derived critters`.

---

### Task 5: ASCII map renderer and `zone_map`

**Files:**
- Create: `app/Services/Mcp/ZoneMapRenderer.php`, `app/Services/Mcp/MapGlyphs.php`, `app/Mcp/Tools/ZoneMapTool.php`
- Test: `tests/Unit/Services/Mcp/ZoneMapRendererTest.php`, `tests/Feature/Mcp/ZoneMapToolTest.php`

**Interfaces:**
- Consumes: `ZoneGeometry`, `ZoneCritters::in`, `ZoneTerrainSummary::cellAt`, `McpZones::resolve`.
- Produces:
  - `MapGlyphs::terrain(?string $elementId, ?string $state, ?string $tilePrefab): string` — fixed table: tile prefabs `Tile`→`#`, `MeshTile`→`=`, `GasPermeableMembrane`→`=`, other tile prefabs → `+`; gases `Oxygen`→`.`, `ContaminatedOxygen`→`,`, `CarbonDioxide`→`_`, `Hydrogen`→`h`, `ChlorineGas`→`c`, other gas → `g`, `Vacuum`→`'`; liquids `Water`→`~`, `DirtyWater`→`%`, `SaltWater`→`s`, other liquid → `l`; solids: stable glyph from `MapGlyphs::SOLIDS` (a fixed `elementId => char` table for the common solids using digits / symbols such as `Dirt`→`d`... builder fills it), unknown solid → `*` and listed by id in the legend.
  - `MapGlyphs::building(string $prefabId): string` — stable colony-wide: uppercase letter from a fixed `prefab => letter` table for frequent prefabs, otherwise the first free uppercase letter in alphabetical prefab order **per call**, flagged `(local)` in the legend. Critters / plants: lowercase first letter of the prefab id, plants prefixed in legend.
  - `ZoneMapRenderer::render(Zone $zone, Colony $colony, ?array $rect, array $layers): string` where `$rect = array{x: int, y: int, width: int, height: int}` in world coords and `$layers ⊆ ['terrain', 'occupancy']`.
  - `ZoneMapRenderer::MAX_WIDTH = 40`, `MAX_HEIGHT = 25`.
- Output format (exact shape):

```
Zone #71 "CritterFarm/Carapoke" v3 · origin w(111,210) · 16x9 · y up · local coords
     0123456789012345
T  4 |::_._##__#_=_:::|
O  4 |.....RR..D...GGG|
... (terrain rows top to bottom, then occupancy rows top to bottom)
Terrain: # Tile  = MeshTile  . Oxygen  _ CarbonDioxide  : border (outside zone)  ' ' outside box
Occupancy:
 R RanchStation#520375 w(116,212) 2x3 operational
 p Crab @ w(121,213) tame (derived)
```

  Border = box cell not in zone cells. In T, a border cell holding gas or liquid prints `:`; a border cell with a tile or a natural solid prints its normal glyph (walls matter). In O, buildings on the border (doors, ladders) print normally. Column ruler: units digits, plus a tens line when width > 10.

- [ ] **Step 1: Failing renderer tests** on `sodicaneFarm()` (box 22x4 @ (99,200)):
  - FarmTile row y=0 (world 200) shows `+` for FarmTile x=100..112 local 1..13 and `#` for Tile local 14..20.
  - FarmStation occupancy: letter at local (15,1),(16,1),(15,2),(16,2) — footprint from bottom-left anchor (114,201).
  - 13 plant glyphs on local row 1, x 1..13 in O grid.
  - legend contains `FarmStation#7001 w(114,201) 2x2`.
  - `it('asks for a rect when the box exceeds 40x25')`: zone with box 60x10 → output contains `too large` and `rect`, no `T ` rows.
  - `it('renders only the requested rect')`.
  - `it('says unavailable when layout is missing')`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement renderer + tool.** Tool schema: `zone` (required string), `rect` (optional object x,y,width,height world), `layers` (optional array, default both), `colony`.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Manual check** (not a test): `php artisan tinker --execute 'echo app(App\Services\Mcp\ZoneMapRenderer::class)->render(App\Models\Zone::find(71), App\Models\Colony::first(), null, ["terrain","occupancy"]);'` — RanchStation at local x 5-6, AirFilter (11,5), Stairs (13,3)/(12,2), WaterPurifier right of the mesh tile (compare with the zone 71 capture).
- [ ] **Step 6: Commit** `feat: zone_map MCP tool`.

---

### Task 6: `zone_cells`

**Files:**
- Create: `app/Mcp/Tools/ZoneCellsTool.php`
- Test: `tests/Feature/Mcp/ZoneCellsToolTest.php`

**Interfaces:**
- Consumes: `ZoneTerrainSummary::cellAt`, `ZoneGeometry`, `McpZones`.
- Schema: `zone` (required), `rect` (required object, world coords), `colony`. Cap `MAX_CELLS = 100`.

- [ ] **Step 1: Failing tests** on `sodicaneFarm()`: rect `{x:114,y:201,width:2,height:1}` → two lines `w(114,201) Oxygen 1.5 kg 22.0 °C FarmStation#7001 zone` and `w(115,201) …`; rect 20x10 → error containing `100`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** Line: `w(x,y) Element mass kg °C [tile] [Prefab#id] zone|border|outside`. **Step 4: Run** — PASS. **Step 5: Commit** `feat: zone_cells MCP tool`.

---

### Task 7: `entity`

**Files:**
- Create: `app/Mcp/Tools/EntityTool.php`
- Test: `tests/Feature/Mcp/EntityToolTest.php`

**Interfaces:**
- Consumes: `McpColony`, `ZoneGeometry`, `ZoneProblems`, `GameText`.
- Schema: `instanceId` (required string), `colony`. Searches active zones of the colony: `fast_state.plants[]`, `fast_state.buildings[]` (state) joined with `layout.buildings[]` (anchor, size, orientation) by `instanceId`. Critters have no id in v1: not searched; the not-found message says so.

- [ ] **Step 1: Failing tests:** `sodicaneFarm()` → `instanceId: '7001'` sees `FarmStation`, `w(114,201)`, `2x2`, `MaterialsUnavailable`, `Fertilizer 0 kg`, `zones: #<id> Ferme sodicane`, `lookup FarmStation`; `'5100'` sees `SaltySticksPlant`, growth, `next harvest`; `'nope'` → error mentioning `critters have no id yet`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement** (print every field the mod sent, keys as-is, values formatted). **Step 4: Run** — PASS. **Step 5: Commit** `feat: entity MCP tool`.

---

### Task 8: `lookup`

**Files:**
- Create: `app/Services/Mcp/GameObjectSearch.php`, `app/Mcp/Tools/LookupTool.php`
- Test: `tests/Feature/Mcp/LookupToolTest.php`

**Interfaces:**
- Consumes: `GameText`, models `Element`, `Building`, `Critter`/`CritterMorph`, `Plant`/`PlantVariant`, `Food`, `CodexEntry`, `PrefabName`; `config('game.assets_path')`.
- Produces: `GameObjectSearch::search(string $query, ?string $kind): list<array{kind: string, id: string, nameFr: string, nameEn: string}>` — matches id (case-insensitive, exact first then prefix) and names in **both** `fr` and `en` (`Lang::get($nameKey, [], $locale)` over `PrefabName`, plus `Element.name`, `CodexEntry.title`); `kind ∈ element|building|critter|plant|food|codex`; max 20 results.
- Sprite path by kind: `elements/`, `buildings/`, `critters/`, `plants/`, `foods/` + `{id}.webp` under `game.assets_path`; codex: none.
- Schema: `query` (required), `kind` (optional enum), `image` (optional bool, default false), `colony` not needed.

- [ ] **Step 1: Failing tests** (factories `Element`, `Building`, `Critter` + `CritterMorph` + diet/output, `PrefabName`, translation lines registered with `Lang::addLines` for `fr` and `en`):
  - `query: 'Oxygen'` one match → detail (state, thermal props, phase changes) and `sprite: available|missing`.
  - French name query (`'Oxygène'`) → same element.
  - several matches → list `kind id · FR / EN`.
  - building detail: size `WxH`, materials, power, heat.
  - critter detail: morphs, diets, outputs ids.
  - `image: true` with a sprite file present (write a tiny WebP to a temp `game.assets_path` set via `config([...])`) → response contains image content of mime `image/webp` (assert with the test response's content helpers; check `TestResponse` API in vendor).
  - no match → error `No game object matches`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** Detail returns related ids (materials, diets, crops, codex) so the next `lookup` is obvious. **Step 4: Run** — PASS. **Step 5: Commit** `feat: lookup MCP tool`.

---

### Task 9: `zone_captures` and `request_capture`

**Files:**
- Create: `app/Mcp/Tools/ZoneCapturesTool.php`, `app/Mcp/Tools/RequestCaptureTool.php`
- Test: `tests/Feature/Mcp/ZoneCapturesToolTest.php`, `tests/Feature/Mcp/RequestCaptureToolTest.php`

**Interfaces:**
- Consumes: `McpZones`, `McpHeader::OFFLINE_SECONDS`, `SendUplinkCommand::handle($colonyGuid, 'zone.capture', ['zoneId' => $zone->zone_guid])`, `ZoneCapture` (newest first via `$zone->captures()`), `Response::fromStorage($path, disk: 'public')`.
- `zone_captures` schema: `zone` (required), `capture` (optional: numeric id, `latest`, `previous`), `colony`. Without `capture`: list lines `#id taken_at · cycle N · trigger · WxH · layout changed since: yes|no` (compare `layout_signature` with `zone.layout.signature`) + latest `zone.capture` command status + app URL. With `capture`: header text + the image (return both contents if the API allows an array of responses; otherwise image only, with the header in the next list call).
- `request_capture` schema: `zone` (required), `colony`. Returns `command <uuid> pending`; adds `game looks offline (last seen N s ago), the command will likely expire` when `last_seen_at` older than 30 s. Archived zone → error (resolution already excludes it).

- [ ] **Step 1: Failing tests:**
  - list: two captures → both ids, `cycle 59` (game_seconds 35 400), `layout changed since: no` when signatures match.
  - image: `Storage::fake('public')`, put a WebP at the capture path → image content `image/webp`.
  - missing file → error containing `#<id>` and `file missing`.
  - request: creates one `UplinkCommand` type `zone.capture` payload `['zoneId' => zone_guid]`; output contains its id and `pending`.
  - request with `last_seen_at` 2 min ago → contains `looks offline`; command still created.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement** (`RequestCaptureTool` without `#[IsReadOnly]`; `#[IsIdempotent(false)]`). **Step 4: Run** — PASS. **Step 5: Commit** `feat: zone_captures and request_capture MCP tools`.

---

### Task 10: Docs page, end-to-end check

**Files:**
- Create (via LaRecipe MCP `write_page`): `docs/site/development/mcp.md` (French), navigation entry via `update_navigation`.
- Test: `php artisan larecipe:validate`.

- [ ] **Step 1:** Activate `larecipe-write-docs`. Page in French, people-first: what `oni` is, how it starts (`.mcp.json`, `<Artisan command="mcp:start">`), the tool list one line each, then in an `<Accordion>`: coordinates, map glyphs and grids, `derived` / `unavailable`, limits. `sources:` = `app/Mcp/**`, `app/Services/Mcp/**`, `app/Services/Zones/ZoneCritters.php`, `app/Services/Zones/ZoneGeometry.php`, `app/Services/Zones/ZoneProblems.php`, `routes/ai.php`. No config values.
- [ ] **Step 2:** `php artisan larecipe:validate` — no errors. `php artisan larecipe:affected --uncommitted --base=main` — update any other listed page (`development/architecture` likely) with `larecipe-update-docs`.
- [ ] **Step 3:** Show the user the docs diff; wait for approval (review mode `required`).
- [ ] **Step 4:** Full suite: `php artisan test --compact` — PASS. `vendor/bin/pint --dirty --format agent`.
- [ ] **Step 5:** Ask the user to restart Claude Code so `oni` connects; then run `list_zones`, `zone_overview 66`, `zone_map 71`, `zone_captures 71 latest`, `lookup Oxygène` live and compare `zone_map 71` with the capture.
- [ ] **Step 6: Commit** `docs: oni MCP server` (docs page + docs migration together).
