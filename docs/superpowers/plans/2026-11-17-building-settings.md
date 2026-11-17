# Building Settings and Stocks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Read what the player set on ranching buildings (feeder filters and capacity, critter drop-off filter and limit) and turn it into advice: low food stock, a feeder that refuses every food a flock eats, foods the flock could eat if ticked, and a drop-off that will overfill its room.

**Architecture:** The Uplink mod adds an optional `settings` object to each building of the fast `buildings` section (generic: any `TreeFilterable` and any `IUserControlledCapacity`). The site parses it once (`ZoneBuildingSettings`), `ZoneFeeding` uses the feeder filters, and a new `RanchProblems` turns stock, filters and drop-off limits into zone problems. The Entities tab and the MCP show the settings.

**Tech Stack:** C# (Uplink mod, net48 + Uplink.Core net8 tests), Laravel 13 / PHP 8.5, spatie/laravel-data, Pest, Vue 3 + Inertia v3, Vitest.

**Spec:** roadmap step 3 in memory `project-zones-roadmap` (« filtres du distributeur, stock, points de dépôt critters/œufs ; alerte algue basse, nourriture alternative »). The user skipped the design questions on 2026-11-17; the decisions below are the planner's calls, listed so they can be checked.

## Decisions (planner's calls)

- **Game facts** (decompiled 2026-11-17): `CreatureFeeder` / `FishFeeder` have a `Storage` (2000 kg / 2×200 kg), a `TreeFilterable` (`AcceptedTags`) and a `StorageLocker` (`IUserControlledCapacity.UserMaxCapacity`, capped at `capacityKg`). `CritterDropOff` / `CritterPickUp` / `FishDeliveryPoint` have a `TreeFilterable` (critter prefab tags) and a `BaggableCritterCapacityTracker` (`creatureLimit`, `storedCreatureCount`, `filteredCount`). Eggs have no drop-off building: an egg "deposit" is a storage whose filter accepts eggs, and the generic filter covers it.
- **Settings ride the fast `buildings` section** (already carries `storage`); no new section, no schema bump.
- **Low stock threshold:** `RanchProblems::LOW_AUTONOMY_CYCLES = 3.0` — a flock with a deficit and fewer than 3 cycles of stock gets a problem. Derived.
- **Alternative food:** item foods of the flock's diet that no feeder in the zone accepts. Shown in the flock feeding block, never as a problem.
- **Drop-off over capacity:** only when the zone has exactly one crowding region of the drop-off's kind (`cavity` for `CritterDropOff`, `pond` for `FishDeliveryPoint`); otherwise skipped (no guess about which room). Derived.
- **Capacity below one cycle of need:** not a problem in this plan (YAGNI); the capacity is shown.

## Global Constraints

- Branch `feat/building-settings` in both repos (already created). Commits without Co-Authored-By and without GPG signature (`git -c commit.gpgsign=false commit`).
- Never `npm`/`npx`: `bun`, `bunx`. PHP: `vendor/bin/pint --dirty --format agent` before each commit touching PHP. `php artisan make:*` for new classes, `--no-interaction`.
- Vue SFCs use `<script setup lang="ts">`. Sections marked `<!-- region name -->` / `<!-- endregion -->` in templates and `// region name` in scripts.
- No hand-built URLs: Wayfinder imports. Item and critter names come from the DB / `GameText`, never hand-written.
- Additive optional field: no `schema` bump. Absent `settings` (older mod) means unknown, `null` filter means the building has no filter.
- Anything computed by the planner is marked `derived` (Data `derived: bool`, French "(dérivé)", MCP "(derived)"). Engine constants are named constants in the class that applies them.
- After Uplink ingest PHP changes: `php artisan queue:restart`. After a mod change: `./build-and-install.sh` in `~/dev/uplink`, then tell the user to reload the game. Don't ask before deploying.
- Docs ship with the work: `php artisan larecipe:affected --uncommitted --base=main`, `larecipe-update-docs` skill, `php artisan larecipe:validate`. Review mode required: show the docs diff before committing it.

## Wire format

Each item of the fast `buildings` array gains:

