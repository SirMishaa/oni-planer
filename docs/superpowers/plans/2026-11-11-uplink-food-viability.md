# Uplink and Food Viability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A game mod (Uplink) streams the live colony state to the site over Reverb, and the site answers "how many cycles can I feed my duplicants, and when does each harvest arrive", with a built-in inspector of the received messages.

**Architecture:** The mod samples the colony every 5 s and sends one full snapshot as a Pusher client event on the public channel `uplink`. A listener inside the Reverb process validates it, journals it (`uplink_messages`, last 50) and upserts the last state per colony (`colonies`). A pure PHP service turns a stored state plus imported food calories into a viability result; two Inertia pages (viability, inspector) render it and reload their props when the browser sees the same client event.

**Tech Stack:** Laravel 13, PHP 8.5, Reverb 1.13, Inertia v3 + Vue 3 (TS), `@laravel/echo-vue`, spatie/laravel-data, Pest, Vitest (via vite-plus), SQLite. Mod: C# net48, Harmony, Newtonsoft.Json (game's), xunit on net8 for the pure core.

**Spec:** `docs/superpowers/specs/2026-11-11-uplink-food-viability-design.md` — read it before any task. Approved mockup (not committed): `.superpowers/brainstorm/60173-1794354759/content/viabilite-v2.html`. Real spike snapshot (old shape, useful for values): `storage/app/colony-spike/latest.json`.

## Global Constraints

