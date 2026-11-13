# Uplink sync orchestration (roadmap 1b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move Uplink ingestion off the Reverb process onto PostgreSQL + Redis queues, one class per message type, session-safe ordering, domain events, a site→mod command envelope, pluggable zone sections in the mod, and two levels of performance baselines.

**Architecture:** Reverb listener only routes frames to one queued job per message type (`uplink-reliable`: single ordered worker; `uplink-state`: several workers). Jobs decode, check their own schema, store with conditional writes keyed by session + `gameSeconds`, then raise domain events; listeners broadcast `ZoneUpdated`, acks and commands. The mod composes zone sections and answers commands.

**Tech Stack:** Laravel 13 / PHP 8.5, PostgreSQL 18, Valkey (Redis protocol, `phpredis`), Reverb, Pest; mod in C# (netstandard2.0 core + net48 game assembly), xUnit-style tests in `Uplink.Core.Tests` (net8.0).

**Spec:** `docs/superpowers/specs/2026-11-13-uplink-sync-orchestration-design.md`

## Global Constraints

- Site: PostgreSQL for all data (tests included), Redis for queue + cache + locks (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`).
- Queues: `uplink-reliable` (session start, zone list, zone layout, command ack) consumed by **exactly one** worker; `uplink-state` (slow, fast, colony state) by 4 workers in dev.
- Reverb listener: no payload decode, no database access, never throws.
- Versioning is per message type; additive optional fields never bump `schema`; new command messages start at `schema` 1; each handler refuses a newer schema than it knows.
- Reloading an older save must never interfere with history: never compare or mix values from two sessions.
- Mod wire output of existing fields stays identical; only additive fields (`sessionId` on zone states; `sessionId` + `gameSeconds` on colony state) and new command messages.
- Commands: `uplink.command` on `uplink-data`, `{schema, id, colonyId, type, issuedAt, expiresAt, payload}` (UTC ISO-8601); ack `client-command.ack` `{schema, id, colonyId, commandId, status: done|refused|expired, reason?}` where `id` is the ack's own reliable message id (acked back like any reliable message) and `commandId` the command's `id`.
- Level 1 performance tests run in the normal suite with budgets ≈ 3× the median measured on the user's machine (write the measured value next to each budget).
- PHP: `declare(strict_types=1)`, `final` classes, explicit types, PHPDoc array shapes, `vendor/bin/pint --dirty --format agent` before each commit. Commits: `git -c commit.gpgsign=false commit`, no co-author line.
- Never `npm`/`npx`; `bun`/`bunx` only.
- Docs (LaRecipe) ship in the same push: run `php artisan larecipe:affected --uncommitted --base=main`, update pages with the `larecipe-update-docs` skill, `php artisan larecipe:validate`; show the docs diff to the user before committing it.

## Review Focus

1. **Old-session state still queued after the user reloads a save** → it must be dropped, never stored over the new session's state (Task 4: `drops a state from a previous session even when its gameSeconds is higher`).
2. **Layout arriving for a zone the list has not created yet** (reconnect burst) → not acked, so the mod resends; no exception, no partial row (Task 3: `does not ack a layout for an unknown zone`).
3. **Garbage or huge frame on the socket** (wrong JSON, unknown event, 5 MB layout) → listener returns without throwing and without decoding (Task 2: `ignores malformed frames without throwing`, `does not decode the payload`).
4. **Command for a colony that is not loaded** (another save open) → mod ignores it, site marks it expired after `expiresAt` (Task 5: `expires unanswered commands`; Task 10: `ignores a command for another colony`).
5. **Older mod without the additive fields** → zone states treated as current session; colony state last-write-wins (Task 4: `treats a state without sessionId as current`; Task 4: `stores a colony state without session fields`).

---

### Task 1: PostgreSQL + Redis stack

**Files:**
- Modify: `.env.example`, `phpunit.xml`, `composer.json` (`scripts.dev`), `config/database.php` only if a default must change
- Modify: `docs/site/development/local-setup.md` (with the docs skill)
- Test: whole suite

**Interfaces:**
- Produces: tests run on PostgreSQL database `oni_planner_testing` (socket `/run/postgresql`); dev runs Redis queue workers.

- [ ] **Step 1:** Create the databases: `createdb oni_planner && createdb oni_planner_testing` (role `mishaa` exists, socket auth, no password).
- [ ] **Step 2:** `.env.example`: `DB_CONNECTION=pgsql`, `DB_HOST=/run/postgresql`, `DB_DATABASE=oni_planner`, `DB_USERNAME=`, `DB_PASSWORD=`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_CLIENT=phpredis`, `REDIS_HOST=127.0.0.1`. Tell the user which `.env` keys to change locally (do not print values of secrets).
- [ ] **Step 3:** `phpunit.xml`: `DB_CONNECTION=pgsql`, `DB_DATABASE=oni_planner_testing`, `DB_HOST=/run/postgresql`; keep `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `BROADCAST_CONNECTION=null`.
- [ ] **Step 4:** Run `php artisan test --compact --exclude-group=browser` (or the non-browser directories). Expected first: failures from SQLite-only behaviour. Fix each in place (typical: case-sensitive `like`, JSON column comparisons, boolean casts, ordering without `orderBy`). Re-run until green.
- [ ] **Step 5:** `composer.json` `dev`: replace `php artisan queue:listen --tries=1` with two named processes: `php artisan queue:work redis --queue=uplink-reliable --tries=1 --memory=256` (one) and `php artisan queue:work redis --queue=uplink-state,default --tries=1 --memory=256` started 4 times (e.g. a small `bin/queue-workers` script using `bunx concurrently`), and document that workers must be restarted after a PHP change (`php artisan queue:restart`). Workers that stop on the memory limit are restarted by `concurrently --restart-tries=-1` or the script's loop.
- [ ] **Step 6:** Point the user's local `.env` at PostgreSQL, run `php artisan migrate` and `php artisan game:import` (allowed without asking), ask the user to launch the game so the mod resends session, list and layouts; confirm zones reappear (`/zones`). Keep `database/database.sqlite` untouched.
- [ ] **Step 7:** Update `development/local-setup` (PostgreSQL + Valkey install, databases, workers). Commit: `chore: run on PostgreSQL and Redis`.

### Task 2: Message types and the routing listener

**Files:**
- Create: `app/Uplink/UplinkMessageType.php` (enum), `app/Jobs/Uplink/UplinkMessageJob.php` (abstract), one job per type in `app/Jobs/Uplink/`: `ReceiveSessionStart`, `ReceiveZoneList`, `ReceiveZoneLayout`, `ReceiveZoneSlowState`, `ReceiveZoneFastState`, `ReceiveColonyState`, `ReceiveCommandAck`
- Create: `app/Listeners/RouteUplinkMessage.php`
- Delete: `app/Listeners/IngestUplinkData.php`, `app/Listeners/IngestColonyState.php` (their tests move, see below)
- Create: `app/Events/Uplink/UplinkMessageAccepted.php`
- Test: `tests/Feature/Uplink/RouteUplinkMessageTest.php`, `tests/Feature/Uplink/UplinkMessageJobsTest.php` (move the behavioural cases of `IngestUplinkDataTest.php` and `IngestColonyStateTest.php` here, against the jobs)

**Interfaces:**
- Produces:
  - `enum UplinkMessageType: string` cases `SessionStart='client-session.start'`, `ZoneList='client-zone.list'`, `ZoneLayout='client-zone.layout'`, `ZoneSlowState='client-zone.slow'`, `ZoneFastState='client-zone.fast'`, `ColonyState='client-colony.state'`, `CommandAck='client-command.ack'`; methods `channel(): string` (`uplink` for `ColonyState`, `uplink-data` otherwise), `queue(): string` (`uplink-reliable` for SessionStart, ZoneList, ZoneLayout, CommandAck; `uplink-state` otherwise), `job(): class-string<UplinkMessageJob>`, `schema(): int` (1 for all), `isReliable(): bool`; static `fromFrame(?string $channel, ?string $event): ?self`.
  - `abstract class UplinkMessageJob implements ShouldQueue` with `__construct(public string $frame)`; `final handle(): void` decodes `data` with `UplinkPayload::decode`, refuses `schema` missing/newer than `static::type()->schema()` (log once per colony like today), calls `abstract protected function process(array $message): bool`, and for reliable types dispatches `UplinkMessageAccepted($id)` when `process` returned true (so acks never break between tasks).
  - `App\Events\Uplink\UplinkMessageAccepted(string $id)` implements `ShouldBroadcastNow`, channel `uplink-data`, `broadcastAs` `uplink.ack`, `broadcastWith` `['id' => $id]`. `abstract public static function type(): UplinkMessageType`. `$tries = 1`.
  - `ReceiveColonyState::process` delegates to `ColonyIngestor::ingest($this->frame)` (keeps journaling).

- [ ] **Step 1: Write the failing listener tests** (`Queue::fake()`):
  - `it('queues each message type on its queue')` — dataset of the 7 types: build a frame with `uplinkData(...)`, fire `MessageReceived`, `Queue::assertPushedOn($type->queue(), $type->job())`.
  - `it('does not decode the payload')` — frame with `data` = `{"payload":"<invalid base64 !!>"}` for `client-zone.slow` is still pushed (decode happens in the job).
  - `it('does not touch the database')` — `DB::listen` counter stays 0 while routing 10 frames.
  - `it('ignores malformed frames without throwing')` — dataset: `''`, `'{'`, `'{"event":"client-zone.slow"}'` (no channel), `pusher:ping`, unknown `client-foo`, colony state on `uplink-data`; nothing pushed, no exception.
- [ ] **Step 2:** Run `php artisan test --compact tests/Feature/Uplink/RouteUplinkMessageTest.php` → FAIL (class missing).
- [ ] **Step 3:** Implement the enum, the abstract job, the 7 jobs (each `process` calls the existing action: `StartUplinkSession`, `SyncZoneList`, `StoreZoneLayout`, `StoreZoneState`, `ColonyIngestor`; `ReceiveCommandAck` is a stub returning false until Task 5) and `RouteUplinkMessage::handle(MessageReceived $event): void` (cheap 200-byte head check for any event name or channel, `json_decode` of the frame, `UplinkMessageType::fromFrame`, `dispatch(new $job($frame))->onQueue(...)`, catch `Throwable` → `report`). Register by event discovery like the old listeners; delete the old listeners.
- [ ] **Step 4:** Move the behavioural tests of the old listeners to `UplinkMessageJobsTest.php`, calling `(new ReceiveZoneSlowState($frame))->handle()` etc. Keep every existing assertion (schema refusal, unknown zone ignored, list archive, layout versions, colony journal; ack assertions now check `UplinkMessageAccepted` via `Event::fake([UplinkMessageAccepted::class])`). Add `it('sends the ack frame the mod parses')` — `broadcastAs()` is `uplink.ack`, `broadcastOn()` is `[new Channel('uplink-data')]`, `broadcastWith()` is `['id' => 'x']`. Run both files → PASS.
- [ ] **Step 5:** Pint, commit: `refactor: route uplink messages to one queued job per type`.

### Task 3: Domain events and zone broadcasts

**Files:**
- Create in `app/Events/Uplink/`: `ZoneListSynced(string $colonyGuid, list<string> $zoneGuids)`, `ZoneLayoutStored(string $colonyGuid, string $zoneGuid)`, `ZoneStateStored(string $colonyGuid, string $zoneGuid, string $kind, float $gameSeconds)`. Only events with a subscriber in this project; others (session started, colony state stored, command acknowledged) are added by the item that needs them.
- Create: `app/Listeners/Uplink/BroadcastZoneUpdated.php` (handles ZoneListSynced, ZoneLayoutStored, ZoneStateStored; keeps the 2 s fast-state throttle via `Cache::lock("zone-updated:{$zoneGuid}", 2)`)
- Modify: `SyncZoneList`, `StoreZoneLayout`, `StoreZoneState` (raise domain events instead of `ZoneUpdated::dispatch`)
- Test: `tests/Feature/Uplink/UplinkDomainEventsTest.php`

**Interfaces:**
- Consumes: Task 2 jobs.
- Produces: the event classes above (constructor signatures exactly as listed); `ZoneUpdated` unchanged for the front end.

- [ ] **Step 1: Write the failing tests:**
  - `it('does not ack a layout for an unknown zone')` → `Event::assertNotDispatched(UplinkMessageAccepted::class)`, no zone row created.
  - `it('never acks states')` — slow and fast jobs → `UplinkMessageAccepted` not dispatched.
  - `it('broadcasts zone updates from domain events')` — `Event::fake([ZoneUpdated::class])`, store list, layout, slow → `ZoneUpdated` dispatched with kinds `list`, `layout`, `slow`.
  - `it('throttles fast zone updates to one per 2 seconds')` — two fast states within 1 s → one `ZoneUpdated`; `travel(3)->seconds()`, third → two.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement; actions raise their domain event after the write; `BroadcastZoneUpdated` replaces the direct `ZoneUpdated::dispatch` calls.
- [ ] **Step 4:** Run the file and `tests/Feature/Uplink` → PASS.
- [ ] **Step 5:** Pint, commit: `feat: uplink domain events for zone updates`.

### Task 4: Session-safe ordering

**Files:**
- Create migration: `add_session_ordering_to_zones_and_colonies` — zones: `slow_session_id` string nullable, `slow_game_seconds` double nullable, `fast_session_id` string nullable, `fast_game_seconds` double nullable; colonies: `session_id` string nullable, `game_seconds` double nullable
- Modify: `StoreZoneState`, `ColonyIngestor`
- Test: `tests/Feature/Uplink/SessionOrderingTest.php`

**Interfaces:**
- Consumes: Task 3 events (`ZoneStateStored` raised only when a row was written).
- Produces: `StoreZoneState::handle(string $kind, array $message): bool` (true = written).

Write rule: **one statement**, the current-session check inside it (no read-then-write race when a session start lands between the check and the write). `current` = subquery on `uplink_sessions` (latest `started_at` for the colony; add an index on `(colony_guid, started_at)`):

```sql
update zones set {kind}_state = :state, {kind}_state_at = now(),
       {kind}_session_id = coalesce(:session, (current)), {kind}_game_seconds = :seconds
where colony_guid = :colony and zone_guid = :zone
  and (:session is null or :session = (current))
  and ({kind}_session_id is distinct from coalesce(:session, (current))
       or {kind}_game_seconds is null or {kind}_game_seconds < :seconds)
```

Built with the query builder + `DB::raw` for the subquery; returns affected rows. `previous_slow_temperature` is set only when the stored slow state has the same session id. Colony state: same rule on `colonies.session_id/game_seconds` when the message has both `colony.sessionId`-level fields (`sessionId`, `gameSeconds` at the top of the snapshot); without them, write unconditionally (today's behaviour).

- [ ] **Step 1: Write the failing tests:**
  - `it('drops an older state of the same session')` — fast `gameSeconds` 200 then 150 → stored stays 200, one `ZoneStateStored`.
  - `it('drops a state from a previous session even when its gameSeconds is higher')` — session A start, B start (B is current), state of A at 9000 after a state of B at 100 → stored stays B/100.
  - `it('replaces a state of another session with the current session state')` — stored A/9000, B becomes current, B state at 100 → stored B/100.
  - `it('drops a new-session state processed before its session start')` — current A, state with sessionId B → nothing written.
  - `it('treats a state without sessionId as current')` — no `sessionId`, newer gameSeconds → written with the current session id.
  - `it('keeps previous_slow_temperature within one session')` — slow A then slow B → `previous_slow_temperature` null after B.
  - `it('orders colony states within the session')` and `it('stores a colony state without session fields')`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Migration (`php artisan make:migration --no-interaction`), conditional update as above.
- [ ] **Step 4:** Run the file and `tests/Feature/Uplink` → PASS.
- [ ] **Step 5:** Pint, commit: `feat: never let a late or older-session state overwrite a newer one`.

### Task 5: Site → mod commands

**Files:**
- Create: migration + model `UplinkCommand` (table `uplink_commands`: `id` uuid primary, `colony_guid`, `type`, `payload` json, `status` string default `pending` [`pending|done|refused|expired`], `reason` nullable, `issued_at`, `expires_at`, `acked_at` nullable, timestamps) with factory
- Create: `app/Actions/Uplink/SendUplinkCommand.php` — `handle(string $colonyGuid, string $type, array $payload, int $ttlSeconds = 60): UplinkCommand`
- Create: `app/Events/Uplink/UplinkCommandIssued.php` (`ShouldBroadcastNow`, channel `uplink-data`, as `uplink.command`, payload = envelope with `schema` 1)
- Create: `app/Console/Commands/UplinkResyncZoneCommand.php` (`uplink:resync-zone {zone : zone id or guid}`), no scheduler: expiry is lazy — `UplinkCommand::expireStale(): int` marks `pending` rows past `expires_at` as `expired`; called by `SendUplinkCommand`, `ReceiveCommandAck` and `uplink:resync-zone` before they read commands
- Create: `app/Listeners/Uplink/ResyncZoneWithoutLayout.php` (on `ZoneStateStored`: zone has no layout, was created more than 30 s ago — so the mod's own 15 s resend after a reconnect gets its chance first — and has no pending `zone.resync` → send one)
- Modify: `ReceiveCommandAck::process`
- Test: `tests/Feature/Uplink/UplinkCommandsTest.php`

**Interfaces:**
- Consumes: Task 2 job base, Task 3 events.
- Produces: command `type` `zone.resync` with payload `{zoneId: string}`; envelope keys exactly `schema, id, colonyId, type, issuedAt, expiresAt, payload`.

- [ ] **Step 1: Write the failing tests:**
  - `it('broadcasts a command envelope')` — `Event::fake`, send `zone.resync` → `UplinkCommandIssued::broadcastWith()` has exactly the 7 envelope keys, `expiresAt` = now + 60 s.
  - `it('records the ack of a command')` — ack frame `{schema:1,id:'msg-ack-1',colonyId,commandId,status:'done'}` through `ReceiveCommandAck` → status `done`, `acked_at` set, `UplinkMessageAccepted` dispatched.
  - `it('records a refusal with its reason')`.
  - `it('ignores an ack for an unknown or already expired command')` → returns true (acked so the mod stops resending), row unchanged.
  - `it('expires unanswered commands')` — pending command, `travel(61)->seconds()`, `UplinkCommand::expireStale()` → `expired`; and an ack arriving after that leaves it `expired`.
  - `it('asks for a resync when a zone still has no layout 30 s after creation')`, `it('does not resync a zone created less than 30 s ago')`, `it('does not send a second resync while one is pending')`.
  - `it('resyncs a zone by hand')` — `artisan('uplink:resync-zone', ['zone' => $zone->id])->assertSuccessful()`.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS.
- [ ] **Step 5:** Pint, commit: `feat: site to mod command envelope with zone.resync`.

### Task 6: Level 1 performance baselines

**Files:**
- Create: `tests/Fixtures/uplink/perf/{session-start,zone-list,zone-layout,zone-slow,zone-fast,colony-state,command-ack}.json` — full frames in the real wire format (data as `{"payload": base64(gzip(json))}` for `uplink-data` types), large-colony profile: zone content ×10 of the user's biggest real zone (~1 000 cells, ~70 plants, ~80 buildings), zone list of 30 zones, colony state from `uplinkFixture()` scaled to 30 duplicants
- Create: `tests/Feature/Uplink/MessageBaselineTest.php`
- Test: same

**Interfaces:**
- Consumes: `UplinkMessageType` cases and `job()`.

- [ ] **Step 1: Write the test:** dataset = `UplinkMessageType::cases()`; for each: fail with a clear message if the fixture file or the budget entry is missing (this enforces "each new message type arrives with its baseline"); seed what the message needs (colony, session, zones); run the job `RUNS = 15` times (first run discarded as warm-up) and `expect(median)->toBeLessThan(BUDGETS[$type->value])`; if over budget, measure once more and assert on the second median (absorbs a busy machine, e.g. the game running). Also assert the query count of one run (`DB::enableQueryLog()`) equals `QUERIES[$type->value]` — exact, machine-independent, catches N+1 regressions. `BUDGETS` is a `const array<string, float>` in the test file, milliseconds, each with a comment `// measured median X ms on <date>`.
- [ ] **Step 2:** Build the fixtures once from the dev database (throwaway script, not committed), run the test with budgets temporarily at 10 000 ms and print the medians; set each budget to ≈ 3× its median (round up to a whole ms).
- [ ] **Step 3:** Run `php artisan test --compact tests/Feature/Uplink/MessageBaselineTest.php` 3 times → PASS each time; whole group adds under ~5 s to the suite (lower `RUNS` if not).
- [ ] **Step 4:** Commit: `test: per message type processing baselines`.

### Task 7: Level 2 ingest bench (`uplink:bench --ingest`)

**Files:**
- Modify: `app/Console/Commands/UplinkBenchCommand.php` (new mode; existing mode untouched)
- Create: `tests/Performance/uplink-ingest.js` (k6), `app/Listeners/Uplink/RecordBenchLatency.php`
- Modify: `docs/site/development/testing-and-performance.md`
- Test: `tests/Feature/Uplink/UplinkBenchIngestTest.php` (options validation, seeding and clean-up, result table; `Process::fake` for k6)

**Interfaces:**
- Options: `--ingest`, `--zones=30`, `--scale=4`, `--rates=7,50,150,400`, `--duration=20`.
- Bench colony guid: `bench-` + random suffix; seeded zones `bench-zone-{n}` with layouts from the dev database scaled ×`--scale` (as the spike's `bench/seed.php`); everything for that colony deleted at the end, also on failure (`finally`).
- Latency: k6 sends states as JSON text (no gzip; gzip cost is covered by Task 6) with `gameSeconds` = send time in seconds (`Date.now() / 1000`, monotonic so the ordering guard accepts it) and a `sessionId` of a seeded bench session. `RecordBenchLatency` listens to `ZoneStateStored` (it runs in the workers, so it cannot be registered only during the bench; its cost outside the bench is one `str_starts_with`), and for colony guids starting with `bench-` pushes `now - gameSeconds` (ms) to a Redis list `uplink-bench:latency`. The command reads and clears that list after the queue drains (stop when no new entry for 3 s or after 120 s).
- Output: table `Rate | Sent | Stored | Lost | Throughput/s | Median | p95 | Max`.

- [ ] **Step 1:** Write the failing feature tests: `it('rejects invalid rates')`; `it('removes the bench colony after a run, even when k6 fails')` (`Process::fake` with a failing k6); `it('prints one row per rate from the recorded latencies')` (pre-fill the latency store, `Process::fake` k6 summary `{"sent": 140}`). Latencies are read straight from the Redis list (tests run against the local Valkey with a test-only key prefix).
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement (reference: spike worktree `../ony-bench/bench/` — `ingest.js` fixed-rate loop on one socket, `run.sh` drain logic, `stats.php`). **Step 4:** Run → PASS.
- [ ] **Step 5:** Manual check with the real stack running (`composer run dev`): `php artisan uplink:bench --ingest --rates=7,150` → 0 lost, medians in the tens of ms. Paste the table into `testing-and-performance` (no config values).
- [ ] **Step 6:** Pint, commit: `feat: uplink:bench --ingest end-to-end ingest bench`.

### Task 8: Mod — pluggable zone sections

**Files (repo `~/dev/uplink`):**
- Create: `Uplink.Core/Zones/ZoneSection.cs`, `Uplink.Core/Zones/ZoneStream.cs` (enum `Layout, Slow, Fast`), `Uplink.Core/Zones/ZoneSectionComposer.cs`
- Create in `Uplink/Zones/Sections/`: `TerrainCellsSection.cs`, `LayoutTilesSection.cs`, `LayoutBuildingsSection.cs`, `PlantsSection.cs`, `BuildingStatesSection.cs`
- Modify: `Uplink/Zones/ZoneSampler.cs`, `Uplink.Core/Zones/ZoneMessages.cs`
- Test: `Uplink.Core.Tests/ZoneSectionComposerTests.cs`, extend `ZoneMessagesTests.cs`

**Interfaces:**
- `public sealed class ZoneSection { ZoneSection(string name, ZoneStream stream, Func<ZoneReadContext, JObject> read) }` — `read` returns the properties this section adds to the message (e.g. terrain returns `palette`, `element`, `mass`, `temperature`); mirrors the colony `SnapshotSection` pattern (composition).
- `ZoneReadContext` carries `Zone zone, CellBox box, int gridWidth, Action<string, Exception> skip` and a per-pass shared cache (plants read once for all zones).
- `ZoneSectionComposer.Compose(JObject header, IEnumerable<ZoneSection> sections, ZoneReadContext ctx, Action<string, Exception> sectionFailed): string` — appends each section's properties in registration order; a throwing section is omitted and reported (today's `Read` behaviour).
- Registration: one static list per stream in `ZoneSampler` (`Sections.Slow = { TerrainCells }`, `Sections.Fast = { Plants, BuildingStates }`, `Sections.Layout = { LayoutTiles, LayoutBuildings }`). Adding a section = one class + one line.

- [ ] **Step 1: Write the failing tests:** `Compose_keeps_registration_order`, `Compose_omits_a_failing_section_and_reports_it`, and **`Slow_and_Fast_output_is_unchanged`**: for a fixture zone, the JSON from the old `ZoneMessages.Slow/Fast/Layout` builders equals the JSON from the composer with the new sections' pure parts (string equality). Capture the old outputs as golden strings before refactoring.
- [ ] **Step 2:** `cd ~/dev/uplink && dotnet test Uplink.Core.Tests` → FAIL.
- [ ] **Step 3:** Implement; move game reads from `ZoneReaders` into the sections (keep the `ZoneReaders` doc notes with the code they describe); `ZoneMessages` keeps building headers.
- [ ] **Step 4:** `dotnet test Uplink.Core.Tests` → PASS; `./build-and-install.sh --no-install` → builds.
- [ ] **Step 5:** Commit (mod repo): `refactor: compose zone messages from pluggable sections`.

### Task 9: Mod — additive session fields

**Files:** `Uplink.Core/Zones/ZoneMessages.cs` (`Slow`, `Fast` gain `string sessionId`), `Uplink/Zones/ZoneSampler.cs`, colony snapshot assembly (`Uplink/Snapshot/*` + `Uplink.Core/SnapshotAssembler.cs`: top-level `sessionId` and `gameSeconds`)
**Test:** `ZoneMessagesTests.cs`, `SnapshotAssemblerTests.cs`

- [ ] **Step 1:** Failing tests: `Slow_carries_sessionId`, `Fast_carries_sessionId` (field present, every other field identical to the golden strings of Task 8 plus `sessionId`), `Snapshot_carries_sessionId_and_gameSeconds`; `schema` stays 1.
- [ ] **Step 2:** `dotnet test Uplink.Core.Tests` → FAIL. **Step 3:** Implement. **Step 4:** → PASS, build.
- [ ] **Step 5:** Commit: `feat: tag zone and colony states with their session`.

### Task 10: Mod — commands

**Files:**
- Create: `Uplink.Core/Commands/CommandEnvelope.cs` (`static bool TryParse(string frame, out CommandEnvelope envelope)` for event `uplink.command` on `uplink-data`; fields `Schema, Id, ColonyId, Type, IssuedAt, ExpiresAt, Payload (JObject)`), `Uplink.Core/Commands/CommandRegistry.cs` (`Register(string type, Func<CommandEnvelope, CommandResult> handler)`, `CommandResult Dispatch(CommandEnvelope e, string loadedColonyId, DateTime utcNow)`), `Uplink.Core/Commands/CommandResult.cs` (`Status: Ignored|Done|Refused|Expired`, `Reason`), `Uplink.Core/Commands/CommandAckMessage.cs` (`string Build(string messageId, string colonyId, string commandId, CommandResult result)` → `{schema:1, id: messageId, colonyId, commandId, status: "done"|"refused"|"expired", reason?}`; `messageId` comes from `SendReliable`)
- Create: `Uplink/Commands/ZoneResyncCommand.cs` (forgets the zone in `LayoutSendTracker` and forces slow + fast on the next pass)
- Modify: `Uplink/Link/UplinkClient.cs` (route `uplink.command` frames to the registry on the main thread; send the ack with `SendReliable("client-command.ack", ...)` unless `Ignored`), `Uplink.Core/PusherFrames.cs` (constants `CommandEvent = "uplink.command"`, `CommandAckEvent = "client-command.ack"`)
- Test: `Uplink.Core.Tests/CommandEnvelopeTests.cs`, `CommandRegistryTests.cs`

- [ ] **Step 1:** Failing tests: `Parses_a_command_frame` (data as JSON string and as object), `Rejects_a_newer_schema` (→ Refused, reason `schema`), `Ignores_a_command_for_another_colony` (→ Ignored, no ack), `Expires_a_command_past_expiresAt`, `Refuses_an_unknown_type`, `Dispatches_to_the_registered_handler`.
- [ ] **Step 2:** `dotnet test Uplink.Core.Tests` → FAIL. **Step 3:** Implement. **Step 4:** → PASS, `./build-and-install.sh` (deploy allowed without asking).
- [ ] **Step 5:** Commit: `feat: site commands with zone.resync`.

### Task 11: End-to-end check and docs

- [ ] **Step 1:** With `composer run dev` running and the mod installed, ask the user to launch the game and load the colony. Check: zones and states arrive (`/zones`), Reverb log clean, `php artisan uplink:resync-zone <id>` → command row `done` within a few seconds and the zone's `layout_at` refreshed.
- [ ] **Step 2:** Ask the user to load an older save of the same colony: states of the new session are stored within ~5 s, `previous_slow_temperature` does not compare across the two sessions.
- [ ] **Step 3:** `php artisan uplink:bench --ingest` (defaults) → table pasted into the docs page.
- [ ] **Step 4:** Docs: `php artisan larecipe:affected --uncommitted --base=main`; update `development/architecture`, `development/uplink` (queues, message types, ordering rule, commands, versioning per message), `development/testing-and-performance` (both levels), `development/local-setup`; `php artisan larecipe:validate`; show the user the docs diff, commit after approval: `docs: uplink sync orchestration`.
- [ ] **Step 5:** Full suite `php artisan test --compact` green (browser tests need Vite running); `dotnet test Uplink.Core.Tests` green. Remove the spike worktree only when the user agrees (`git worktree remove ../ony-bench`, branch `spike/ingest-bench`).