```json
"settings": {
  "filter": ["Algae", "Kelp"],
  "capacity": {"limit": 500.0, "max": 2000.0, "stored": 340.0, "unit": "kg"}
}
```

- `filter`: `TreeFilterable.AcceptedTags` names, sorted ordinal; `null` when the building has no `TreeFilterable`; `[]` when nothing is ticked.
- `capacity`: `null` without an `IUserControlledCapacity`. `unit` is `"kg"` (`StorageLocker`) or `"critters"` (`BaggableCritterCapacityTracker`: `limit` = `creatureLimit`, `max` = `MaxCapacity`, `stored` = `storedCreatureCount`). Non-finite numbers → `null` (`Num.OrNull`); `limit` = +∞ (never set) → `null`.
- `settings` is written only when one of the two is non-null.

## Review Focus

1. **Feeder with nothing ticked.** `filter: []` must read as "refuses every food", not as "unknown". Test in Task 4.
2. **Two feeders, one filtered, one not.** A flock is blocked only when *no* feeder of the zone accepts any of its item foods. Test in Task 3.
3. **Older mod without `settings`.** No filter problem, no alternative food line, no crash; low stock still works (it only needs storage). Tests in Tasks 2 and 4.
4. **Drop-off in a zone with two cavities.** Skipped, no problem. Test in Task 4.
5. **Grazers and hunters** (no item food). Never "blocked by filter", never an alternative food. Test in Task 3.

---

### Task 1: Mod sends building settings

**Files (in `~/dev/uplink`):**
- Create: `Uplink.Core/Zones/BuildingSettings.cs`
- Modify: `Uplink/Zones/ZoneReaders.cs` (`BuildingState`)
- Test: `Uplink.Core.Tests/BuildingSettingsTests.cs`

**Interfaces:**
- Produces: `public static JObject BuildingSettings.Write(IEnumerable<string> acceptedTags /* null = no filter */, double? limit, double? max, double? stored, string unit /* "kg"|"critters"|null */)` → the `settings` object, or `null` when both parts are absent.

- [ ] **Step 1: Failing tests** in `BuildingSettingsTests`: `Filter_IsSortedOrdinal` (`["Kelp","Algae"]` → `["Algae","Kelp"]`); `EmptyFilter_StaysEmptyArray`; `NoFilterAndNoCapacity_IsNull`; `InfiniteLimit_IsNull` (`limit = double.PositiveInfinity` → `capacity.limit` is JSON null, `max` kept); `CrittersUnit_IsKept`.
- [ ] **Step 2: Run** `cd ~/dev/uplink && dotnet test Uplink.Core.Tests --filter BuildingSettings` → FAIL (type missing).
- [ ] **Step 3: Implement** `BuildingSettings.Write` (use `Num.OrNull` for numbers). In `ZoneReaders.BuildingState`, add `["settings"] = Settings(go, skip)`: `TreeFilterable.AcceptedTags` (tag `.Name`), then the first `IUserControlledCapacity` component — `BaggableCritterCapacityTracker` gives unit `"critters"` with `stored = storedCreatureCount`, any other gives `"kg"` with `stored = AmountStored`. Wrap in try/catch → `skip(go.name + " settings", e)`, null.
- [ ] **Step 4: Run** `dotnet test Uplink.Core.Tests` → all PASS. `./build-and-install.sh`.
- [ ] **Step 5: Verify in game** (tell the user to reload): `/colony/uplink` inspector shows `settings` on the feeders of PacuOne and on a drop-off. Note one real payload in the commit body.
- [ ] **Step 6: Commit** in `~/dev/uplink`: `feat: send building filters and capacity`.

### Task 2: Site parses building settings

**Files:**
- Create: `app/Data/BuildingSettingsData.php`, `app/Data/BuildingCapacityData.php`, `app/Services/Zones/ZoneBuildingSettings.php`
- Test: `tests/Unit/Services/Zones/ZoneBuildingSettingsTest.php`

