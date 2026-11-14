# Zone captures Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The game renders a zone to a PNG on demand or after a building spree; the site stores the last 15 per zone, shows them on the zone page and prints the latest path for Claude.

**Architecture:** The Uplink mod decides when to capture (pure `CaptureScheduler` in `Uplink.Core`, state saved in the save through `ZoneStore`), renders with the game's own timelapse path (`CameraController.RenderForTimelapser` behind the freeze camera), writes the PNG in its mod folder and sends a small reliable `client-zone.capture` message. The site job copies the file from the mod folder (`game.uplink_mod_path`) to the public disk, records a `zone_captures` row, prunes to 15 and broadcasts `zone.updated` (kind `capture`). Manual captures go through the existing command channel (`zone.capture`).

**Tech Stack:** C# net48 Unity mod + `Uplink.Core` (netstandard2.0, xUnit tests in `Uplink.Core.Tests`); Laravel 13, Pest, Inertia v3 Vue, spatie laravel-data + typescript-transformer, Wayfinder.

**Spec:** `docs/superpowers/specs/2026-11-14-zone-captures-design.md`

## Global Constraints

- Repos: mod `~/dev/uplink` (branch `feat/zone-captures`, created from `feat/zone-inspect-and-rooms-overlay`), site `~/dev/ony-planner` (branch `feat/zone-captures` from `main`).
- Commits: no GPG signature (`git -c commit.gpgsign=false commit`), no Co-Authored-By line.
- bun/bunx only, never npm/npx. Vue SFCs use `lang="ts"`; template sections use `<!-- region -->` markers like their siblings.
- Front formatting: `NODE_OPTIONS='--experimental-strip-types' bunx vp fmt <file>`; PHP: `vendor/bin/pint --dirty --format agent`; TS types: `php artisan typescript:transform`.
- Mod tests: `dotnet test Uplink.Core.Tests`; mod build+install: `./build-and-install.sh` (the player restarts the game after an install).
- After a PHP change touching Uplink jobs: `php artisan queue:restart`.
- Settle delay 120 game seconds; automatic guard 600 real seconds per zone; keep 15 captures per zone; 32 pixels per cell; capture box = the slow-state box (`CellBox.Around(zone.Cells, gridWidth, gridHeight, ZoneSampler.Margin)`).
- No image bytes through Reverb.

## Review Focus

1. A capture whose PNG is missing in the mod folder (deleted, path misconfigured): the site acks, records nothing, marks the command failed with a reason, and the artisan command reports it instead of waiting forever. → Task 5 test `it('acks and fails the command when the capture file is missing')`.
2. A zone deleted in the game while its capture is in flight: the job acks and drops it without error. → Task 5 test `it('drops a capture of an unknown zone')`.
3. Game paused during a building spree: the settle delay does not elapse (game seconds frozen), no capture fires. → Task 1 test `DueWaitsForGameTimeNotRealTime`.
4. Loading an older save without capture state, or a save whose state names deleted zones: no crash, deleted zones forgotten, existing zones count as never captured. → Task 1 tests `FromJsonToleratesNullAndGarbage`, `KeepOnlyForgetsDeletedZones`.
5. The 16th capture: oldest row and its file both deleted, nothing else. → Task 5 test `it('keeps the 15 most recent captures of a zone and deletes the oldest file')`.

---

### Task 1: Capture trigger logic (mod, pure)

**Files:**
- Create: `Uplink.Core/Zones/CaptureScheduler.cs`
- Test: `Uplink.Core.Tests/CaptureSchedulerTests.cs`

**Interfaces:**
- Produces:
  - `public sealed class CaptureScheduler` with `public const double SettleGameSeconds = 120; public const double GuardRealSeconds = 600;`
  - `void Observe(string zoneId, string signature, double gameSeconds)` — a layout check saw this signature; remembers the game time it last changed.
  - `List<string> Due(double gameSeconds, double utcSeconds)` — zone ids needing an automatic capture: observed signature differs from the last capture's (or no capture yet), unchanged for ≥ `SettleGameSeconds`, and last capture ≥ `GuardRealSeconds` ago (or none).
  - `void Captured(string zoneId, string signature, double gameSeconds, double utcSeconds)` — any successful capture, manual or automatic.
  - `void KeepOnly(IEnumerable<string> liveZoneIds)`
  - `string ToJson()` / `static CaptureScheduler FromJson(string json)` — persists only the last capture per zone (`signature`, `gameSeconds`, `utcSeconds`); observations are not saved. `utcSeconds` is Unix time (survives game restarts, unlike `Time.unscaledTime`).

