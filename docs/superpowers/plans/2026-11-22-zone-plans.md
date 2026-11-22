# Zone plans (planning mode v1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One proposed variant ("plan") per zone, written by Claude through the `oni` MCP, drawn on the zone page with an Actuel / Suggestion switch, editable by the player (move, delete), reconciled with the game's layout.

**Architecture:** A plan is a list of changes (add / remove / replace) stored per zone. A pure applier turns the zone's raw `layout` (same shape the mod sends: `buildings[]` with `anchorCell/prefabId/width/height/orientation/instanceId`, `tiles[]` with `cell/prefabId`) into a planned layout; everything that already draws a layout (`ZonePresenter::layoutMap`, `ZoneMapRenderer`) draws the planned one unchanged. Validation and reconciliation reuse the same applier.

**Tech Stack:** Laravel 12 / PHP 8.5, Postgres, laravel/mcp, spatie/laravel-data (+ TS transformer), Inertia v3 + Vue 3 TS, Wayfinder, Pest, Vitest (via vite-plus).

**Spec:** `docs/superpowers/specs/2026-11-22-zone-plans-design.md`

## Global Constraints

- One active plan per zone; a new write from Claude replaces it wholesale.
- Plans are user data: never truncated by `game:import`.
- Change kinds: `add`, `remove`, `replace` (= remove + add, one change). States: `pending`, `applied` (adds/replaces only), `conflicted`. Resolved removes are deleted.
- "Checked" layers = what the mod sends: buildings whose `object_layer` is `Building` (tiles included: `build_location_rule = 'Tile'` → go to `layout.tiles`). Any other `object_layer` (Wire, SolidConduit, LiquidConduit, GasConduit, LogicGate, Backwall…) → change stored with `checked = false`: drawn, never validated, never applied. Keep `object_layer` on each change.
- Validation of a checked add: same-layer overlap with buildings/tiles (planned layout minus the change itself), solid cells (slow state element `state = solid`, outside tiles), `permitted_rotations`, support for `OnFloor` (every cell under the footprint's bottom row is a tile or solid) and `OnCeiling` (every cell above the top row is a tile or solid). Other `build_location_rule` values: not checked.
- Validation context = real layout with the plan's other changes applied.
- Only adds move. Delete/move refused if it would leave another change overlapping.
- In-game chat: may read plans (`zone_plan`), never write (`write_zone_plan` denied in `config/ai.php`).
- Planned building instance ids in planned layouts: `plan:{changeId}`.
- UI copy in French through `lang/fr/zones.php` and the existing `t()` helper; Vue SFCs `lang="ts"`; `<!-- region -->` / `// region` markers; Wayfinder imports only (no URL literals).
- Run `vendor/bin/pint --dirty --format agent` after PHP changes; `bun run typecheck` and `bun run lint` after TS changes.
- Commits: no GPG signature, no Co-Authored-By trailer.

## Review Focus

- A plan written on a zone whose layout is null (layout not received yet) → MCP returns a clear error, nothing stored. Test in Task 4.
- A remove/replace targeting an instance id or cell that does not exist in the real layout → write refused naming the target. Test in Task 4.
- Rotated adds (R90/R270) → footprint width/height swapped before validation and drawing. Test in Task 2.
- A player moves an add outside the zone box or onto a solid cell → 422 with the reason, change untouched. Test in Task 6.
- The game sends a layout where an applied add's building disappeared → change back to pending (or conflicted if its spot is taken). Test in Task 3.

---

### Task 1: Storage — `ZonePlan` and `ZonePlanChange`

**Files:**
- Create: migration `create_zone_plans_tables` (via `php artisan make:migration --no-interaction`), `app/Models/ZonePlan.php`, `app/Models/ZonePlanChange.php`, `app/Enums/ZonePlanChangeKind.php`, `app/Enums/ZonePlanChangeState.php`, factories for both models
- Modify: `app/Models/Zone.php` (relation)
- Test: `tests/Feature/Models/ZonePlanTest.php`

**Interfaces:**
- Produces:
  - `zone_plans`: `id`, `zone_id` (FK cascade, unique), timestamps.
  - `zone_plan_changes`: `id`, `zone_plan_id` (FK cascade), `position` int, `kind` (enum), `state` (enum, default pending), `prefab_id` ?string, `anchor_cell` ?int, `orientation` string default `Neutral`, `object_layer` ?string, `checked` bool, `target_instance_id` ?string, `target_cell` ?int, `note` string, `moved_by_player` bool default false, timestamps.
  - `Zone::plan(): HasOne<ZonePlan>`; `ZonePlan::changes(): HasMany<ZonePlanChange>` ordered by `position`.
  - Enums `ZonePlanChangeKind::{Add, Remove, Replace}` (values `add|remove|replace`), `ZonePlanChangeState::{Pending, Applied, Conflicted}`; follow existing enum naming in `app/Enums`.
  - `ZonePlanChange::adds(): bool` (kind add or replace), `removes(): bool` (remove or replace).

- [ ] **Step 1:** Write test `it('deletes the plan and its changes with the zone')` and `it('keeps one plan per zone')` (second insert for the same zone throws `UniqueConstraintViolationException`).
- [ ] **Step 2:** Run `php artisan test --compact tests/Feature/Models/ZonePlanTest.php` → FAIL (table missing).
- [ ] **Step 3:** Create migration, models (`#[Fillable]` + `casts()` like `Zone`), enums, factories (`ZonePlanChangeFactory` states `add()`, `remove()`, `replace()`).
- [ ] **Step 4:** Run the test → PASS. Confirm `game:import` truncation list does not include the new tables (`rg -n "truncate" app/Console app/Services/GameImport`).
- [ ] **Step 5:** Pint, commit `feat: store zone plans`.

### Task 2: Applier and footprints — `ZonePlanLayout`

**Files:**
- Create: `app/Services/Zones/Plans/ZonePlanLayout.php`, `app/Services/Zones/Plans/PlanFootprint.php`
- Test: `tests/Unit/Services/ZonePlanLayoutTest.php` (Pest unit, no DB: pass changes as unsaved models or plain value objects)

**Interfaces:**
- Consumes: Task 1 models.
- Produces:
  - `PlanFootprint::size(int $width, int $height, string $orientation): array{0:int,1:int}` (swap for `R90`, `R270`).
  - `PlanFootprint::cells(int $anchorCell, int $width, int $height, int $gridWidth): list<int>` (delegate to `ZoneGeometry::footprint`).
  - `ZonePlanLayout::apply(array $layout, iterable<ZonePlanChange> $changes, array<string, array{width:int,height:int,isTile:bool}> $definitions, ?int $exceptChangeId = null): array` — returns a layout of the same shape: removes drop the targeted building (`instanceId`) or tile (`cell`); adds append `{instanceId: 'plan:{id}', anchorCell, prefabId, width, height, orientation}` to `buildings` or `{cell, prefabId, connections: 0}` to `tiles` when `isTile`; `applied` adds are skipped (already in the real layout); unchecked adds are appended too (drawn); `$exceptChangeId` leaves one change out (for validation).
  - `ZonePlanLayout::definitions(iterable<ZonePlanChange> $changes): array` — loads `Building` rows by `prefab_id` (width, height, `build_location_rule === 'Tile'`).

- [ ] **Step 1:** Tests: `it('swaps the footprint of a rotated building')` (`size(3,1,'R90') === [1,3]`); `it('removes a building by instance id and a tile by cell')`; `it('adds a planned building with a plan instance id')`; `it('adds a planned tile to tiles')`; `it('turns a mesh tile into a solid tile with a replace')` (tile at cell C: prefab `MeshTile` → `Tile`); `it('skips applied adds')`; `it('leaves out the excepted change')`.
- [ ] **Step 2:** Run `php artisan test --compact tests/Unit/Services/ZonePlanLayoutTest.php` → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run → PASS. Pint. Commit `feat: apply a zone plan to a layout`.

### Task 3: Placement checks and reconciliation

**Files:**
- Create: `app/Services/Zones/Plans/PlanPlacement.php`, `app/Actions/Zones/ReconcileZonePlan.php`, `app/Listeners/Zones/ReconcilePlanOnLayout.php`
- Modify: event registration for `ZoneLayoutStored` (same place `BroadcastZoneUpdated` is registered; reconcile must run before the broadcast or broadcast again after)
- Test: `tests/Feature/Zones/PlanPlacementTest.php`, `tests/Feature/Zones/ReconcileZonePlanTest.php`

**Interfaces:**
- Consumes: Task 2 `ZonePlanLayout`, `PlanFootprint`; `ZoneTerrainSummary::cellAt($slow, $x, $y)` + `Element.state` for solids.
- Produces:
  - `PlanPlacement::problem(Zone $zone, ZonePlan $plan, ZonePlanChange $change): ?string` — null when OK, otherwise an English sentence naming the cell `w(x,y)` and the blocker (`overlaps #123 FishFeeder`, `solid cell`, `rotation R90 not permitted for X`, `needs a floor under w(x,y)`, `needs a ceiling above w(x,y)`, `outside the zone box`). Skips unchecked changes and removes. Context: `ZonePlanLayout::apply($zone->layout, $plan->changes, $defs, exceptChangeId: $change->id)`.
  - `PlanPlacement::dependents(Zone $zone, ZonePlan $plan, ZonePlanChange $without): list<ZonePlanChange>` — adds that get a problem once `$without` is gone (used to refuse delete/move).
  - `ReconcileZonePlan::handle(Zone $zone): bool` (true when something changed). Rules: pending/conflicted add → `applied` when the real layout has a building/tile with the same prefab, same anchor cell, same orientation; applied add → `pending` when that match disappeared; any non-applied checked add → `conflicted` when `PlanPlacement::problem` returns non-null, else `pending`; remove → deleted when its target is absent from the real layout; replace → `applied` when its add matches and its target is gone. Deletes the plan when no change remains.

- [ ] **Step 1:** Placement tests (use `Zone::factory()->sodicaneFarm()` and real `Building` rows from factories): overlap, solid cell, unpermitted rotation, `OnFloor` floating, `OnCeiling` without ceiling, outside box, unchecked change never has a problem, add over a removed building is OK (validation context).
- [ ] **Step 2:** Reconcile tests: add marked applied when built; applied → pending when deconstructed; applied → conflicted when deconstructed and spot taken; pending → conflicted when something is built on its cells; remove dropped when target gone; replace applied; plan deleted when empty; listener runs on `ZoneLayoutStored` (dispatch through `StoreZoneLayout::handle`).
- [ ] **Step 3:** Run both files → FAIL.
- [ ] **Step 4:** Implement.
- [ ] **Step 5:** Run → PASS. Pint. Commit `feat: check plan placements and follow the game`.

### Task 4: MCP — `write_zone_plan`, `zone_plan`, `zone_map plan:true`

**Files:**
- Create: `app/Mcp/Tools/WriteZonePlanTool.php`, `app/Mcp/Tools/ZonePlanTool.php`, `app/Actions/Zones/WriteZonePlan.php`
- Modify: `app/Mcp/Servers/OniServer.php` (register both), `app/Mcp/Tools/ZoneMapTool.php` + `app/Services/Mcp/ZoneMapRenderer.php` (planned layout), `config/ai.php` (`denied_tools` += `'mcp__oni__write_zone_plan'`), server instructions text if it lists the flow
- Test: `tests/Feature/Mcp/WriteZonePlanToolTest.php`, `tests/Feature/Mcp/ZonePlanToolTest.php`, extend `tests/Feature/Mcp/ZoneMapToolTest.php`, `tests/Feature/Chat/…` existing config test if one asserts the deny list

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces:
  - `write_zone_plan` input: `zone`, `colony?`, `changes: list<{kind: add|remove|replace, prefab?: string, x?: int, y?: int (world, bottom-left), orientation?: string, target?: string ('#instanceId' or 'w(x,y)' for a tile cell), note: string}>`. Empty `changes` clears the plan. Prefab resolved by id (exact `building_id`). Writes atomically through `WriteZonePlan::handle(Zone $zone, array $changes): ZonePlan|array $errors` — validates every change in order against the plan so far (`PlanPlacement::problem`), refuses the whole write on the first errors (list them all, one line each with the change index), replaces the existing plan, runs `ReconcileZonePlan`, dispatches `ZoneUpdated($zoneGuid, $colonyGuid, 'plan')`.
  - Errors: zone without layout; unknown prefab; target not in the real layout; missing `x/y` for adds; placement problems.
  - `zone_plan` (`#[IsReadOnly]`): header + one line per change: `#id kind prefab @ w(x,y) orientation · state · checked|unchecked · moved by player? · "note"` and target for removes; "no plan" otherwise.
  - `zone_map` gains `plan: bool` (default false): renders the planned layout (`ZoneMapRenderer::render(..., ?array $layoutOverride)`), legend lines of planned instances show `plan#id` and their state, removed targets listed under `Removed by the plan:`.

- [ ] **Step 1:** Tests: writes a plan and reads it back via `zone_plan`; replaces the previous plan; empty list clears; refuses an overlapping add and stores nothing (`ZonePlan::count() === 0`); refuses an unknown target; refuses on a zone with null layout; accepts an unchecked `Wire` add without validation and marks it unchecked; `zone_map` with `plan: true` shows the planned glyph and `plan#`; config deny contains `mcp__oni__write_zone_plan` and allow still has `mcp__oni__*`.
- [ ] **Step 2:** Run the three files → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run → PASS; run the whole `tests/Feature/Mcp` folder → PASS. Pint. Commit `feat: write and read zone plans through the MCP`.

### Task 5: Zone page data — `plan`, `planMap`, live reload

**Files:**
- Create: `app/Data/ZonePlanData.php`, `app/Data/ZonePlanChangeData.php`
- Modify: `app/Data/ZoneShowData.php`, `app/Services/Zones/ZonePresenter.php` (`props()`, `PROPS_BY_KIND` + `'plan' => ['plan', 'planMap']`, `ALL_LIVE_PROPS`), TS types regeneration (project's existing command for `resources/js/types/App/Data/data.d.ts`)
- Test: extend `tests/Feature/Zones/ZoneControllerTest.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces:
  - `ZonePlanChangeData { id:int, kind:string, state:string, checked:bool, movedByPlayer:bool, note:string, prefabId:?string, name:?string, x:?int, y:?int, width:?int, height:?int, orientation:string, target:?{x:int,y:int,width:int,height:int,label:string}, movable:bool }` (`movable` = adds and unchecked adds, not removes/replaces).
  - `ZonePlanData { updatedAt: string, changes: list<ZonePlanChangeData> }`.
  - `ZoneShowData::$plan: ?ZonePlanData`, `ZoneShowData::$planMap: ?ZoneMapData` (`layoutMap(ZonePlanLayout::apply(...))`; planned buildings carry `instanceId 'plan:{id}'`, sprites resolved like real ones).

- [ ] **Step 1:** Tests: page has `plan = null`, `planMap = null` without a plan; with a plan, `planMap.buildings` contains `plan:{id}` with its sprite and lacks the removed instance; `liveProps.plan === ['plan','planMap']`.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS. Pint. Commit `feat: send the zone plan to the zone page`.

### Task 6: Player edits — move and delete endpoints

**Files:**
- Create: `app/Http/Controllers/ZonePlanChangeController.php`, `app/Http/Requests/MoveZonePlanChangeRequest.php`
- Modify: `routes/web.php` (`PATCH /zones/{zone}/plan/changes/{change}` → `zones.plan.changes.update`, `DELETE` same path → `zones.plan.changes.destroy`, scoped binding), Wayfinder regeneration
- Test: `tests/Feature/Zones/ZonePlanChangeControllerTest.php`

**Interfaces:**
- Consumes: Tasks 1–3, 5 (`ZoneUpdated` kind `plan`).
- Produces: `update(MoveZonePlanChangeRequest)` body `{x:int, y:int}` (world bottom-left) → sets `anchor_cell`, `moved_by_player = true`, validates with `PlanPlacement::problem` and `dependents`, returns `back()` or 422 `errors.position`; `destroy` → refuses with 422 `errors.change` when `dependents` is not empty, else deletes (and the plan when empty); both dispatch `ZoneUpdated(..., 'plan')`.

- [ ] **Step 1:** Tests: move an add → stored, `moved_by_player` true; move onto a solid cell → 422, unchanged; move outside the box → 422; move a remove → 422 (not movable); delete an add → gone; delete a remove another add depends on → 422; change of another zone → 404.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS. Pint. Commit `feat: move and delete plan changes from the zone page`.

### Task 7: Suggestion mode on the map

**Files:**
- Create: `resources/js/components/zones/zonePlan.ts` (pure helpers), `resources/js/components/zones/zonePlan.test.ts`, `resources/js/components/zones/ZonePlanPanel.vue` (list of changes with note, state badge, delete button)
- Modify: `resources/js/pages/Zones/Show.vue` (switch Actuel / Suggestion shown only when `plan` is not null; passes `planMap` instead of `map` and `plan` to `ZoneMap`), `resources/js/components/zones/ZoneMap.vue` (highlight planned entities `plan:*`, ghost removed footprints, note on hover/click, drag of movable changes), `lang/fr/zones.php`
- Test: `zonePlan.test.ts` (run with the project's Vitest through vite-plus, like the other `*.test.ts`)

**Interfaces:**
- Consumes: Task 5 types (`ZonePlanData`, `ZonePlanChangeData`), Task 6 Wayfinder actions `ZonePlanChangeController.update/destroy`.
- Produces (in `zonePlan.ts`):
  - `planChangeId(instanceId: string | null): number | null` (`'plan:12'` → 12).
  - `snapToCell(pointerX: number, pointerY: number, cellPx: number, box: ZoneBoxData): { x: number; y: number }` (world cell under the pointer, y grows upward like the map).
  - `removedFootprints(plan: ZonePlanData): Array<{ x: number; y: number; width: number; height: number; changeId: number }>`.

- [ ] **Step 1:** Vitest tests for the three helpers (including `snapToCell` y-flip at the box's top row).
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement helpers.
- [ ] **Step 4:** UI: switch (segmented control next to the layers bar, labels `Actuel` / `Suggestion`, remembered per zone in `localStorage` wrapped in try/catch); planned entities outlined (pending), green badge `appliqué`, red outline `en conflit`, dashed outline `non vérifié`; removed footprints drawn as ghosted hatched boxes; click on a change selects it and opens its note in `ZonePlanPanel`; drag a movable change → `router.patch` via Wayfinder on drop, snapped preview while dragging, error toast with the server message on 422; delete button in the panel → `router.delete`.
- [ ] **Step 5:** Run Vitest, `bun run typecheck`, `bun run lint` → PASS. Check in the browser (HMR dev server, or prod build + `octane:reload` in preview mode) on PacuOne with a plan written through the MCP; take a screenshot with Playwright.
- [ ] **Step 6:** Commit `feat: suggestion mode on the zone map`.

### Task 8: Docs

**Files:**
- Modify/Create via the LaRecipe MCP author tools: `product/colony` (or new `product/plans` page) and `development/mcp`

- [ ] **Step 1:** `php artisan larecipe:affected --uncommitted --base=main`; update listed pages and add the plan section with the `larecipe-update-docs` / `larecipe-write-docs` skills (Suggestion switch, move/delete, states; MCP tools `write_zone_plan`, `zone_plan`, `zone_map plan`; chat read-only). `sources:` frontmatter includes the new files.
- [ ] **Step 2:** `php artisan larecipe:validate` → no errors.
- [ ] **Step 3:** Show the docs diff to the player and wait for approval (review mode required), then commit `docs: zone plans`.