**Interfaces:**
- Produces: `BuildingSettingsData(?array $filter /* list<string>|null */, ?BuildingCapacityData $capacity)`; `BuildingCapacityData(?float $limit, ?float $max, ?float $stored, string $unit /* 'kg'|'critters' */)`; `ZoneBuildingSettings::of(array $building): ?BuildingSettingsData` (null when `settings` absent or malformed); `ZoneBuildingSettings::feederFilters(Zone $zone): ?array` → `array<string instanceId, list<string>|null>` for the `ZoneInventory::FEEDER_PREFABS` buildings, `null` when no feeder carries `settings` (older mod).

- [ ] **Step 1: Failing tests**: `it parses filter and capacity`; `it keeps an empty filter as empty`; `it returns null without settings`; `it drops a capacity with an unknown unit`; `it maps feeder filters by instance and ignores other buildings`; `it returns null feeder filters for an older mod`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Unit/Services/Zones/ZoneBuildingSettingsTest.php` → FAIL.
- [ ] **Step 3: Implement** (read `fast_state['buildings']` like `ZoneInventory::of`). `php artisan typescript:transform`.
- [ ] **Step 4: Run** the test → PASS. Pint.
- [ ] **Step 5: Commit**: `feat: parse building filters and capacity`.

### Task 3: Feeding follows the feeder filters

**Files:**
- Modify: `app/Services/Zones/ZoneFeeding.php`, `app/Data/ZoneFeedingData.php`, `resources/js/components/zones/ZoneFlock.vue`, `resources/js/components/zones/zoneFlock.ts`, `lang/fr/zones.php` (`flock.feeding`)
- Test: `tests/Feature/Zones/ZoneFeedingTest.php` (extend the existing feeding test file; create it with `php artisan make:test --pest Zones/ZoneFeedingTest` if none), `resources/js/components/zones/zoneFlock.test.ts`

**Interfaces:**
- Consumes: `ZoneBuildingSettings::feederFilters(Zone): ?array` (Task 2).
- Produces: on `ZoneFeedingData`, `public ?bool $blockedByFilter = null` (true: the zone has feeders with known filters and none accepts any of the group's item foods; null: unknown or the group eats no item food) and `/** @var list<ZoneFeedingLineData> */ public array $untickedFoods = []` (item foods of the diet no feeder accepts; empty when filters unknown).

- [ ] **Step 1: Failing tests** (PHP): `it marks a flock blocked when no feeder accepts its foods`; `it does not block when one of two feeders accepts a food` (one filter `[]`, one `['Algae']`); `it lists unticked foods of the diet`; `it leaves blocked null for grazers and hunters`; `it leaves blocked null and no unticked foods for an older mod`. Vitest: `zoneFlock` builds a line "À cocher dans le distributeur : Varech" from `untickedFoods`, nothing when empty.
- [ ] **Step 2: Run** both → FAIL.
- [ ] **Step 3: Implement.** A food is item food when its `AcceptedFoodData` kind is the stored kind (the same split `foods()` uses for item vs plants/prey). Strings: `'unticked' => 'À cocher dans le distributeur :'`, `'blocked' => 'Aucun distributeur n\'accepte sa nourriture'`.
- [ ] **Step 4: Run** → PASS. `php artisan typescript:transform`, `bun run build` only if HMR is off.
- [ ] **Step 5: Commit**: `feat: feeding follows feeder filters`.

### Task 4: Ranch problems

**Files:**
- Create: `app/Services/Zones/RanchProblems.php`
- Modify: `app/Services/Zones/ZoneProblems.php` (`summary`), `app/Services/Zones/ZonePresenter.php` (problem list), `lang/fr/zones.php` (`problems`)
- Test: `tests/Unit/Services/Zones/RanchProblemsTest.php`

**Interfaces:**
- Consumes: `ZoneFeedingData` (`autonomyCycles`, `deficitKgPerCycle`, `blockedByFilter`, `foods`), `ZoneBuildingSettings::of`, `ZonePopulationData` (`kind`, `cellCount`, `occupied`, `regionId`), personal space from `critter_morphs.overcrowding_threshold`.
- Produces: `RanchProblems::of(Zone $zone, array $feeding /* list<ZoneFeedingData> */, array $population /* list<ZonePopulationData> */): array` → `list<ZoneEntityData>`; constants `LOW_AUTONOMY_CYCLES = 3.0`, `DROP_OFF_REGION_KIND = ['CritterDropOff' => 'cavity', 'FishDeliveryPoint' => 'pond']`.

Problems (kind `critter_group` with `groupKey` for the first two, kind `building` with the drop-off's `instanceId` for the third; text in French, each ends with " (dérivé)"):
- Low stock: `deficitKgPerCycle > 0` and `autonomyCycles < 3.0` → "Stock bas : :food pour :cycles cycles" (cycles to one decimal, `:food` = main food name).
- Blocked: `blockedByFilter === true` → "Aucun distributeur n'accepte sa nourriture".
- Drop-off over room: limit L, the single region R of the matching kind, smallest personal space S among the accepted prefabs known in the DB, accepted critters already in R = N. The room still takes `floor((R.cellCount − R.occupied) / S)` more; if `L > N + that` → "Le point de dépôt amène jusqu'à :limit critters, la pièce en supporte :supported". Skip when no region, several regions, no limit, empty filter, or no known personal space.

- [ ] **Step 1: Failing tests**: `it flags stock under three cycles with a deficit`; `it ignores low stock without a deficit`; `it flags a flock blocked by filters`; `it flags a drop-off over its room`; `it skips a drop-off in a zone with two cavities`; `it skips a drop-off with nothing ticked`; `it marks every ranch problem derived`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Unit/Services/Zones/RanchProblemsTest.php` → FAIL.
- [ ] **Step 3: Implement**, then add `RanchProblems::of` to the problem list in `ZonePresenter` and to `ZoneProblems::summary` (counts). Reuse the feeding and population already computed in the presenter (`self::once`), no second computation.
- [ ] **Step 4: Run** the test and `tests/Unit/Services/Zones` + `tests/Feature/Zones` → PASS. Pint. `php artisan queue:restart`.
- [ ] **Step 5: Commit**: `feat: low stock, filter and drop-off problems`.