- Two repositories. Tasks 1–9 and 13: `/home/mishaa/dev/ony-planner`, branch `feat/uplink-viability`. Tasks 10–12: new repository `/home/mishaa/dev/uplink` (`git init`, branch `main`).
- Never `npm`/`npx`: `bun`/`bunx`. Never `bun run build` (dev server with HMR runs).
- Commits: no `Co-Authored-By`, `--no-gpg-sign`. Never stage `app/Http/Controllers/CritterController.php` (user's uncommitted work) nor `json-cache/`.
- `php artisan game:import` is destructive: never run it without the user's confirmation. Tests use fixtures.
- Read `.ai/rules/index.md` if it exists and `CLAUDE.md` before editing. PHP: `declare(strict_types=1)`, `final` classes, PHPDoc array shapes, `php artisan make:*` for new files, `vendor/bin/pint --dirty --format agent` before each commit.
- Vue: `<script setup lang="ts">`, JetBrains region markers (`<!-- region X -->` / `// region X`), URLs only through Wayfinder (`@/actions/...`), single root element.
- Skills to load when touching the domain: `laravel-best-practices`, `testing-best-practices`, `inertia-vue-development`, `wayfinder-development`, `tailwindcss-development`, `octane-development` (no per-request state in singletons), `impeccable:impeccable` for Tasks 7–9 (product register; `PRODUCT.md` exists).
- Protocol constants (verbatim): channel `uplink`; event `client-colony.state`; `schema: 1`; calories in **cal** (÷1000 for kcal); one cycle = **600 s**; non-finite numbers are `null`.
- `colonies` and `uplink_messages` are user data: their models must **not** carry `#[GameData]`.
- Verdict thresholds: critical `< 3` cycles, tight `< 10`, else holds; horizon **50** cycles, displayed `50+` beyond.
- **Every user-facing string of this project is translated in French and English** (user's decision): page copy, states, verdict sentences, inspector outcomes and refusal reasons, command output and error messages, validation messages. Nothing user-facing is hardcoded in a template, controller or command. Catalogs: `lang/fr/colony.php` and `lang/en/colony.php` (same keys, nested arrays, `:placeholder` syntax, `one|other` pipe for plurals), read in PHP with `__('colony.…')` / `trans_choice` and in Vue with `useT()` (Task 6). Existing pages and the existing `lang/game/*.json` catalogs are not touched. The locale stays the application locale (`APP_LOCALE`); a language switcher is out of scope. Game names go through the existing catalogs (`PrefabName` → `__($name_key)`). No em dashes in copy. The French strings quoted in Tasks 8 and 9 are the `fr` values; write the `en` value next to each.
- Look: mockup v2 tokens (ink `oklch(16% 0.022 222)` family, ration yellow `oklch(82% 0.13 82)`, Barlow + Barlow Semi Condensed), hairline rules instead of cards, state colour always paired with a word, WCAG AA.
- Mod: never throw into the game; the game thread never waits on the socket; log prefix `Uplink:`; the mod name/ids must not contain "ONI".

## Review Focus

1. **Site not running / Reverb restarted while the game plays**: the mod must keep the game smooth and reconnect by itself (Task 11 test `Backoff`, manual step in Task 13).
2. **A plant whose crop is not a food, or a food id unknown to the site** (modded food, new DLC): ignored in calories, never a crash, listed as unknown (Task 5 test `ignores crops that are not foods`).
3. **`null` in any numeric field** (non-finite in game, section unreadable `{ "error": … }`): the calculation skips the item and the page says the section is unavailable (Task 3 test `records section errors`, Task 5 test `tolerates null numbers`).
4. **A duplicant that may eat none of the stocked foods, or a bionic duplicant**: autonomy is 0 for the former and named; the latter is excluded from consumption (Task 5 tests).
5. **Malformed or oversized message on the socket** (not JSON, wrong event, `schema: 2`): journaled as refused with a reason, the stored state untouched, the Reverb process alive (Task 3 tests).

---

## File Structure

Site:

| File | Responsibility |
|---|---|
| `config/game.php` | + `uplink_mod_path` |
| `app/Models/Food.php`, migration `create_foods_table` | game-data table of foods |
| `app/Services/GameImport/OniExport/FoodReader.php`, `app/Services/GameImport/FoodImporter.php` | food import |
| `app/Models/Colony.php`, `app/Models/UplinkMessage.php` + migrations + factories | user data |
| `app/Services/Uplink/ColonyIngestor.php` | raw socket message → journal row + colony upsert |
| `app/Listeners/IngestColonyState.php` | thin Reverb listener calling the ingestor |
| `app/Console/Commands/UplinkInstallCommand.php` | writes `uplink.json` |
| `app/Services/Viability/FoodViability.php` (+ `HarvestSchedule.php`, `FeedingSimulation.php`) | the calculation, pure |
| `app/Data/Viability/*.php` | page payload (laravel-data, exported to TS) |
| `app/Http/Controllers/ColonyController.php` | `viability`, `uplink` actions |
| `resources/js/components/timeline/CycleTimeline.vue`, `resources/js/components/timeline/timeline.ts` (+ `.test.ts`) | generic timeline |
| `lang/{fr,en}/colony.php` | UI strings of the colony pages, commands and refusal reasons |
| `resources/js/composables/useT.ts` (+ `.test.ts`) | `t()` / `tc()` over the shared `translations` prop |
| `resources/js/composables/useUplink.ts` | Echo subscription + debounced reload + live/stale status |
| `resources/js/pages/Colony/Viability.vue`, `resources/js/components/viability/*.vue` | the page, one component per block |
| `resources/js/pages/Colony/Uplink.vue`, `resources/js/components/uplink/JsonTree.vue` | inspector |
| `tests/Fixtures/Uplink/state.json` | trimmed schema-1 snapshot |

Mod (`~/dev/uplink`):

| File | Responsibility |
|---|---|
| `Uplink.Core/` (netstandard2.0) | `Settings`, `Backoff`, `LatestOutbox`, `PusherFrames`, `Num`, `ChangeDetector` — no game reference |
| `Uplink.Core.Tests/` (net8, xunit) | tests of the above |
| `Uplink/Mod.cs` | `UserMod2` entry, Harmony patches (colony loaded / unloaded, pause screen status) |
| `Uplink/Link/UplinkClient.cs` | socket threads, lifecycle, status |
| `Uplink/Link/Sampler.cs` | Unity component: 5 s realtime tick on the main thread |
| `Uplink/Snapshot/*.cs` | one static class per section |
| `build/Uplink.Wsl.csproj`, `build-and-install.sh`, `mod.yaml`, `mod_info.yaml`, `README.md` | build, install, notes |

---

### Task 1: Reverb baseline and spike removal

**Files:**
- Delete: `app/Listeners/Spike/DumpColonySnapshot.php`
- Modify: `composer.json` (scripts `dev` and `devWithOctane`), `config/game.php`, `.env.example`
- Commit as-is (already on disk from the spike): `bootstrap/app.php`, `composer.json`, `composer.lock`, `package.json`, `bun.lock`, `resources/js/app.ts`, `config/broadcasting.php`, `config/reverb.php`, `routes/channels.php`

**Interfaces:**
- Produces: `config('game.uplink_mod_path')` (string, default `env('UPLINK_MOD_PATH', '/mnt/c/Users/misha/Documents/Klei/OxygenNotIncluded/mods/dev/Uplink')`); a `reverb` process in `composer run dev`.

- [ ] **Step 1:** Delete the spike listener. Keep `storage/app/colony-spike/` on disk (ignored, used as reference).
- [ ] **Step 2:** In both dev scripts add a fifth process `"php artisan reverb:start"` named `reverb` with a fifth colour `#86efac`.
- [ ] **Step 3:** Add `uplink_mod_path` to `config/game.php` with a docblock comment like its siblings. Add to `.env.example` the `REVERB_*` / `VITE_REVERB_*` keys present in `.env` (empty id/key/secret) plus `REVERB_APP_MAX_MESSAGE_SIZE=5000000` and `REVERB_MAX_REQUEST_SIZE=5000000`. Confirm `config/reverb.php` reads those two env names and has `accept_client_events_from` = `members`; if the config key is hardcoded, wire it to the env.
- [ ] **Step 4:** Run `php artisan test --compact` → all pass (195 before this plan). Run `bun run typecheck` → no error.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`, then commit the files listed above only: `chore: install Reverb for the Uplink channel`.

---

### Task 2: Food import

**Files:**
- Create: migration `create_foods_table`, `app/Models/Food.php`, `database/factories/FoodFactory.php`, `app/Services/GameImport/OniExport/FoodReader.php`, `app/Services/GameImport/FoodImporter.php`
- Modify: `app/Console/Commands/GameImportCommand.php` (`importAll`), `app/Console/Commands/GameImportAssetsCommand.php`, `tests/Fixtures/OniExport/database/food.json`
- Test: `tests/Feature/GameImport/OniExport/FoodReaderTest.php`, additions to `GameImportCommandTest.php`, `GameImportAssetsCommandTest.php`, `GameDataTablesTest.php`

**Interfaces:**
- Produces:
  - Table `foods`: `id`, `food_id` string unique, `calories_per_unit` float (cal), `quality` integer, `can_rot` boolean, `dlc_id` string nullable, timestamps. Model `App\Models\Food` with `#[GameData]`.
  - `FoodReader::__construct(OniExport $export)`; `records(): list<array{food_id: string, calories_per_unit: float, quality: int, can_rot: bool, dlc_id: string|null}>`.
  - `FoodImporter::importRecords(array $records): void`.
  - Icons at `public/assets/game/foods/{food_id}.webp`; `GameImportAssetsCommand` prints the food ids with no `ui_image/{id}.png`.
  - Display name of a food: existing `PrefabName` lookup by `prefab_id` (check `PrefabNameImporter` covers food ids; if a food id has no row, extend the importer with the food records, key `STRINGS.ITEMS.FOOD.{UPPER_ID}.NAME`).

Source shape (`database/food.json`): `foodInfoList[]` with `Id`, `CaloriesPerUnit`, `Quality`, `CanRot`, `Display`; `requiredDlcIdsMap[Id]` is `null` or a list (first entry = `dlc_id`). Foods with `CaloriesPerUnit <= 0` are skipped.

- [ ] **Step 1:** Extend the fixture `food.json` to three foods: `Lettuce` (400000, quality 0, CanRot true, no DLC), `FishMeat` (1000000, 2, true), `SwampFruit` (1840000, 0, true, `requiredDlcIdsMap` `["EXPANSION1_ID"]`), plus one `Display: true` entry with `CaloriesPerUnit: 0` named `Zero`. Keep `PrickleFruit` if other tests read it (give it 1600000).
- [ ] **Step 2: Failing reader test**

```php
it('reads foods with calories, quality and dlc', function () {
    $foods = collect(new FoodReader(new OniExport(base_path('tests/Fixtures/OniExport')))->records())->keyBy('food_id');

    expect($foods)->not->toHaveKey('Zero')
        ->and($foods['Lettuce'])->toMatchArray(['calories_per_unit' => 400000.0, 'quality' => 0, 'can_rot' => true, 'dlc_id' => null])
        ->and($foods['SwampFruit']['dlc_id'])->toBe('EXPANSION1_ID');
});
```

- [ ] **Step 3:** Run `php artisan test --compact --filter=FoodReaderTest` → fails (class missing). Implement migration, model, factory, reader, importer. Run → passes.
- [ ] **Step 4: Command tests** — in `GameImportCommandTest.php` add: after the fixture import, `Food::query()->where('food_id', 'Lettuce')->value('calories_per_unit')` is `400000.0` and the report prints a `Foods` count line. In `GameDataTablesTest.php`: `GameDataTables::all()` contains `foods`. In `GameImportAssetsCommandTest.php`: with a `ui_image/Lettuce.png` in the temp export and a `Food` row for `Lettuce` and `FishMeat`, the command writes `foods/Lettuce.webp` and its output contains `FishMeat` in a "missing food icons" line.
- [ ] **Step 5:** Wire `FoodReader`/`FoodImporter` into `importAll` after geysers (`$this->info('Importing foods...')`, `'Foods'` in `counts`); add the food loop to the assets command following the element loop. Run the three test files → pass.
- [ ] **Step 6:** Pint, commit: `feat(import): import foods with their calories and icons`.
- [ ] **Step 7:** Ask the user to confirm before running `php artisan game:import` then `php artisan game:import-assets` on the real export; expected `Foods 75` (minus zero-calorie entries). Note the missing icon ids for Task 8.

---

### Task 3: Ingestion (journal + last state)

**Files:**
- Create: migrations `create_colonies_table`, `create_uplink_messages_table`; `app/Models/Colony.php`, `app/Models/UplinkMessage.php`, factories; `app/Services/Uplink/ColonyIngestor.php`; `app/Listeners/IngestColonyState.php`; `tests/Fixtures/Uplink/state.json`
- Test: `tests/Feature/Uplink/ColonyIngestorTest.php`

**Interfaces:**
- Produces:
  - `colonies`: `id`, `guid` string unique, `name` string, `cycle` integer, `state` json, `schema` integer, `last_seen_at` datetime, timestamps. Casts: `state` → `array`, `last_seen_at` → `datetime`.
  - `uplink_messages`: `id`, `event` string nullable, `colony_guid` string nullable, `colony_name` string nullable, `cycle` integer nullable, `size` integer (bytes), `outcome` string (`stored`|`refused`), `reason` string nullable (`invalid_json`|`schema_missing`|`schema_newer`|`colony_missing`), `section_errors` json (list of section names), `payload` longText (the raw message, truncated to 2 000 000 bytes), `received_at` datetime. No `updated_at`.
  - `ColonyIngestor::KEEP = 50`, `ColonyIngestor::SCHEMA = 1`, `ColonyIngestor::EVENT = 'client-colony.state'`, `ColonyIngestor::CHANNEL = 'uplink'`.
  - `ColonyIngestor::ingest(string $rawMessage): ?UplinkMessage` — returns `null` (nothing written) when the message is not for `EVENT` on `CHANNEL` (Pusher protocol frames, pings, other channels).
  - `IngestColonyState::handle(Laravel\Reverb\Events\MessageReceived $event): void` — cheap prefilter `str_contains(substr($event->message, 0, 200), ColonyIngestor::EVENT)`, then `ingest`, wrapped in `try/catch (Throwable)` + `report()` so the Reverb loop never dies. Laravel discovers it from `app/Listeners`.
  - Fixture `state.json`: the **envelope** `{"event":"client-colony.state","channel":"uplink","data":{…schema 1…}}`.

Envelope notes: `data` may be an object or a JSON-encoded string (Pusher allows both) — accept both. A section is "in error" when its value is an object with an `error` key instead of a list/object of data.

- [ ] **Step 1: Build the fixture** by hand from the spec's protocol example and the real values in `storage/app/colony-spike/latest.json`: colony `{"id":"guid-deepwater","name":"DeepWaterGo","cycle":59,"cyclePercent":0.5,"build":"U59-744825","dlcs":["DLC5_ID"]}`; one world id 0; duplicants `Ruby` (id `d1`, forbiddenFoods `[]`), `Ellie` (id `d2`, forbiddenFoods `["FishMeat"]`), each `calories {value: 3000000, max: 4000000, deltaPerSecond: -1666.6667}`, model `Minion`; food `Lettuce` 4000000 cal (10 units), `FishMeat` 3000000 cal (3 units); plants: P1 `SeaLettuce` planted, cropId `Lettuce`, cropUnits 12, cropDurationSeconds 7200, autoHarvest true, secondsUntilHarvest 300, growthPerSecond 0.0016667; P2 same but wild, autoHarvest true, secondsUntilHarvest 1200, growthPerSecond 0.00041667; P3 wild, autoHarvest false, secondsUntilHarvest 600, growthPerSecond 0.00041667; P4 planted, wilting true, growthPerSecond 0, secondsUntilHarvest null; P5 `Oxyfern`-like with cropId `NotAFood`; critters: two tame `Pacu` with drops `[{"id":"FishMeat","units":1}]`; `printingPod {"secondsUntilNextPrint": 1260.0}`. Tasks 5 and 6 reuse this file; do not change these numbers afterwards.
- [ ] **Step 2: Failing tests** (`uses(RefreshDatabase::class)`; helper `uplinkFixture(array $override = []): string` that merges into `data` and re-encodes):

```php
it('stores the state and journals the message', function () {
    $message = app(ColonyIngestor::class)->ingest(uplinkFixture());

    $colony = Colony::query()->sole();
    expect($message->outcome)->toBe('stored')
        ->and($message->size)->toBe(strlen(uplinkFixture()))
        ->and($colony->guid)->toBe('guid-deepwater')
        ->and($colony->cycle)->toBe(59)
        ->and($colony->state['duplicants'])->toHaveCount(2)
        ->and($colony->last_seen_at)->not->toBeNull();
});

it('keeps one row per colony', function () {
    app(ColonyIngestor::class)->ingest(uplinkFixture());
    app(ColonyIngestor::class)->ingest(uplinkFixture(['colony' => ['cycle' => 60]]));
    expect(Colony::query()->count())->toBe(1)->and(Colony::query()->value('cycle'))->toBe(60);
});

it('ignores frames that are not colony states', function () {
    expect(app(ColonyIngestor::class)->ingest('{"event":"pusher:ping","data":{}}'))->toBeNull()
        ->and(UplinkMessage::query()->count())->toBe(0);
});

it('refuses a newer schema without touching the stored state', function () {
    app(ColonyIngestor::class)->ingest(uplinkFixture());
    $message = app(ColonyIngestor::class)->ingest(uplinkFixture(['schema' => 2, 'colony' => ['cycle' => 99]]));
    expect($message->outcome)->toBe('refused')->and($message->reason)->toBe('schema_newer')
        ->and(Colony::query()->value('cycle'))->toBe(59);
});

it('refuses unreadable messages with a reason', function (string $raw, string $reason) {
    expect(app(ColonyIngestor::class)->ingest($raw)->reason)->toBe($reason);
})->with([
    'broken json' => ['{"event":"client-colony.state","channel":"uplink","data":{', 'invalid_json'],
    'no schema' => ['{"event":"client-colony.state","channel":"uplink","data":{"colony":{"id":"x"}}}', 'schema_missing'],
    'no colony id' => ['{"event":"client-colony.state","channel":"uplink","data":{"schema":1,"colony":{}}}', 'colony_missing'],
]);

it('accepts data sent as a json string', function () {
    $envelope = json_decode(uplinkFixture(), true);
    $envelope['data'] = json_encode($envelope['data']);
    expect(app(ColonyIngestor::class)->ingest(json_encode($envelope))->outcome)->toBe('stored');
});

it('records section errors', function () {
    $message = app(ColonyIngestor::class)->ingest(uplinkFixture(['plants' => ['error' => 'boom']]));
    expect($message->outcome)->toBe('stored')->and($message->section_errors)->toBe(['plants']);
});

it('keeps only the last 50 messages', function () {
    foreach (range(1, 53) as $i) {
        app(ColonyIngestor::class)->ingest(uplinkFixture(['colony' => ['cycle' => $i]]));
    }
    expect(UplinkMessage::query()->count())->toBe(50)->and(UplinkMessage::query()->min('cycle'))->toBe(4);
});

it('is not truncated by the game import', function () {
    expect(GameDataTables::all())->not->toContain('colonies')->not->toContain('uplink_messages');
});
```

`broken json` still matches the prefilter (event name in the first bytes) so it is journaled with `event` null.

- [ ] **Step 3:** Run `php artisan test --compact --filter=ColonyIngestorTest` → fails. Implement. Run → passes.
- [ ] **Step 4:** Pint, commit: `feat(uplink): ingest colony states from the Reverb channel`.

---

### Task 4: `uplink:install`

**Files:**
- Create: `app/Console/Commands/UplinkInstallCommand.php`
- Test: `tests/Feature/Uplink/UplinkInstallCommandTest.php`

**Interfaces:**
- Produces: `php artisan uplink:install {--path=} {--host=127.0.0.1}` writing `{path}/uplink.json`:

```json
{ "url": "ws://127.0.0.1:8080/app/<REVERB_APP_KEY>?protocol=7&client=uplink&version=1", "channel": "uplink", "intervalSeconds": 5 }
```

`path` defaults to `config('game.uplink_mod_path')`; key and port come from `config('reverb.apps.apps.0.key')` and `config('reverb.servers.reverb.port')` (verify the exact config paths in `config/reverb.php`); scheme `wss` when the Reverb scheme is `https`. Host defaults to `127.0.0.1` (the game runs on Windows, Reverb in WSL; the spike proved `127.0.0.1` reaches WSL).

All command output goes through `__('colony.install.…')` (keys `written`, `folder_missing`, `key_missing`), created here in both `lang/fr/colony.php` and `lang/en/colony.php`.

- [ ] **Step 1: Failing tests:** (a) with `--path` = a temp dir, the file exists and decodes to the exact three keys, `url` containing the configured key and `protocol=7`; (b) when the directory does not exist the command fails with exit code 1 and a message telling to build and install the mod first (it does not create the folder); (c) missing Reverb key → failure naming `REVERB_APP_KEY`; (d) with `app()->setLocale('fr')` the folder-missing message equals `__('colony.install.folder_missing', […], 'fr')` and differs from the `en` one.
- [ ] **Step 2:** Run → fail. Implement. Run → pass. Pint, commit: `feat(uplink): add the uplink:install command`.

---

### Task 5: The calculation

**Files:**
- Create: `app/Services/Viability/HarvestSchedule.php`, `app/Services/Viability/FeedingSimulation.php`, `app/Services/Viability/FoodViability.php`
- Test: `tests/Feature/Viability/HarvestScheduleTest.php`, `FeedingSimulationTest.php`, `FoodViabilityTest.php`

**Interfaces:**
- Consumes: a state array (shape of `data` in `tests/Fixtures/Uplink/state.json`); `array<string, float>` calories per unit keyed by food id (from `Food::query()->pluck('calories_per_unit', 'food_id')`).
- Produces (all pure, no database inside; kcal are floats):

```php
final class HarvestSchedule {
    public const int HORIZON = 50;
    /**
     * @param list<array<string,mixed>> $plants  @param array<string,float> $caloriesPerUnit
     * @return array{
     *   harvests: list<array{sourceKey: string, plantId: string, cropId: string, kind: 'planted'|'wild_auto'|'wild_manual', offset: float, kcal: float}>,
     *   sources: list<array{key: string, plantId: string, cropId: string, kind: 'planted'|'wild_auto'|'wild_manual', count: int, stopped: int, kcalPerCycle: float}>,
     *   unknownCrops: list<string>,
     *   autoHarvestKnown: bool,
     * }
     */
    public static function build(array $plants, array $caloriesPerUnit): array;
}

final class FeedingSimulation {
    /**
     * @param array<string,float> $stockKcal                         by food id
     * @param list<array{id: string, name: string, needKcal: float, forbidden: list<string>}> $eaters
     * @param list<array{offset: float, cropId: string, kcal: float}> $arrivals
     * @return array{autonomy: int|null, firstShort: string|null, stockByCycle: list<float>}
     */
    public static function run(array $stockKcal, array $eaters, array $arrivals, int $horizon = HarvestSchedule::HORIZON): array;
}

final class FoodViability {
    /** @return array<string,mixed> the shape given in Task 6's `ViabilityData` */
    public static function compute(array $state, array $caloriesPerUnit): array;
}
```

Rules (these are the decisions; the spec's Part 4 is the source):

- `offset` = cycles from now = `seconds / 600`. Absolute cycle on the page = `cycle + cyclePercent + offset`.
- A plant yields harvests only if `cropId` is a key of `$caloriesPerUnit`; otherwise its `cropId` goes to `unknownCrops` (unique) and the plant is dropped.
- `kind`: `planted` when `planted`; else `wild_auto` when `autoHarvest === true`; else `wild_manual`. `autoHarvestKnown` is false when every wild plant has `autoHarvest === null`.
- Source key = `"{plantId}:{kind}"`. `stopped` counts plants with `wilting === true`, or `growthPerSecond` null or `<= 0` while not `grown`. Stopped plants yield nothing.
- Harvest times of a growing plant: first at `secondsUntilHarvest` (0 when `grown`; plant dropped as stopped if `null` and not grown), then every `period = (cropDurationSeconds / 600) / growthPerSecond` seconds (growth is in maturity-cycles per second: a domestic plant has `1/600`, so `period == cropDurationSeconds`; a wild one at 25 % has four times that). When `grown` and `growthPerSecond` is null/0, period = `cropDurationSeconds` for planted and `4 × cropDurationSeconds` for wild. Generate while `offset <= 50`.
- `kcal` per harvest = `cropUnits × caloriesPerUnit[cropId] / 1000`. `kcalPerCycle` of a source = sum over its growing plants of `kcal / (period / 600)`.
- Eaters: duplicants with `model === 'Minion'` and non-null `calories`. `needKcal` = `-deltaPerSecond × 600 / 1000` when `deltaPerSecond` is finite and negative, else `1000.0`. Others are listed as `nonEaters` (name, model).
- Stock: `food[].calories / 1000` summed by `id` over all worlds (limit shown on the page), foods unknown to `$caloriesPerUnit` still counted (the game gave their calories).
- `FeedingSimulation::run`: for cycle `c = 1..horizon`: add arrivals with `c-1 < offset <= c` (offset 0 counts in cycle 1) to the stock; then feed eaters in ascending order of "number of foods in stock they may eat" (ties: input order); each eater draws its `needKcal` from its allowed foods, taking first the food that the fewest eaters may eat (ties: larger stock, then id). If an eater cannot be fully fed, stop: `autonomy = c - 1`, `firstShort = name`. `stockByCycle[c]` = total stock after cycle `c` (index 0 = initial stock); after a stop, remaining entries are `0.0`. Surviving the horizon → `autonomy = null`. Body reserves of duplicants are ignored (stated limit).
- Three scenarios in `compute`: `none` (no arrivals), `automatic` (planted + wild_auto) = the headline, `all` (+ wild_manual).
- Verdict from `automatic`: `null` → `holds`; `< 3` → `critical`; `< 10` → `tight`; else `holds`.
- Reserves: `wildManual` = sources of kind `wild_manual` with `kcalPerCycle`; `critters` grouped by `id` and `tame` with `count` and `kcal` = sum of `drops[].units × caloriesPerUnit[id] / 1000` (`null` when the critters section has no `drops` key at all → "butin inconnu"); `printingPod.cyclesUntilNextPrint` = `secondsUntilNextPrint / 600` or `null`.
- A section given as `{ "error": … }` is treated as empty and its name added to `unavailable`.

- [ ] **Step 1: Failing tests** (helper `fixtureState(): array` = `data` of the Task 3 fixture; `$cal = ['Lettuce' => 400000.0, 'FishMeat' => 1000000.0]`; one Lettuce harvest = `12 × 400` = **4800 kcal**).

```php
// HarvestScheduleTest
it('schedules planted, wild auto and wild manual harvests', function () {
    $r = HarvestSchedule::build(fixtureState()['plants'], $cal);
    $by = collect($r['harvests'])->groupBy('kind');
    expect($by['planted']->first())->toMatchArray(['offset' => 0.5, 'kcal' => 4800.0])       // 300 s
        ->and($by['planted']->pluck('offset')->take(3)->all())->toEqualWithDelta([0.5, 12.5, 24.5], 0.01)
        ->and($by['wild_auto']->pluck('offset')->take(2)->all())->toEqualWithDelta([2.0, 50.0], 0.05)
        ->and($by['wild_manual']->first()['offset'])->toEqualWithDelta(1.0, 0.01);
});
it('counts stopped plants and yields nothing for them', function () {
    $planted = collect(HarvestSchedule::build(fixtureState()['plants'], $cal)['sources'])->firstWhere('key', 'SeaLettuce:planted');
    expect($planted)->toMatchArray(['count' => 2, 'stopped' => 1])->and($planted['kcalPerCycle'])->toEqualWithDelta(400.0, 0.5);
});
it('ignores crops that are not foods', function () {
    $r = HarvestSchedule::build(fixtureState()['plants'], $cal);
    expect($r['unknownCrops'])->toBe(['NotAFood'])->and(collect($r['harvests'])->pluck('cropId')->unique()->all())->toBe(['Lettuce']);
});
it('tolerates null numbers', function () {
    $plants = [['id' => 'X', 'cropId' => 'Lettuce', 'cropUnits' => null, 'cropDurationSeconds' => null, 'planted' => true,
        'autoHarvest' => null, 'wilting' => false, 'grown' => false, 'secondsUntilHarvest' => null, 'growthPerSecond' => null]];
    expect(HarvestSchedule::build($plants, $cal)['harvests'])->toBe([]);
});

// FeedingSimulationTest
it('counts cycles with no harvest', function () {
    $r = FeedingSimulation::run(['Lettuce' => 4000.0, 'FishMeat' => 3000.0],
        [['id' => 'd1', 'name' => 'Ruby', 'needKcal' => 1000.0, 'forbidden' => []],
         ['id' => 'd2', 'name' => 'Ellie', 'needKcal' => 1000.0, 'forbidden' => ['FishMeat']]], []);
    // Ellie eats lettuce only; Ruby takes fish first (fewest eaters): 3 cycles of fish then shares lettuce.
    expect($r['autonomy'])->toBe(3)->and($r['firstShort'])->toBe('Ellie')->and($r['stockByCycle'][0])->toBe(7000.0);
});
it('is zero when a duplicant may eat nothing in stock', function () {
    $r = FeedingSimulation::run(['FishMeat' => 9000.0], [['id' => 'd2', 'name' => 'Ellie', 'needKcal' => 1000.0, 'forbidden' => ['FishMeat']]], []);
    expect($r['autonomy'])->toBe(0)->and($r['firstShort'])->toBe('Ellie');
});
it('returns null beyond the horizon', function () {
    $r = FeedingSimulation::run(['Lettuce' => 60000.0], [['id' => 'd1', 'name' => 'Ruby', 'needKcal' => 1000.0, 'forbidden' => []]], []);
    expect($r['autonomy'])->toBeNull()->and($r['stockByCycle'])->toHaveCount(51);
});
it('uses arrivals in the cycle they land', function () {
    $r = FeedingSimulation::run(['Lettuce' => 1000.0], [['id' => 'd1', 'name' => 'Ruby', 'needKcal' => 1000.0, 'forbidden' => []]],
        [['offset' => 1.5, 'cropId' => 'Lettuce', 'kcal' => 2000.0]]);
    expect($r['autonomy'])->toBe(3);
});

// FoodViabilityTest
it('computes the headline from automatic arrivals only', function () {
    $v = FoodViability::compute(fixtureState(), $cal);
    expect($v['consumptionKcalPerCycle'])->toEqualWithDelta(2000.0, 0.1)
        ->and($v['stockKcal'])->toBe(7000.0)
        ->and($v['scenarios']['none']['autonomy'])->toBe(3)
        ->and($v['scenarios']['automatic']['autonomy'])->toBeGreaterThan(3)
        ->and($v['scenarios']['all']['autonomy'])->toBeGreaterThanOrEqual($v['scenarios']['automatic']['autonomy'])
        ->and($v['verdict'])->toBeIn(['critical', 'tight', 'holds']);
});
it('excludes duplicants that do not eat', function () {
    $state = fixtureState();
    $state['duplicants'][] = ['id' => 'd3', 'name' => 'Bix', 'model' => 'Bionic', 'worldId' => 0, 'calories' => null, 'forbiddenFoods' => [], 'traits' => []];
    $v = FoodViability::compute($state, $cal);
    expect($v['consumptionKcalPerCycle'])->toEqualWithDelta(2000.0, 0.1)->and($v['nonEaters'])->toHaveCount(1);
});
it('marks an unreadable section as unavailable', function () {
    $state = fixtureState(); $state['plants'] = ['error' => 'boom'];
    $v = FoodViability::compute($state, $cal);
    expect($v['unavailable'])->toBe(['plants'])->and($v['scenarios']['automatic']['autonomy'])->toBe(3);
});
it('lists critter reserves without counting them', function () {
    $v = FoodViability::compute(fixtureState(), $cal);
    expect($v['reserves']['critters'][0])->toMatchArray(['id' => 'Pacu', 'tame' => true, 'count' => 2, 'kcal' => 2000.0])
        ->and($v['reserves']['printingPod']['cyclesUntilNextPrint'])->toEqualWithDelta(2.1, 0.01);
});
```

If a hand-computed number above disagrees with the rules when implemented, the **rules win**: recompute by hand, fix the assertion, and say so in the commit body.

- [ ] **Step 2:** Run `php artisan test --compact tests/Feature/Viability` → fail. Implement the three classes. Run → pass.
- [ ] **Step 3:** Pint, commit: `feat(viability): compute food autonomy and harvest arrivals`.

---

### Task 6: Controller, payload and routes

**Files:**
- Create: `app/Http/Controllers/ColonyController.php`, `app/Data/Viability/ViabilityData.php` (+ nested data classes in the same folder), `app/Data/UplinkMessageData.php`, `app/Data/ColonyStatusData.php`
- Create: `resources/js/composables/useT.ts`, `resources/js/composables/useT.test.ts`; extend `lang/{fr,en}/colony.php`
- Modify: `routes/web.php`, `resources/js/layouts/AppLayout.vue` (nav), `app/Http/Middleware/HandleInertiaRequests.php`
- Test: `tests/Feature/Colony/ColonyControllerTest.php`, `tests/Feature/Colony/ColonyTranslationsTest.php`

**Interfaces:**
- Consumes: `FoodViability::compute`, `Colony`, `UplinkMessage`, `Food`, `PrefabName`.
- Produces:
  - Routes `GET /colony/viability` → `ColonyController::viability` (name `colony.viability`), `GET /colony/uplink` → `ColonyController::uplink` (name `colony.uplink`). Wayfinder: `@/actions/App/Http/Controllers/ColonyController`.
  - Page `Colony/Viability` props: `status: ColonyStatusData`, `viability: ViabilityData|null`.
  - Page `Colony/Uplink` props: `status: ColonyStatusData`, `messages: list<UplinkMessageData>` (newest first, **without** payload), `selected: {id: number, payload: string}|null` (from query `?message={id}`, `Inertia::optional` is not used: it is a plain nullable prop).
  - `ColonyStatusData`: `state: 'never'|'no_colony'|'ok'|'newer_schema'`, `colonyName: string|null`, `cycle: int|null`, `lastSeenAt: string|null` (ISO 8601), `schema: int|null`, `echo: {channel: 'uplink', event: '.client-colony.state'}`. `never` = no `uplink_messages` row and no colony; `newer_schema` = latest message refused with `schema_newer`; `no_colony` = messages exist but no colony row; else `ok`. Live vs stale is decided in the browser from `lastSeenAt` (stale after 20 s).
  - `ViabilityData` fields (camelCase in TS): `nowCycle: float`, `verdict: 'critical'|'tight'|'holds'`, `consumptionKcalPerCycle`, `stockKcal`, `scenarios: {none, automatic, all}` each `{autonomy: int|null, firstShort: string|null, stockByCycle: list<float>}` (page shows the first 21 values), `sources[]` (`key`, `plantId`, `plantName`, `plantIcon`, `cropId`, `kind`, `count`, `stopped`, `kcalPerCycle`), `harvests[]` (`sourceKey`, `kind`, `cycle` absolute decimal, `kcal`), `lastComputedCycle: float` (= `nowCycle + 50`), `menu[]` (`foodId`, `name`, `icon: string|null`, `units`, `kcal`, `forbiddenFor: list<string>` duplicant names), `eaters[]` (`id`, `name`, `kcal`, `maxKcal`, `needKcal`, `allowed[]`/`forbidden[]` food ids among stocked foods), `nonEaters[]`, `reserves` (`wildManual[]`, `critters[]` with `name`, `icon`, `printingPod`), `unavailable: list<string>`, `unknownCrops: list<string>`, `autoHarvestKnown: bool`.
  - Names: `__($prefabName->name_key)` with the id as fallback. Icons: `/assets/game/foods/{id}.webp`, `/assets/game/plants/{id}.webp`, `/assets/game/critters/{id}.webp` when the file exists in `config('game.assets_path')`, else `null` (check how existing pages build plant/critter icon URLs and reuse that helper).
  - The colony shown is the most recently seen: `Colony::query()->latest('last_seen_at')->first()`.
  - Translations: `HandleInertiaRequests::share` adds `locale` (string) and `translations` => `['colony' => trans('colony')]` (whole group for the current locale, a closure so it is resolved per request; Octane-safe, no static cache). `useT(): { t(key: string, replace?: Record<string, string | number>): string; tc(key: string, count: number, replace?: Record<string, string | number>): string }` reads `usePage().props.translations`; keys are dotted paths (`colony.verdict.holds`); `:name` placeholders replaced; `tc` picks the part before `|` when `count === 1` (French: also when `count === 0`, i.e. `count < 2` for locale `fr`), else the part after, and replaces `:count` (formatted with `Intl.NumberFormat(locale)`); a missing key returns the key itself. Pure helpers `translate(dict, key, replace)` and `choose(text, count, locale)` are exported for tests. Numbers and relative times on the pages use `Intl.NumberFormat` / `Intl.RelativeTimeFormat` with `locale`.
  - Refusal reasons and outcomes are sent as codes (`schema_newer`…) and translated in Vue (`colony.inspector.reason.{code}`); nothing is pre-translated in `UplinkMessageData`.
  - Nav labels: `colony.nav.group`, `colony.nav.viability`, `colony.nav.uplink`.
  - Nav: a group "Ma colonie" with "Viabilité" and "Uplink", following how `AppLayout.vue` declares items (replace hardcoded hrefs for these two with Wayfinder `.url()`).

- [ ] **Step 1: Failing tests:** (a) no data → `Colony/Viability` renders with `status.state` `never` and `viability` null; (b) after `ingest(uplinkFixture())` with `Food` factory rows for `Lettuce` (400000) and `FishMeat` (1000000) → `viability.stockKcal` 7000, `viability.scenarios.none.autonomy` 3, `status.state` `ok`, `status.colonyName` `DeepWaterGo`; (c) after a `schema: 2` message → `status.state` `newer_schema`; (d) `/colony/uplink` lists messages newest first, each without a `payload` key, and `?message={id}` fills `selected.payload`; an unknown id gives `selected` null (no 404). Use `assertInertia`.
  Also: `ColonyTranslationsTest` — `Arr::dot(trans('colony', [], 'fr'))` and the `en` one have exactly the same keys, and no value is empty; the Inertia shared props carry `translations.colony.nav.viability`. Vitest `useT.test.ts`: `translate({colony:{a:{b:'Salut :name'}}}, 'colony.a.b', {name:'Ruby'})` → `'Salut Ruby'`; missing key → the key; `choose(':count cycle|:count cycles', 1, 'en')` → `'1 cycle'`, `(…, 0, 'en')` → `'0 cycles'`, `(…, 0, 'fr')` → `'0 cycle'`, `(…, 2, 'fr')` → `'2 cycles'`.
- [ ] **Step 2:** Run → fail. Implement. Run `php artisan typescript:transform` if that is how `resources/js/types/App/Data/data.d.ts` is generated (check `composer.json` scripts / the provider) and `php artisan wayfinder:generate` if the Vite plugin is not running. Run tests → pass; `bun run typecheck` → clean.
- [ ] **Step 3:** Pint, commit: `feat(colony): viability and uplink endpoints`.

---

### Task 7: `CycleTimeline` component

**Files:**
- Create: `resources/js/components/timeline/timeline.ts`, `resources/js/components/timeline/timeline.test.ts`, `resources/js/components/timeline/CycleTimeline.vue`

**Interfaces:**
- Produces:

```ts
export interface TimelineRow { id: string }
export interface TimelineEvent<P = unknown> { rowId: string; cycle: number; kind: string; payload: P }
export interface TimelineMark<P = unknown> { rowId: string; cycle: number; kind: string; events: TimelineEvent<P>[] }

/** Merges events of the same row and kind closer than `minGapPx` on screen. */
export function mergeEvents<P>(events: TimelineEvent<P>[], pxPerCycle: number, minGapPx: number): TimelineMark<P>[];
/** Integer cycle ticks from floor(start) to ceil(end), thinned so labels are >= 48 px apart. */
export function cycleTicks(startCycle: number, endCycle: number, pxPerCycle: number): number[];
export function cycleToPx(cycle: number, startCycle: number, pxPerCycle: number): number;
```

`CycleTimeline.vue` (generic, knows nothing about food) — props: `rows: TimelineRow[]`, `events: TimelineEvent[]`, `startCycle: number`, `endCycle: number` (last computed cycle), `nowCycle: number`, `pxPerCycle?: number` (v-model, default 56, clamped 16–160), `minGapPx?: number` (default 18). Slots: `#label="{ row }"`, `#summary="{ row }"`, `#mark="{ mark }"` (default: a dot with the count when `mark.events.length > 1`). Behaviour: time left to right; one horizontal scroll container reaching `endCycle`; label and summary columns `position: sticky` (left / right); a header of cycle ticks; a "now" line at `nowCycle`; a scale control (− / + buttons and `Ctrl`+wheel) bound to `pxPerCycle`; keyboard: the scroll container is focusable (`tabindex="0"`, arrow keys scroll natively), marks are not tab stops; `role="table"`-like semantics are not required but each row has an `aria-label` built from the label slot's row id passed by the parent via `rows[].ariaLabel?`. No layout property is animated.

- [ ] **Step 1: Failing Vitest tests** (`bunx vp test resources/js/components/timeline/timeline.test.ts`):

```ts
it('merges close events of the same row and kind', () => {
    const e = (cycle: number, kind = 'a', rowId = 'r') => ({ rowId, cycle, kind, payload: null });
    const marks = mergeEvents([e(60), e(60.1), e(61), e(60.05, 'b'), e(60, 'a', 'r2')], 50, 18);
    expect(marks).toHaveLength(4);
    expect(marks.find((m) => m.rowId === 'r' && m.kind === 'a' && m.events.length === 2)?.cycle).toBeCloseTo(60, 5);
    expect(marks.filter((m) => m.kind === 'b')).toHaveLength(1);
    expect(marks.filter((m) => m.rowId === 'r2')).toHaveLength(1);
});
it('thins ticks to stay readable', () => {
    expect(cycleTicks(59.5, 64, 56)).toEqual([59, 60, 61, 62, 63, 64]);
    expect(cycleTicks(59.5, 110, 16)).toEqual([59, 62, 65, 68, 71, 74, 77, 80, 83, 86, 89, 92, 95, 98, 101, 104, 107, 110]);
});
it('places a cycle in pixels', () => { expect(cycleToPx(61, 59.5, 56)).toBe(84); });
```

A merged mark sits at the cycle of its first event. Tick step = smallest integer `n` with `n × pxPerCycle >= 48`.

- [ ] **Step 2:** Run → fail. Implement `timeline.ts`. Run → pass. Then build `CycleTimeline.vue`. `bun run typecheck` and `bun run lint` → clean.
- [ ] **Step 3:** Commit: `feat(ui): generic cycle timeline component`.

---

### Task 8: Viability page

**Files:**
- Create: `resources/js/composables/useUplink.ts`, `resources/js/pages/Colony/Viability.vue`, `resources/js/components/viability/{VerdictBlock,ProjectionChart,ArrivalsBlock,MenuBlock,EatersBlock,ReservesBlock,UplinkStatus,ColonyEmptyState}.vue`
- Modify: fonts/tokens where the app declares them (`resources/css/app.css`): Barlow, Barlow Semi Condensed and the v2 colour tokens, scoped to a `.colony` wrapper class so other pages are untouched.

**Interfaces:**
- Consumes: page props of Task 6; `CycleTimeline` of Task 7.
- Produces: `useUplink(status: MaybeRef<ColonyStatusData>, only: string[]): { live: Ref<boolean>, ageLabel: Ref<string> }` — subscribes with `useEchoPublic(status.echo.channel, status.echo.event, cb)` from `@laravel/echo-vue`; `cb` triggers a debounced (400 ms, trailing) `router.reload({ only })`; `live` is true while `lastSeenAt` is younger than 20 s (re-evaluated every second); `ageLabel` is `en direct` or `vu il y a 2 h` style French relative time. Reused by Task 9.

Every string below is a `fr` catalog value under `colony.viability.*` / `colony.status.*`, with its `en` counterpart; templates only call `t()` / `tc()`. The key-parity test of Task 6 must still pass at the end of this task.

Load `impeccable:impeccable` (product register) and open the mockup file before writing markup; the mockup is the visual reference, the spec's Part 5 the content reference. Blocks top to bottom, one page, no tabs:

1. **Verdict** — autonomy of `scenarios.automatic` as the largest element (`50+` when null), state word (`Critique` / `Juste` / `Ça tient`) with its colour, one plain sentence (e.g. "À ce rythme, Ellie manque de nourriture au cycle 71."), the no-harvest figure, consumption per cycle, and `ProjectionChart`: inline SVG, 21 points per scenario, three lines labelled directly at their end ("Sans récolte", "Automatique", "Avec cueillette sauvage"), a zero baseline, no legend box, values readable by `<title>`/`aria-label`.
2. **Arrivages** — `CycleTimeline` with `rows` = `sources`, `events` = `harvests` (`rowId` = `sourceKey`), `startCycle` = `floor(nowCycle)`, `endCycle` = `lastComputedCycle`. Label slot: plant sprite, name, count, warning "2 ne poussent plus" when `stopped > 0`. Summary slot: `kcalPerCycle`. Three mark styles differing in **shape** as well as colour (filled, ringed, dashed ring) with a legend naming them: "Plantation", "Sauvage, récolte auto", "Sauvage, à aller chercher". When `autoHarvestKnown` is false, a note says the game did not give the auto-harvest setting and wild plants are shown as "à aller chercher".
3. **Au menu** — canteen-menu rows: icon, name, units, dotted leader, kcal; note "Interdit à Ellie" from `forbiddenFor`. Missing icon → neutral food placeholder glyph (never a critter sprite).
4. **Convives** — per duplicant: initials placeholder, name, calorie meter with numbers, allowed food icons, forbidden ones dimmed with the label "interdit". `nonEaters` listed on one line ("ne mange pas").
5. **Réserves, si tu décides** — `wildManual`, `critters` (count, kcal or "butin inconnu"), printing pod ("prochaine impression dans 2,1 cycles"), and the sentence that none of this is counted above.

Footer of the page: the stated limits (spoilage ignored, harvests counted raw, someone picks at maturity, all asteroids summed, duplicants' own reserves ignored).

States: `never` → `ColonyEmptyState` explaining to install Uplink and run `php artisan uplink:install`, nothing fake on screen; `no_colony` → "Uplink connecté, aucune colonie chargée"; `newer_schema` → banner "Uplink est plus récent que le site" above the last known state; `unavailable` sections → a line in the affected block ("Plantes illisibles dans le jeu pour l'instant"); `unknownCrops` are not shown. `UplinkStatus` (top right of the page): dot + `en direct · DeepWaterGo · cycle 59` or `vu il y a 2 h · cycle 59`, linking to the inspector.

- [ ] **Step 1:** Add tokens and fonts; build `useUplink` and the block components; assemble the page.
- [ ] **Step 2:** `bun run typecheck`, `bun run lint` → clean. `php artisan test --compact --filter=ColonyControllerTest` → still passes.
- [ ] **Step 3: See it.** Start what is missing of the stack (site on :8003, Reverb). Feed the fixture: `php artisan tinker --execute 'app(App\Services\Uplink\ColonyIngestor::class)->ingest(file_get_contents(base_path("tests/Fixtures/Uplink/state.json")));'` (needs foods imported, Task 2 Step 7; otherwise create the two `Food` rows the same way). Open `/colony/viability` with Playwright at 1440 and 390 px wide, screenshot under `.playwright-mcp/`, compare with the mockup, fix, delete the screenshots. Check: no horizontal page scroll at 390 px (only the timeline scrolls), sticky columns hold, focus ring visible on the timeline, contrast AA on the yellow and state colours.
- [ ] **Step 4: Live check.** With the page open, send a client event on the channel from a second browser tab or a small `bunx` script using `pusher-js` (`channel.trigger` is not available on public channels in pusher-js: send the raw frame with a `WebSocket`: subscribe then `{"event":"client-colony.state","channel":"uplink","data":{…cycle 60…}}`). Expected: the page shows cycle 60 within a second. **If the browser does not receive the client event** (Reverb not relaying on a public channel), replace the Echo trigger in `useUplink` by Inertia `usePoll(5000, { only })` and keep the Echo code out; note it in the commit body.
- [ ] **Step 5:** Commit: `feat(colony): food viability page`.

---

### Task 9: Message inspector page

**Files:**
- Create: `resources/js/pages/Colony/Uplink.vue`, `resources/js/components/uplink/JsonTree.vue`, `resources/js/components/uplink/jsonTree.ts` (+ `.test.ts`)

**Interfaces:**
- Consumes: props of Task 6 (`status`, `messages`, `selected`), `useUplink`.
- Produces: `summarize(value: unknown): { kind: 'array' | 'object'; count: number } | { kind: 'literal'; text: string }` in `jsonTree.ts`; `JsonTree.vue` props `value: unknown`, `depth?: number`, `openDepth?: number` (default 1: top-level keys visible, sections folded with their `summarize` text).

Content, per the spec's "Message inspector": connection summary (state, last message time, colony, schema); the list newest first with time, event, size (KB), colony + cycle, outcome as a word (`Enregistré` / `Refusé : schéma plus récent` / `Refusé : JSON illisible` / `Refusé : schéma absent` / `Refusé : colonie absente`) and section errors ("sections illisibles : plants"); selecting a row visits `ColonyController.uplink({ query: { message: id } })` with `preserveScroll` and `only: ['selected']`, showing the payload in `JsonTree` (parse failure → raw text in a `<pre>`), with a "Copier le JSON" button; a Pause / Reprendre toggle that stops `useUplink` reloads (`only: ['messages', 'status']` when running). Same tokens as the viability page, plain table, no sprites.

All strings under `colony.inspector.*` in both catalogs (outcomes, the four reasons, section errors, buttons, summary labels, `items` and `keys` plural strings).

- [ ] **Step 1: Failing Vitest test** for `summarize(value)` returning a structure, not text: `summarize([1,2,3])` → `{ kind: 'array', count: 3 }`, `summarize({a:1})` → `{ kind: 'object', count: 1 }`, `summarize(null)` → `{ kind: 'literal', text: 'null' }`, `summarize('x')` → `{ kind: 'literal', text: '"x"' }`. The component renders counts with `tc('colony.inspector.items', n)` / `tc('colony.inspector.keys', n)`.
- [ ] **Step 2:** Run → fail; implement; run → pass. Build the page.
- [ ] **Step 3:** `bun run typecheck`, `bun run lint`. Playwright check at 1440 and 390 px with the fixture messages (ingest the fixture three times with different cycles and one `schema: 2`): list order, a refused row readable, tree opens, pause holds the list while a new message is ingested.
- [ ] **Step 4:** Commit: `feat(colony): uplink message inspector`.

---

### Task 10: Mod repository and pure core

**Repo:** `/home/mishaa/dev/uplink` (create, `git init -b main`). Reference for build mechanics only: `/home/mishaa/dev/OniExtract2024/tools/wsl/` (GPL: copy no code, the csproj structure and script idea are fine to re-write).

**Files:**
- Create: `Uplink.Core/Uplink.Core.csproj` (netstandard2.0, `Newtonsoft.Json` 13.0.3 as `PackageReference` with `PrivateAssets="all"` + `ExcludeAssets="runtime"`), `Uplink.Core/{Settings,Backoff,LatestOutbox,PusherFrames,Num,ChangeDetector}.cs`, `Uplink.Core.Tests/` (net8.0, xunit 2.9.3, Microsoft.NET.Test.Sdk 17.11.1), `build/Uplink.Wsl.csproj` (net48, compiles `../Uplink/**/*.cs` and `../Uplink.Core/**/*.cs` into one `Uplink.dll`; references `0Harmony`, `Assembly-CSharp`, `Assembly-CSharp-firstpass`, `Newtonsoft.Json`, `UnityEngine*.dll` from `GameLibsFolder` default `/mnt/d/SteamLibrary/steamapps/common/OxygenNotIncluded/OxygenNotIncluded_Data/Managed`, all `Private="false"`; `Microsoft.NETFramework.ReferenceAssemblies` 1.0.3), `build-and-install.sh` (builds, copies `Uplink.dll`, `mod.yaml`, `mod_info.yaml` to `${ONI_MODS_DEV:-/mnt/c/Users/${USER_WIN:-misha}/Documents/Klei/OxygenNotIncluded/mods/dev}/Uplink`; `--no-install` flag; never deletes `uplink.json` there), `mod.yaml` (`title: "Uplink"`, `staticID: "Uplink"`), `mod_info.yaml` (`minimumSupportedBuild: 744825`, `version: 0.1.0`, `APIVersion: 2`), `.gitignore`, `README.md`.
- No LICENSE file until the user picks one (open question, see handoff).

**Interfaces (namespace `Uplink.Core`):**

```csharp
public sealed class Settings {
    public string Url; public string Channel; public float IntervalSeconds;
    public static Settings Parse(string json);        // throws FormatException with a readable message; Channel default "uplink", IntervalSeconds default 5, clamped to [1, 60]; Url required, must start with ws:// or wss://
}
public sealed class Backoff {                          // 1, 2, 4, 8, 16, 30, 30… seconds
    public TimeSpan Next(); public void Reset();
}
public sealed class LatestOutbox<T> where T : class {  // capacity 2, thread-safe; Put drops the OLDEST when full
    public void Put(T item); public bool TryTake(out T item, int millisecondsTimeout, CancellationToken token); public int Dropped { get; }
}
public static class PusherFrames {
    public const string StateEvent = "client-colony.state";
    public static string Subscribe(string channel);                     // {"event":"pusher:subscribe","data":{"channel":"uplink"}}
    public static string Pong();                                        // {"event":"pusher:pong","data":{}}
    public static string ClientEvent(string channel, string eventName, string dataJson); // data embedded as a JSON object, not a string
    public static FrameKind Classify(string frame);                     // Established, SubscriptionSucceeded, Ping, Error, Other
}
public enum FrameKind { Established, SubscriptionSucceeded, Ping, Error, Other }
public static class Num {
    public static double? OrNull(float value);   // NaN / ±Infinity -> null
    public static double? OrNull(double value);
}
public sealed class ChangeDetector {              // remembers the last SENT payload hash
    public bool IsNew(string payload);            // true and remembers when different from the last one; false when identical
    public void Reset();                          // after a reconnect: the next payload is always sent
}
```

- [ ] **Step 1: Failing xunit tests** (`dotnet test Uplink.Core.Tests`):
  - `Settings.Parse("{\"url\":\"ws://127.0.0.1:8080/app/k?protocol=7\"}")` → Channel `uplink`, IntervalSeconds 5; interval 0.1 → 1; missing url or `http://…` → `FormatException`; broken JSON → `FormatException`.
  - `Backoff`: sequence 1, 2, 4, 8, 16, 30, 30 s; after `Reset()` → 1.
  - `LatestOutbox`: `Put("a"); Put("b"); Put("c")` then two `TryTake` give `b`, `c`; `Dropped == 1`; `TryTake` on empty returns false after the timeout; cancelled token → returns false, no exception.
  - `PusherFrames.ClientEvent("uplink", StateEvent, "{\"schema\":1}")` parses to JSON whose `data.schema` is the number 1; `Classify` of `{"event":"pusher:connection_established","data":"{…}"}` → `Established`, `pusher_internal:subscription_succeeded` → `SubscriptionSucceeded`, `pusher:ping` → `Ping`, `pusher:error` → `Error`, garbage text → `Other` (no throw).
  - `Num.OrNull(float.NaN)`, `PositiveInfinity`, `NegativeInfinity` → null; `1.5f` → 1.5.
  - `ChangeDetector`: `IsNew("a")` true, `IsNew("a")` false, `IsNew("b")` true, `Reset()` then `IsNew("b")` true.
- [ ] **Step 2:** Run → fail. Implement. Run → pass.
- [ ] **Step 3:** Add a placeholder `Uplink/Mod.cs` (`public sealed class Mod : KMod.UserMod2` logging `Uplink: loaded` in `OnLoad` after `base.OnLoad`), run `./build-and-install.sh --no-install` → `Uplink.dll` built.
- [ ] **Step 4:** README: what it is, build, install, `uplink.json`, protocol pointer to the site's spec. Commit: `feat: Uplink core and build`.

---

### Task 11: Mod connection and lifecycle

**Files (repo `uplink`):**
- Create: `Uplink/Link/UplinkClient.cs`, `Uplink/Link/Sampler.cs`; modify `Uplink/Mod.cs`
- Throwaway reference for socket code that worked in the game: `/home/mishaa/dev/OniExtract2024/OniExtract2024/colony/ColonySync.cs` (branch `spike/colony-sync`; read for the `ClientWebSocket` usage, re-write rather than copy).

**Interfaces:**
- Consumes: `Uplink.Core` (Task 10).
- Produces:
  - `UplinkClient.Start()` / `Stop()` (idempotent, main thread), `UplinkClient.Status` (`"non configuré"`, `"connexion…"`, `"connecté"`, `"reconnexion dans N s"`), `UplinkClient.Offer(string dataJson)` (called by the sampler: applies `ChangeDetector`, wraps with `PusherFrames.ClientEvent`, `LatestOutbox.Put`; no-op when not subscribed).
  - `Sampler : MonoBehaviour` — coroutine waiting `WaitForSecondsRealtime(settings.IntervalSeconds)` (runs while paused), calling `Func<string> buildState` (Task 12 supplies `ColonyState.Build`; this task uses a stub returning `{"schema":1,"colony":{"id":"stub","name":"stub","cycle":0}}`) inside try/catch, then `UplinkClient.Offer`.
  - Lifecycle: start on colony loaded (Harmony postfix on `Game.OnSpawn`), stop on unload (`Game.OnDestroy` / `Game.DestroyInstances`, whichever the game calls on returning to the main menu — verify in `Assembly-CSharp`). Settings read once per start from `uplink.json` next to the DLL; missing/invalid file → status `non configuré`, one log line, no thread started.
  - Threads: one connection thread owning `ClientWebSocket` (connect → wait `Established` → send `Subscribe` → on `SubscriptionSucceeded` mark subscribed, `ChangeDetector.Reset()`, `Backoff.Reset()` → loop taking from the outbox and sending), one reader thread classifying frames (answer `Ping` with `Pong`, log `Error`). Any failure → close, status `reconnexion dans N s`, sleep `Backoff.Next()` (interruptible by the cancellation token), retry. All threads `IsBackground = true`.
  - Pause screen: a status line "Uplink : connecté" — Harmony postfix on `PauseScreen.OnShow` (or the method the spike's button used, `PauseScreen.ConfigureButtonInfos`) adding/updating one text element; if that proves fragile, fall back to a disabled menu button whose label is the status. Log lines `Uplink: …` in `Player.log` on each state change (not on each send).

- [ ] **Step 1:** Implement client, sampler with the stub state, patches. `./build-and-install.sh`.
- [ ] **Step 2:** In the site repo run `php artisan uplink:install` (Task 4) → `uplink.json` written in the mod folder.
- [ ] **Step 3: Ask the user** to enable the mod "Uplink" (and disable the spike button's sync in OniExtract), load their colony, and check: no click needed; `/colony/uplink` lists `client-colony.state` messages **stored** as colony `stub`. Then stop Reverb for 20 s and restart it: messages resume by themselves, game stays smooth. Read `Player.log` (`/mnt/c/Users/misha/AppData/LocalLow/Klei/Oxygen Not Included/Player.log`) for `Uplink:` lines and exceptions.
- [ ] **Step 4:** Delete the `stub` colony row (`php artisan tinker --execute 'App\Models\Colony::where("guid","stub")->delete();'`). Commit (mod repo): `feat: connect to the site and follow the colony lifecycle`.

---

### Task 12: Mod snapshot sections

**Files (repo `uplink`):**
- Create: `Uplink/Snapshot/{ColonyState,ColonySection,WorldsSection,DuplicantsSection,FoodSection,PlantsSection,CrittersSection,PrintingPodSection}.cs`
- Modify: `Uplink/Mod.cs` (sampler uses `ColonyState.Build`), `README.md` ("What the game gave us" table)
- Throwaway reference: `/home/mishaa/dev/OniExtract2024/OniExtract2024/colony/ColonySnapshot.cs` (APIs that worked: `Components.LiveMinionIdentities`, `Db.Get().Amounts.Calories.Lookup(go)`, `Components.Edibles`, `Components.Crops`, `Growing`, `ReceptacleMonitor.Replanted`, `WiltCondition`, `Components.Brains`).

**Interfaces:**
- Produces: `ColonyState.Build(): string` — the `data` object of the spec's Part 2, `schema: 1`, sections built in isolation: each `XSection.Build()` returns a `JToken`; `ColonyState` wraps each call in try/catch and writes `{ "error": "<message>" }` for a failed section, logging it once per section per session. All floats through `Num.OrNull`. Field names and nesting exactly as in the spec's JSON example (the site's fixture `tests/Fixtures/Uplink/state.json` is the contract; the spike's extra fields — `attributes`, `skills`, `stress`, `power`, `maturity`, `effects` — are **not** sent).

Field sources to establish. For each of the six "to verify first" items, inspect the game's code (REA `mcp__rea__*` tools if available, else Cecil/ILSpy over `Assembly-CSharp.dll`; search terms given) and write the finding in the README table (field, API used, or "not readable"):

| Field | Where to look |
|---|---|
| `colony.id` stable across saves | `SaveGame.Instance` / `SaveLoader.Instance.GameInfo` (`colonyGuid`) |
| `duplicants[].id` stable | `KPrefabID.InstanceID` of the minion (persisted in saves), as a string |
| `duplicants[].forbiddenFoods` | `ConsumableConsumer.forbiddenTagSet` (or `IsPermitted(string)`) on the minion; send only food ids |
| `plants[].autoHarvest` | `HarvestDesignatable.HarvestWhenReady` on the plant (null when the component is absent) |
| `printingPod.secondsUntilNextPrint` | `Immigration.Instance` (`timeBeforeSpawn` / `GetTimeRemaining()`) |
| `critters[].drops` | `Butcherable` (`drops` array/dictionary) on the critter prefab; units per id |

Other fields: `plants[].growthPerSecond` = the maturity amount's `GetDelta()` (maturity-cycles per second, as in the spike's `maturity.deltaPerSecond`); `secondsUntilHarvest` as the spike computed it; `planted` = `ReceptacleMonitor.Replanted`; `wilting` = `WiltCondition.IsWilting()`; `grown` = `Growing.IsGrown()`; tree branches (`SeaTreeBranch` and other branch prefabs): skipped unless they carry their own crop that a dupe harvests — decide from the game's code and state it in the README; `critters[].tame` = wildness amount value `<= 0` (or the `Wild` tag absent); `food[]` grouped by prefab id and `worldId` with summed `units` and `calories`; `worlds[]` from `ClusterManager.Instance.WorldContainers` (skip module interiors); `colony.dlcs` from the save's active DLC ids; `colony.build` = `BuildWatermark.GetBuildText()` or the equivalent used by OniExtract.

If one of the six is not readable: send `null` for that field (or omit `drops`), write "not readable" in the README, and tell the user in the task report — the site already handles each case.

- [ ] **Step 1:** Investigate the six items; fill the README table.
- [ ] **Step 2:** Implement the sections; `./build-and-install.sh`.
- [ ] **Step 3: Ask the user** to load the colony. Then check on the site: `/colony/uplink` shows stored messages with colony `DeepWaterGo` and no section error; open one and compare field names with `tests/Fixtures/Uplink/state.json` key by key (write a 10-line throwaway script in the scratchpad that diffs the key sets per section, do not commit it); size and build time are logged once at start (`Uplink: state built in N ms, M bytes`) and stay near the spike's 4–19 ms.
- [ ] **Step 4:** Commit (mod repo): `feat: send the colony state`. If a field name had to differ from the spec, change the **mod** to match the spec, not the site.

---

### Task 13: End-to-end verification with the user

**Files:** none new. Site repo.

- [ ] **Step 1:** Run `php artisan test --compact`, `bun run typecheck`, `bun run lint`, `bunx vp test`, and in the mod repo `dotnet test Uplink.Core.Tests` → all green.
- [ ] **Step 2:** With the user's colony loaded, walk the spec's "Verification with the user" list and record each result:
  1. the mod connects with no click;
  2. `/colony/viability` shows their duplicants, stock and arrivals with the game's art;
  3. the "sans récolte" figure matches a hand check of stock ÷ consumption (compute it from the latest stored state and show both numbers to the user);
  4. toggling auto-harvest on a wild plant in game moves its marks between the two wild styles within seconds;
  5. `/colony/uplink` lists messages as they arrive and opens one as readable JSON;
  5b. with `APP_LOCALE=en` (then back to the user's value) both pages and `uplink:install` read fully in English, no French left, no raw key shown;
  6. closing the game leaves the page readable with its age shown.
- [ ] **Step 3:** Remove the spike leftovers: in `/home/mishaa/dev/OniExtract2024` tell the user the branch `spike/colony-sync` and the installed `colony_sync.json` can be discarded (do not delete them without their go-ahead); delete `storage/app/colony-spike/` only with their go-ahead.
- [ ] **Step 4:** Update the memory note `project_colony_sync_mod.md` (mod repo path, protocol, `uplink:install`) and report what was verified, what was not readable in the game, and the deferred items (language switcher and translation of the older pages, duplicant portraits, per-asteroid split, spoilage, licence of the mod repo).