- [ ] **Step 1: Write the failing tests** (xUnit, in `Uplink.Core.Tests/CaptureSchedulerTests.cs`)
  - `NewZoneIsDueOnceSettled`: Observe("a","s1",0); `Due(119, 0)` empty; `Due(120, 0)` == ["a"].
  - `ChangingLayoutRestartsTheSettleDelay`: Observe s1 at 0, Observe s2 at 100 → `Due(150,0)` empty, `Due(220,0)` == ["a"].
  - `DueWaitsForGameTimeNotRealTime`: Observe at 0; `Due(10, 99999)` empty.
  - `CapturedLayoutIsNotDueAgain`: Observe s1 at 0, Captured("a","s1",130,1000) → `Due(10000, 99999)` empty.
  - `GuardSpacesAutomaticCaptures`: Captured s1 at utc 1000; Observe s2 at game 0 → `Due(500, 1599)` empty, `Due(500, 1600)` == ["a"].
  - `ManualCaptureCountsAsLastCapture`: Observe s1 at 0; Captured("a","s1",50,1000) → `Due(200, 5000)` empty.
  - `PersistsLastCapturesNotObservations`: Captured a/s1; Observe b/x; `FromJson(ToJson())` then Observe a/s1 at 0 → `Due(1000, 1e9)` empty; Observe b/x at 0 → b due at 120.
  - `FromJsonToleratesNullAndGarbage`: `FromJson(null)`, `FromJson("")`, `FromJson("{")` return an empty scheduler (no throw).
  - `KeepOnlyForgetsDeletedZones`: Captured a and b, Observe a and b; `KeepOnly(["a"])` → after Observe b again at 0, b due at 120 as a never-captured zone; round-trip JSON no longer contains b.

- [ ] **Step 2: Run** `dotnet test Uplink.Core.Tests --filter CaptureSchedulerTests` — Expected: FAIL (type missing).
- [ ] **Step 3: Implement `CaptureScheduler`** with two `Dictionary<string, …>(StringComparer.Ordinal)`: observations (signature, changedAtGameSeconds) and last captures; JSON with Newtonsoft (`JObject`), unknown/invalid entries skipped.
- [ ] **Step 4: Run** the same filter — Expected: PASS; then `dotnet test Uplink.Core.Tests` all green.
- [ ] **Step 5: Commit** `feat: zone capture scheduler`.

### Task 2: Capture message and layout signature on the wire (mod, pure)

**Files:**
- Modify: `Uplink.Core/PusherFrames.cs` (add `public const string ZoneCaptureEvent = "client-zone.capture";`)
- Modify: `Uplink.Core/Zones/ZoneMessages.cs` (layout gains `"signature"`; new `Capture`)
- Test: `Uplink.Core.Tests/ZoneMessagesTests.cs`

**Interfaces:**
- Produces:
  - `ZoneMessages.Layout(…, int? borderCells = null, string signature = null)` writes `"signature"` after `"version"` when not null.
  - `public static string Capture(string messageId, string colonyId, string sessionId, string zoneId, double gameSeconds, string signature, string trigger, string commandId, string fileName, int width, int height, string failure)` — reliable message: `Header` (schema, id), `colonyId`, `sessionId`, `zoneId`, `gameSeconds`, `signature`, `trigger` (`"manual"` | `"auto"`), `commandId` (null for auto), then either `fileName`, `width`, `height` (success) or `failure` (string, no file fields).

- [ ] **Step 1: Write the failing tests**
  - `LayoutCarriesItsSignature`: `JObject.Parse(ZoneMessages.Layout(..., signature: "abc"))["signature"]` == "abc"; without it, no `signature` key.
  - `CaptureSuccessCarriesTheFile`: parsed fields `zoneId`, `trigger`="manual", `commandId`="c1", `fileName`="20261114-153000.png", `width`=1216, `height`=736, no `failure` key.
  - `CaptureFailureCarriesOnlyTheReason`: `failure`="zone on another world", no `fileName`/`width`/`height`, `commandId` null for auto.
- [ ] **Step 2: Run** `dotnet test Uplink.Core.Tests --filter ZoneMessagesTests` — Expected: FAIL.
- [ ] **Step 3: Implement** both, reusing `Build`, `Header`, `Str`, `OptionalStr`, `Num` of `ZoneMessages`.
- [ ] **Step 4: Run** — Expected: PASS (whole suite green).
- [ ] **Step 5: Commit** `feat: zone capture message, layout signature on the wire`.