### Task 5: Settings in Entities and MCP

**Files:**
- Modify: `app/Data/ZoneEntityData.php` (`public ?BuildingSettingsData $settings = null`), `app/Services/Zones/ZonePresenter.php` (`buildingEntity`), `resources/js/components/zones/ZoneEntities.vue`, `lang/fr/zones.php`, `app/Mcp/Tools/EntityTool.php`, `app/Mcp/Tools/ZoneOverviewTool.php`
- Test: `tests/Feature/Mcp/EntityToolTest.php` (extend), a Vitest for the settings line formatter if one is extracted to a `.ts` helper.

**Interfaces:**
- Consumes: `ZoneBuildingSettings::of` (Task 2).
- Produces: building rows show one line: `Filtre : Algue, Varech · 340 / 500 kg` (`Rien de coché` for `[]`; `max` only in the tooltip); drop-off: `12 / 20 critters · Pacu`. Item and critter names through the existing name lookup the flock uses. MCP `entity` and `zone_overview` print `filter: Algae, Kelp; capacity 340/500 kg (max 2000)`.

- [ ] **Step 1: Failing tests**: MCP `it prints a feeder filter and capacity`, `it prints nothing ticked`; presenter `it puts settings on building entities`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** `php artisan typescript:transform`.
- [ ] **Step 4: Run** → PASS; check PacuOne in the browser (Entities tab) with the game running.
- [ ] **Step 5: Commit**: `feat: building settings in entities and MCP`.

## Finish

- [ ] Full suites: `php artisan test --compact`, `bunx vp test run`, `cd ~/dev/uplink && dotnet test Uplink.Core.Tests` → green. (`bun run typecheck` is broken by tooling: vue-tsc vs typescript `ERR_PACKAGE_PATH_NOT_EXPORTED`; report it, don't fix it here.)
- [ ] Docs: `product/rules/feeding.md` (filters, unticked foods, low stock), `product/colony.md` (settings line, ranch problems), `development/uplink.md` (wire `settings`); show the diff, commit after approval.
- [ ] Hand off with `superpowers:finishing-a-development-branch` (both repos).