### Task 3: Rendering, triggers and the `zone.capture` command (mod, game)

**Files:**
- Create: `Uplink/Zones/ZoneCapturer.cs`
- Modify: `Uplink/Zones/ZoneSampler.cs` (pass signature to `ZoneMessages.Layout`; `Observe` after computing it; `Due` on each slow pass; register `zone.capture`)
- Modify: `Uplink/Zones/ZoneStore.cs` (`[Serialize] private string captureStateJson;` written in `OnSerializing` from `Captures.ToJson()`, read lazily like `zonesJson`; `public CaptureScheduler Captures { get; }`; `KeepOnly` with live zone ids on `OnZonesChanged`)

**Interfaces:**
- Consumes: Task 1 `CaptureScheduler`, Task 2 `ZoneMessages.Capture`, `PusherFrames.ZoneCaptureEvent`.
- Produces: `ZoneCapturer : MonoBehaviour` (one per sampler GameObject) with `void Request(Zone zone, string trigger, string commandId)`; requests are queued and run one at a time.

Rendering (copy the game's `Timelapser` sequence, verified in Assembly-CSharp):
frame 1 at `WaitForEndOfFrame`: read the screen into a texture, `CameraController.Instance.timelapseFreezeCamera` + its `FillRenderTargetEffect.SetFillTexture`, enable the freeze camera, save `OverlayScreen.Instance.mode` and switch to `OverlayModes.None.ID`, `DebugHandler.SetTimelapseMode(true, zone.WorldId)`, save camera position and `CameraController.Instance.OrthographicSize`, set `OrthographicSize = box.Height / 2f` and `SetPosition` to the box centre (keep z); frame 2: `RenderTexture(box.Width * 32, box.Height * 32, 32, ARGB32)`, `CameraController.Instance.RenderForTimelapser(ref rt)`, `ReadPixels` into a `Texture2D`, `EncodeToPNG`, write `<mod folder>/captures/<zoneId>/<yyyyMMdd-HHmmss>.png` (mod folder = `Path.GetDirectoryName(Assembly.GetExecutingAssembly().Location)`), then restore size, position, overlay, `SetTimelapseMode(false)`, disable the freeze camera. Wrap everything in try/finally so the view is always restored; on success `ZoneStore.Instance.Captures.Captured(...)` and send `Capture(... fileName, width, height, failure: null)` with `UplinkClient.SendReliable(PusherFrames.ZoneCaptureEvent, id => ...)`; on exception log once and send the failure message.

Command `zone.capture` (payload `{zoneId}`), synchronous checks in the handler: unknown zone → `CommandResult.Refused("unknown zone")`; `zone.WorldId != ClusterManager.Instance.activeWorldId` → `Refused("zone on another world")`; zone without cells → `Refused("empty zone")`; otherwise `Request(zone, "manual", command.Id)` and `Done()`. Automatic: on each slow pass, for each id in `Captures.Due(gameSeconds, utcNow)` whose zone is on the active world, `Request(zone, "auto", null)`; zones on another world simply stay due.

- [ ] **Step 1: Implement** the three files as above.
- [ ] **Step 2: Build** `./build-and-install.sh --no-install` — Expected: `Build succeeded`, 0 warnings.
- [ ] **Step 3: Run** `dotnet test Uplink.Core.Tests` — Expected: PASS.
- [ ] **Step 4: Install** `./build-and-install.sh`; tell the player to restart the game. Manual check (player): `php artisan uplink:capture 65` after Task 7, or watch `Player.log` for `Uplink: zone … capture` lines; the screen must not jump.
- [ ] **Step 5: Commit** `feat: render zone captures in game, zone.capture command and automatic captures`.

### Task 4: `zone_captures` table and model (site)

**Files:**
- Create: migration `create_zone_captures_table`, `app/Models/ZoneCapture.php`, `database/factories/ZoneCaptureFactory.php`
- Modify: `app/Models/Zone.php` (`captures(): HasMany` ordered by `taken_at` desc)
- Test: `tests/Feature/Models/ZoneCaptureTest.php`

**Interfaces:**
- Produces: columns `id`, `zone_id` (fk cascade on delete), `path` (string, public-disk relative: `zone-captures/{zone_guid}/{file}`), `taken_at` (timestamp), `game_seconds` (double), `layout_signature` (string, nullable), `trigger` (string: `manual`|`auto`), `command_id` (uuid, nullable, indexed), `width`, `height` (int), timestamps. `ZoneCapture::KEEP = 15`. Accessor `cycle(): int` = `intdiv((int) game_seconds, 600)`.

- [ ] **Step 1: Write the failing test** `it('belongs to its zone, newest first, and gives the game cycle')`: two captures via factory on one zone, `$zone->captures->first()->taken_at` is the latest; `game_seconds = 247_500` → `cycle` 412.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Models/ZoneCaptureTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** with `php artisan make:model ZoneCapture -mf --no-interaction`, following `ZoneVersion`'s conventions (strict types, `#[Fillable]`, casts).
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** `feat: zone_captures table`.

### Task 5: Receive and store a capture (site)

**Files:**
- Create: `app/Actions/Uplink/StoreZoneCapture.php`, `app/Jobs/Uplink/ReceiveZoneCapture.php`
- Modify: `app/Enums/UplinkMessageType.php` (`case ZoneCapture = 'client-zone.capture';`, reliable, job mapping), `app/Models/UplinkCommand.php` only if a `FAILED` status constant is missing (use `REFUSED` with the failure as reason otherwise)
- Test: `tests/Feature/Uplink/ReceiveZoneCaptureTest.php`

**Interfaces:**
- Consumes: Task 4 model.
- Produces: `StoreZoneCapture::handle(array $message): bool` — false when the zone is unknown. On success: copies `{game.uplink_mod_path}/captures/{zoneId}/{fileName}` to the `public` disk at `zone-captures/{zone_guid}/{fileName}`, creates the row (`taken_at` = now), deletes captures beyond `ZoneCapture::KEEP` (rows and files), dispatches `ZoneUpdated($zoneGuid, $colonyGuid, 'capture')`. On `failure`, or a missing source file: no row; when `commandId` is set, sets that `UplinkCommand` to `REFUSED` with the reason (`'capture file missing'` for a missing file); dispatches `ZoneUpdated(..., 'capture')`. Always acked by the job (a drop is logged with `$reader->drop`).

- [ ] **Step 1: Write the failing tests** (fake the mod folder with `config(['game.uplink_mod_path' => $tmp])`, `Storage::fake('public')`, `Event::fake([ZoneUpdated::class])`, frames built like the existing `ReceiveZoneLayoutTest`):
  - `it('copies the capture, records it and broadcasts it')`: file exists on the fake disk, row has `trigger`, `layout_signature`, `width`/`height`, `ZoneUpdated` kind `capture`, message acked.
  - `it('keeps the 15 most recent captures of a zone and deletes the oldest file')`: 15 existing rows+files, store a 16th → 15 rows, oldest file gone.
  - `it('acks and fails the command when the capture file is missing')`: command row → `REFUSED`, reason `capture file missing`, no capture row, acked.
  - `it('records the failure of a manual capture on its command')`: message with `failure` → command `REFUSED` with that reason.
  - `it('drops a capture of an unknown zone')`: acked, nothing stored.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Uplink/ReceiveZoneCaptureTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** the enum case, job (validation like `ReceiveZoneList`: `id`, `colonyId`, `zoneId` strings; `fileName` must match `/^[\w.-]+\.png$/` — reject path traversal), action.
- [ ] **Step 4: Run** — Expected: PASS; `php artisan queue:restart`.
- [ ] **Step 5: Commit** `feat: store zone captures sent by Uplink`.

### Task 6: Captures on the zone page data and the capture request (site)

**Files:**
- Create: `app/Data/ZoneCaptureData.php`, `app/Http/Controllers/ZoneCaptureController.php`
- Modify: `app/Data/ZoneShowData.php` (`captures: list<ZoneCaptureData>`, `captureRequest: ?array{status: string, reason: ?string}`), `app/Services/Zones/ZonePresenter.php`, `routes/web.php` (`POST /zones/{zone}/captures` → `ZoneCaptureController@store`, name `zones.captures.store`)
- Test: `tests/Unit/Services/ZonePresenterTest.php`, `tests/Feature/ZoneCaptureControllerTest.php`

**Interfaces:**
- Produces: `ZoneCaptureData { int $id; string $url; string $takenAt (ISO-8601); int $cycle; string $trigger; bool $matchesLayout; int $width; int $height; }` — `url` root-relative (`/storage/zone-captures/...`), `matchesLayout` = `layout_signature !== null && layout_signature === $zone->layout['signature']`. `captureRequest` = the latest `zone.capture` `UplinkCommand` whose payload `zoneId` is this zone's guid (null if none). `ZoneCaptureController::store(Zone $zone, SendUplinkCommand $send): RedirectResponse` sends `zone.capture` with `['zoneId' => $zone->zone_guid]`, then `back()`.

- [ ] **Step 1: Write the failing tests**
  - presenter `it('lists the zone captures newest first and says which match the current layout')`.
  - presenter `it('reports the latest capture request of the zone')` (pending, then refused with reason).
  - controller `it('asks the mod for a capture of the zone')`: POST → one `UplinkCommand` type `zone.capture` with the zone guid, redirect back; archived zone → 404.
- [ ] **Step 2: Run** both files — Expected: FAIL.
- [ ] **Step 3: Implement**; run `php artisan storage:link` once (the public disk is not linked yet); regenerate TS types and format them.
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** `feat: zone captures in the zone page data, capture request route`.

### Task 7: `uplink:capture` artisan command (site)

**Files:**
- Create: `app/Console/Commands/UplinkCaptureCommand.php` (same zone lookup as `UplinkResyncZoneCommand`)
- Test: `tests/Feature/Console/UplinkCaptureCommandTest.php`

**Interfaces:**
- Produces: `uplink:capture {zone : Zone id or zone guid} {--timeout=60 : Seconds to wait}` — sends `zone.capture`, polls every 500 ms for a `ZoneCapture` with that `command_id`, or the command turning `REFUSED`/`EXPIRED`. Success prints the absolute path (`Storage::disk('public')->path(...)`) and `matches the current site layout` or `taken on an older layout`; refusal prints the reason and fails; timeout fails with `No capture after N s: is the game running and linked?`.

- [ ] **Step 1: Write the failing tests** with a short `--timeout=1`: capture row created by a fake (insert the row in a `beforeEach` keyed to the command once created — use `Event::listen(UplinkCommandIssued::class, …)` to create it) → output contains the absolute path, success; refused command → failure with reason; nothing → timeout message, failure; unknown zone → failure.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** (inject a sleep closure or use `Sleep::for()->milliseconds(500)` so tests can `Sleep::fake()`).
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** `feat: uplink:capture command`.

### Task 8: Captures tab (site front)

**Files:**
- Create: `resources/js/components/zones/ZoneCaptures.vue`
- Modify: `resources/js/pages/Zones/Show.vue` (third tab `captures`, props `captures`/`captureRequest` added to the live `only` list), `resources/js/composables/useZoneLive.ts` (`kind` union gains `'capture'`), `lang/fr/zones.php` (and `lang/en` if present) under `zones.show.tabs.captures` and `zones.captures.*`

**Interfaces:**
- Consumes: Task 6 props, Wayfinder `ZoneCaptureController.store`.
- Produces: tab showing the selected capture at full width (default the latest) with `il y a 3 min · cycle 412`, trigger label (`Manuelle`/`Automatique`) and a `Plan actuel`/`Plan plus ancien` badge; a strip of the others (click selects); a `Capturer` button posting with Inertia `router.post` (`preserveScroll`), disabled with a tooltip `Le jeu n'est pas relié` while `live` (from `useZoneLive`) is false, showing `Capture en cours…` while `captureRequest.status === 'pending'`, and the refusal reason when `refused`. Empty state: `Aucune capture pour l'instant`.

- [ ] **Step 1: Implement** following `ZoneEntities.vue`'s structure and styles (regions, tokens, `lang="ts"`).
- [ ] **Step 2: Run** `bunx vitest run resources/js/components/zones` and `bunx eslint resources/js/components/zones/ZoneCaptures.vue resources/js/pages/Zones/Show.vue` — Expected: PASS / no errors.
- [ ] **Step 3: Check in the browser** (`http://localhost:8003/zones/65`): tab, empty state, button state.
- [ ] **Step 4: Commit** `feat: captures tab on the zone page`.

### Task 9: End-to-end check, docs, merges

**Files:**
- Modify: docs pages listed by `php artisan larecipe:affected --uncommitted --base=main` (expected `product/colony`, `development/uplink`), via the `larecipe-update-docs` skill; show the docs diff to the player before committing (review mode `required`).

- [ ] **Step 1:** With the game running and linked: `php artisan uplink:capture 65` → prints a path; open the PNG and compare it with `/zones/65`.
- [ ] **Step 2:** Build a few tiles in a zone, wait ~2 game minutes → an automatic capture appears in the tab.
- [ ] **Step 3:** Docs update + `php artisan larecipe:validate`; commit after the player approves the diff.
- [ ] **Step 4:** Merge (fast-forward, no push) once the player confirms: uplink `feat/zone-inspect-and-rooms-overlay` + `feat/zone-captures` → `main`; OniExtract `feat/stair-caps` → `master`; site `feat/zone-captures` → `main`.
