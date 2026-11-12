# Zones Foundation, Slice A, Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In game, the player paints or room-fills named zones that live in the save; the mod syncs each zone (layout, slow and fast state) and the zone list to the site over Reverb with acknowledgements; the site stores zones and shows a zone page (map tab with layers and panel, entities tab) that updates live.

**Architecture:** Two repos. The mod (`~/dev/uplink`, C# net48, game-free logic in `Uplink.Core` with xunit) gains a zone model, a zone tool, readers for zone data, a second Pusher channel `uplink-data` and an acknowledged ordered queue. The site (`~/dev/ony-planner`, Laravel 13, Inertia v3 + Vue, Pest) gains a listener on `uplink-data` that unwraps `{payload: base64(gzip(json))}`, stores sessions/zones/versions/latest states, acks by correlation id, and broadcasts a small `zone.updated` event that the pages use to reload props.

**Tech Stack:** C# .NET Framework 4.8 + Harmony + Newtonsoft.Json (game's), xunit; PHP 8.5, Laravel 13, Reverb 1.13, Octane, spatie/laravel-data, Pest, Vue 3 + TypeScript, Tailwind, Wayfinder, bun.

**Spec:** `docs/superpowers/specs/2026-11-12-zones-foundation-design.md` (slice A of "Delivery slices"). Mockup: `.superpowers/brainstorm/23658-1794445677/content/zone-layout-v3.html`.

## Global Constraints

- Transport: **everything over Reverb**; no HTTP endpoint for the mod. New mod messages go on channel `uplink-data` with `data = {"payload": base64(gzip(json))}`; the colony state stays as it is on `uplink`.
- Reliable messages (`client-session.start`, `client-zone.list`, `client-zone.layout`) carry a message `id`; the site answers the sender with `{"event":"uplink.ack","channel":"uplink-data","data":"{\"id\":\"…\"}"}` after storing. States (`client-zone.slow`, `client-zone.fast`) are latest-only, never acked.
- The Reverb listener must stay cheap and never throw: unwrap, validate, small writes, then return. It must not broadcast synchronously (it runs inside Reverb): broadcast with a **queued** `ShouldBroadcast` event.
- New tables are user data: never in `GameDataTables`, never truncated by `game:import`. A colony owning zones is never pruned.
- Game time is `GameClock.Instance.GetTime()` (total seconds, float); cycle is the HUD cycle `GameUtil.GetCurrentCycle()`.
- Cells are the game's cell indices; `x = cell % gridWidth`, `y = cell / gridWidth`; every zone message carries `gridWidth` (`Grid.WidthInCells`).
- Default rates (slice A, read from `uplink.json`): `zoneFastSeconds` 5 (clamp 1–60), `zoneSlowSeconds` 30 (clamp 5–600).
- Mod never blocks or throws into the game; every reader isolated like `SnapshotAssembler` sections; log lines prefixed `Uplink:`.
- Vue SFCs: `<script setup lang="ts">`, JetBrains `<!-- region -->` / `// region` markers, Wayfinder imports (no URL literals), translations in `lang/{en,fr}/zones.php` with identical keys, no em dashes in copy, dark tokens from `resources/css/app.css` (`--ink-*`, `--ok`, `--warn`, `--oni-amber`).
- Tooling: `bun`/`bunx` only; `vendor/bin/pint --dirty --format agent` after PHP edits; `php artisan make:*` for new Laravel files; commits without co-author trailer and without GPG (`git -c commit.gpgsign=false commit`).
- Reverb must be restarted after any listener change (`reverb:start` runs from the dev runner).

## Review Focus

1. **Reload a save** (or load another colony) while the site is up: zones come back from the save, a new session starts, the zone list is resent; zones absent from it are archived, not deleted. Test: `SyncZoneList` archives missing zones and un-archives returning ones (Task S4).
2. **Site down, then up**: reliable messages queued while disconnected are all delivered once the link is back, in order, and duplicates (resent before the ack arrived) do not create duplicate rows. Tests: `AckQueue` resend/ack (Task M2), listener idempotence on the same message id twice (Task S4).
3. **Zone edited in game** (cells added/removed): a new `zone_versions` row only when the cell set changed, not on every layout resend. Test in Task S4.
4. **Big zone** (a 2 000-cell factory room): the payload stays well under 5 MB once gzipped and the page still renders. Test: `ZoneMessages` gzip size check on 2 000 cells (Task M4); map renders 2 000 cells (Task S6 manual check with `uplink:bench`-sized fixture).
5. **Zone on another world / rocket interior removed**: messages carry `worldId`; a zone whose world is gone is kept with `orphaned = true` and the page says so. Test in Task S4 (orphan flag stored) and M3 (zone keeps cells when world missing).

---

## Part 1: the mod (`~/dev/uplink`)

Run all Core tests with `dotnet test Uplink.Core.Tests`; build with `./build-and-install.sh --no-install`.

### Task M1: Wire payload

**Files:**
- Create: `Uplink.Core/WirePayload.cs`
- Test: `Uplink.Core.Tests/WirePayloadTests.cs`

**Interfaces:**
- Produces: `public static string WirePayload.Wrap(string json)` → `{"payload":"<base64(gzip(utf8 json))>"}`; `public static string WirePayload.Unwrap(string dataJson)` (tests and diagnostics only).

- [ ] **Step 1: Write the failing tests**

```csharp
[Fact] public void Wrap_IsAnObjectWithOnlyPayload() {
    JObject data = JObject.Parse(WirePayload.Wrap("{\"a\":1}"));
    Assert.Single(data.Properties());
    Assert.Equal(JTokenType.String, data["payload"].Type);
}
[Fact] public void Wrap_PayloadIsBase64OfGzip() {
    byte[] bytes = Convert.FromBase64String((string)JObject.Parse(WirePayload.Wrap("{\"a\":1}"))["payload"]);
    Assert.Equal(0x1f, bytes[0]); Assert.Equal(0x8b, bytes[1]);
}
[Fact] public void Unwrap_RoundTripsUnicode() =>
    Assert.Equal("{\"n\":\"Écluse\"}", WirePayload.Unwrap(WirePayload.Wrap("{\"n\":\"Écluse\"}")));
```

- [ ] **Step 2: Run** `dotnet test Uplink.Core.Tests --filter WirePayloadTests`. Expected: FAIL (type missing).
- [ ] **Step 3: Implement** with `System.IO.Compression.GZipStream` (`CompressionLevel.Fastest`) over UTF-8 bytes (no BOM).
- [ ] **Step 4: Run** the same filter. Expected: PASS.
- [ ] **Step 5: Commit** `feat: gzip+base64 wire payload`.

### Task M2: Acknowledged queue and keyed latest outbox

**Files:**
- Create: `Uplink.Core/AckQueue.cs`, `Uplink.Core/KeyedLatestOutbox.cs`
- Test: `Uplink.Core.Tests/AckQueueTests.cs`, `Uplink.Core.Tests/KeyedLatestOutboxTests.cs`

**Interfaces:**
- Produces:
  - `public sealed class AckQueue(int capacity, TimeSpan resendAfter, Func<DateTime> clock)`; `string Enqueue(string eventName, string payloadJson)` returns the message id (GUID "N") and stores `(id, eventName, payloadJson)`; `IReadOnlyList<PendingMessage> Due()` returns messages never sent or sent longer than `resendAfter` ago, oldest first, and marks them sent now; `void Ack(string id)`; `void ResendAllOnReconnect()` marks every pending message as never sent; `int Count`; `int Dropped` (oldest dropped when full). `PendingMessage { string Id; string EventName; string PayloadJson; }`. Thread-safe (one lock).
  - `public sealed class KeyedLatestOutbox` : `void Put(string key, string frame)` keeps only the latest frame per key; `bool TryTakeAny(out string frame)` takes keys in first-put order; thread-safe.

- [ ] **Step 1: Write the failing tests** (`AckQueueTests`)

```csharp
[Fact] public void Due_ReturnsNewMessagesOnceUntilTimeout() {
    var now = new DateTime(2026, 1, 1); var q = new AckQueue(10, TimeSpan.FromSeconds(10), () => now);
    q.Enqueue("client-zone.list", "{}");
    Assert.Single(q.Due()); Assert.Empty(q.Due());
    now = now.AddSeconds(11); Assert.Single(q.Due());
}
[Fact] public void Ack_RemovesTheMessage() {
    var q = new AckQueue(10, TimeSpan.FromSeconds(10), () => DateTime.UtcNow);
    string id = q.Enqueue("e", "{}"); q.Due(); q.Ack(id);
    Assert.Equal(0, q.Count);
}
[Fact] public void Due_KeepsEnqueueOrder() {
    var q = new AckQueue(10, TimeSpan.FromSeconds(10), () => DateTime.UtcNow);
    string a = q.Enqueue("e", "{}"), b = q.Enqueue("e", "{}");
    Assert.Equal(new[] { a, b }, q.Due().Select(m => m.Id));
}
[Fact] public void Full_DropsOldestAndCounts() {
    var q = new AckQueue(2, TimeSpan.FromSeconds(10), () => DateTime.UtcNow);
    q.Enqueue("e", "1"); q.Enqueue("e", "2"); q.Enqueue("e", "3");
    Assert.Equal(1, q.Dropped); Assert.Equal(new[] { "2", "3" }, q.Due().Select(m => m.PayloadJson));
}
[Fact] public void ResendAllOnReconnect_MakesSentMessagesDueAgain() {
    var q = new AckQueue(10, TimeSpan.FromHours(1), () => DateTime.UtcNow);
    q.Enqueue("e", "{}"); q.Due(); q.ResendAllOnReconnect();
    Assert.Single(q.Due());
}
[Fact] public void Ack_UnknownIdIsIgnored() => new AckQueue(1, TimeSpan.Zero, () => DateTime.UtcNow).Ack("nope");
```

  `KeyedLatestOutboxTests`: `Put_SameKeyKeepsLatest` (put k→"a", k→"b", take → "b", then nothing); `TryTakeAny_FirstPutOrder` (put k1, k2, k1 again → takes k1's latest then k2).

- [ ] **Step 2: Run** `dotnet test Uplink.Core.Tests --filter "AckQueueTests|KeyedLatestOutboxTests"`. Expected: FAIL.
- [ ] **Step 3: Implement** both classes in the files above.
- [ ] **Step 4: Run** the filter. Expected: PASS.
- [ ] **Step 5: Commit** `feat: acked queue and keyed latest outbox`.

### Task M3: Zone model and its save format

**Files:**
- Create: `Uplink.Core/Zones/Zone.cs`, `Uplink.Core/Zones/ZoneSet.cs`
- Test: `Uplink.Core.Tests/ZoneSetTests.cs`

**Interfaces:**
- Produces:
  - `public sealed class Zone { string Id; string Name; int WorldId; SortedSet<int> Cells; int Version; }` (`Version` starts at 1, +1 on every change of `Cells`).
  - `public sealed class ZoneSet`: `Zone Create(string name, int worldId)` (id = GUID "N"); `bool Rename(string id, string name)`; `bool Delete(string id)`; `bool AddCells(string id, IEnumerable<int> cells)` / `bool RemoveCells(string id, IEnumerable<int> cells)` (return true and bump `Version` only if the set changed); `IReadOnlyList<Zone> All` (creation order); `Zone Find(string id)`; `event Action Changed` (raised on create, rename, delete, cell change); `string ToJson()`; `static ZoneSet FromJson(string json)` (null/empty/broken → empty set, never throws; logs nothing, caller logs).
  - Default name for a new zone: `"Zone " + n` with the first free n ≥ 1.

- [ ] **Step 1: Write the failing tests**: `Create_NamesZonesWithFirstFreeNumber` ("Zone 1", "Zone 2"; delete "Zone 1"; next is "Zone 1"); `AddCells_BumpsVersionOnlyOnChange` (add {1,2} → v2; add {2} → still v2, returns false); `RemoveCells_BumpsVersion`; `Changed_RaisedOnEveryMutation`; `Json_RoundTripsZones` (ids, names, world, cells, version identical); `FromJson_BrokenTextGivesEmptySet` (`"{"`, `""`, `null`); `Cells_MayOverlapBetweenZones`.
- [ ] **Step 2: Run** `dotnet test Uplink.Core.Tests --filter ZoneSetTests`. Expected: FAIL.
- [ ] **Step 3: Implement** with Newtonsoft (JSON shape `{"schema":1,"zones":[{"id","name","worldId","version","cells":[…]}]}`).
- [ ] **Step 4: Run** the filter. Expected: PASS.
- [ ] **Step 5: Commit** `feat: zone model and save format`.

### Task M4: Zone messages

**Files:**
- Create: `Uplink.Core/Zones/ZoneMessages.cs`, `Uplink.Core/Zones/CellBox.cs`
- Test: `Uplink.Core.Tests/ZoneMessagesTests.cs`

**Interfaces:**
- Consumes: `Zone` (M3).
- Produces game-free builders returning the **inner JSON** (before `WirePayload.Wrap`):
  - `CellBox.Around(IEnumerable<int> cells, int gridWidth, int gridHeight, int margin)` → `CellBox { int X, Y, Width, Height }` clamped to the grid; margin used by the mod: 2.
  - `string ZoneMessages.SessionStart(string messageId, string colonyId, string sessionId, double loadedGameSeconds, int cycle)` → `{"schema":1,"id","colonyId","sessionId","loadedGameSeconds","cycle"}`.
  - `string ZoneMessages.ZoneList(string messageId, string colonyId, string sessionId, IEnumerable<Zone> zones, Func<int,bool> worldExists)` → `{"schema":1,"id","colonyId","sessionId","zones":[{"zoneId","name","worldId","version","cellCount","orphaned"}]}`.
  - `string ZoneMessages.Layout(string messageId, string colonyId, Zone zone, int gridWidth, CellBox box, double gameSeconds, IEnumerable<LayoutTile> tiles, IEnumerable<LayoutBuilding> buildings)` → `{"schema":1,"id","colonyId","zoneId","version","gameSeconds","gridWidth","box":{"x","y","width","height"},"cells":[…zone cells],"tiles":[{"cell","prefabId"}],"buildings":[{"instanceId","prefabId","anchorCell","width","height","orientation"}]}`. `LayoutTile { int Cell; string PrefabId; }`, `LayoutBuilding { string InstanceId; string PrefabId; int AnchorCell; int Width; int Height; string Orientation; }`.
  - `string ZoneMessages.Slow(string colonyId, string zoneId, double gameSeconds, CellBox box, IReadOnlyList<string> elementIds, IReadOnlyList<float> masses, IReadOnlyList<float> temperatures)` → `{"schema":1,"colonyId","zoneId","gameSeconds","box",…,"palette":[distinct element ids in first-seen order],"element":[palette index per cell],"mass":[…],"temperature":[…]}`; arrays are row-major from the box's bottom-left cell (y ascending, then x ascending); non-finite numbers → `null`.
  - `string ZoneMessages.Fast(string colonyId, string zoneId, double gameSeconds, JArray plants, JArray buildings)` → `{"schema":1,"colonyId","zoneId","gameSeconds","plants","buildings"}`.
  - The layout's content hash for change detection: `static string ZoneMessages.LayoutSignature(Zone zone, IEnumerable<LayoutTile> tiles, IEnumerable<LayoutBuilding> buildings)` (stable regardless of input order).

- [ ] **Step 1: Write the failing tests**: `CellBox_AddsMarginAndClamps` (cells at x 0..3 with margin 2 → x 0, width 6); `Slow_UsesPaletteAndRowMajorOrder` (2×2 box with Oxygen, Water, Oxygen, Oxygen → palette `["Oxygen","Water"]`, element `[0,1,0,0]`); `Slow_NonFiniteIsNull`; `ZoneList_FlagsOrphanedWorld`; `Layout_CarriesZoneCellsSorted`; `LayoutSignature_IgnoresOrder`; `Slow_TwoThousandCellsStaysSmallOnceWrapped` (`WirePayload.Wrap(Slow(…2 000 cells…)).Length < 200_000`).
- [ ] **Step 2: Run** `dotnet test Uplink.Core.Tests --filter ZoneMessagesTests`. Expected: FAIL.
- [ ] **Step 3: Implement** `CellBox` and `ZoneMessages` with `JsonTextWriter` (invariant culture, like `PusherFrames`).
- [ ] **Step 4: Run** the filter. Expected: PASS.
- [ ] **Step 5: Commit** `feat: zone message builders`.

### Task M5: Settings, data channel, acks in the handshake

**Files:**
- Modify: `Uplink.Core/Settings.cs`, `Uplink.Core/PusherFrames.cs`, `Uplink.Core/LinkSession.cs`
- Test: `Uplink.Core.Tests/SettingsTests.cs`, `Uplink.Core.Tests/PusherFramesTests.cs`, `Uplink.Core.Tests/LinkSessionTests.cs`

**Interfaces:**
- Produces:
  - `Settings.ZoneFastSeconds` (default 5, clamp 1–60), `Settings.ZoneSlowSeconds` (default 30, clamp 5–600), parsed like `intervalSeconds` from `zoneFastSeconds` / `zoneSlowSeconds`.
  - `PusherFrames.DataChannel = "uplink-data"`; event name constants `SessionStartEvent = "client-session.start"`, `ZoneListEvent = "client-zone.list"`, `ZoneLayoutEvent = "client-zone.layout"`, `ZoneSlowEvent = "client-zone.slow"`, `ZoneFastEvent = "client-zone.fast"`, `AckEvent = "uplink.ack"`.
  - `FrameKind.Ack` and `public static string PusherFrames.AckId(string frame)` (reads `data` as a JSON string or object, returns `id`, null when absent); `Classify` returns `SubscriptionSucceeded` for both channels and exposes `public static string PusherFrames.ChannelOf(string frame)`.
  - `LinkSession`: after `connection_established` it asks to subscribe to `uplink` then `uplink-data` (new `LinkAction.SubscribeData`); `StartSending` once `uplink` succeeded (as today); new `LinkAction.StartSendingData` once `uplink-data` succeeded; `Ack` frames map to `LinkAction.Acked`.
- [ ] **Step 1: Write the failing tests**: settings defaults and clamps (`zoneFastSeconds: 0` → 1, `zoneSlowSeconds: 9999` → 600, wrong type → `FormatException`); `Classify_AckFrame` and `AckId_ReadsStringData` (`{"event":"uplink.ack","channel":"uplink-data","data":"{\"id\":\"x\"}"}` → `"x"`); `LinkSession_SubscribesBothChannelsAndStartsDataOnItsOwnSuccess`.
- [ ] **Step 2: Run** `dotnet test Uplink.Core.Tests --filter "SettingsTests|PusherFramesTests|LinkSessionTests"`. Expected: the new tests FAIL, old ones pass.
- [ ] **Step 3: Implement.** Keep every existing behaviour and test green.
- [ ] **Step 4: Run** the full suite `dotnet test Uplink.Core.Tests`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: data channel, acks and zone rates in settings`.

### Task M6: Link: reliable and keyed sending

**Files:**
- Modify: `Uplink/Link/UplinkClient.cs`
- Create: `Uplink/Link/UplinkSession.cs`

**Interfaces:**
- Consumes: `AckQueue`, `KeyedLatestOutbox` (M2), `WirePayload` (M1), `PusherFrames` (M5).
- Produces (main thread, never block, never throw):
  - `UplinkClient.SendReliable(string eventName, string innerJson)` → wraps, enqueues in the run's `AckQueue(capacity 500, resendAfter 15 s)`; connection loop sends `Due()` frames on `uplink-data` once `StartSendingData`; reader calls `Ack(id)` on `Acked`; `ResendAllOnReconnect()` on every new connection.
  - `UplinkClient.OfferLatest(string key, string eventName, string innerJson)` → wraps into a frame, `KeyedLatestOutbox.Put(key, frame)`; sent on `uplink-data`; skipped when unchanged per key (one `ChangeDetector` per key).
  - `UplinkSession.Current` (`SessionId` GUID "N", `ColonyId`) created in `Mod.StartLink`; `Mod.StartLink` sends `SessionStart` reliably with `GameClock.Instance.GetTime()` and `GameUtil.GetCurrentCycle()`.
- [ ] **Step 1: Build** `./build-and-install.sh --no-install`. Expected: success.
- [ ] **Step 2: Manual check** with the site from Part 2 Task S4 deployed and Reverb restarted: load a save → `uplink_sessions` gets one row; `Player.log` has no `Uplink:` warnings; stop Reverb 30 s, restart it → the link reconnects and no session is duplicated (idempotent on message id).
- [ ] **Step 3: Commit** `feat: reliable and keyed sending on uplink-data`.

### Task M7: Zones in the save and the zone list

**Files:**
- Create: `Uplink/Zones/ZoneStore.cs` (KMonoBehaviour), `Uplink/Zones/ZoneStorePatches.cs`

**Interfaces:**
- Consumes: `ZoneSet` (M3), `ZoneMessages.ZoneList` (M4), `UplinkClient.SendReliable` (M6).
- Produces: `ZoneStore.Instance` (`ZoneSet Zones`), added by a Harmony postfix on `SaveGame.OnPrefabInit` (`__instance.gameObject.AddOrGet<ZoneStore>()`); `[SerializationConfig(MemberSerialization.OptIn)]` with one `[Serialize] private string zonesJson;` written in `OnSerializing`-equivalent hook (`[OnSerializing]` method) from `Zones.ToJson()` and read in `OnSpawn` with `ZoneSet.FromJson`. On `OnSpawn` and on `Zones.Changed` it sends the zone list reliably (world existence via `ClusterManager.Instance.GetWorld(id) != null`).
- [ ] **Step 1: Verify the hooks** in the game's code (`ilspycmd -t SaveGame`, `KSerialization.OnSerializingAttribute`): note in the class doc which attribute the game calls before writing.
- [ ] **Step 2: Build and install** `./build-and-install.sh`.
- [ ] **Step 3: Manual check**: create a zone with Task M8's tool (or temporarily from a debug key while M8 is pending), save, quit to menu, reload → zone back; the site's `zones` has it. **Disable the mod and load the save**: the game loads (missing component tolerated); re-enable: zones back. Record the result in the README.
- [ ] **Step 4: Commit** `feat: zones saved with the colony and listed to the site`.

### Task M8: The Zone tool

**Files:**
- Create: `Uplink/Zones/ZoneTool.cs`, `Uplink/Zones/ZoneToolPatches.cs`, `Uplink/Zones/ZoneOverlay.cs`, `Uplink/Zones/ZonePicker.cs`

**Interfaces:**
- Consumes: `ZoneStore.Instance.Zones` (M7).
- Produces:
  - `ZoneTool : DragTool` (`GetMode()` → `Mode.Box` for paint/erase); registered by a postfix on `PlayerController.OnPrefabInit` (append an instance to `tools`) and a postfix on `ToolMenu.CreateBasicTools` (add `ToolMenu.CreateToolCollection(…, "ZoneTool", …)`); verified: `DragTool.Mode { Brush, Box, Line }`, `OnDragTool(int cell, int distFromOrigin)`, `OnDragComplete(Vector3, Vector3)`.
  - Modes chosen with the tool parameter menu: **Paint**, **Erase**, **Room**. Room: on click, `Game.Instance.roomProber.GetCavityForCell(cell)`; if the cavity's `room != null`, add all `cavity.cells` to the selected zone; otherwise a hover-card message "Not a room".
  - `ZonePicker`: choose the active zone, "New zone", rename, delete (with confirm). **UI library: PLib (MIT, already used by the user's OniExtract fork), merged into `Uplink.dll` with ILRepack or bundled; needs the user's go-ahead (dependency).**
  - `ZoneOverlay`: while the tool is active, one mesh of translucent quads per zone plus an outline, the selected zone brighter; rebuilt on `Zones.Changed`; hidden when the tool closes.
- [ ] **Step 1: Build and install.**
- [ ] **Step 2: Manual check** in DeepWaterGo: Room mode on the sodicane room fills it (cell count = the room's "Taille de la pièce" in the game's room tooltip); Erase removes a cell; Paint adds; rename to "Ferme sodicane"; the site's zone list follows each change within a second.
- [ ] **Step 3: Commit** `feat: zone tool with paint, erase and room fill`.

### Task M9: Zone readers and sampling

**Files:**
- Create: `Uplink/Zones/ZoneReaders.cs`, `Uplink/Zones/ZoneSampler.cs`
- Modify: `Uplink/Snapshot/PlantsSection.cs` (extract `public static JObject Plant(Crop crop, …)` reuse and add `instanceId` = `KPrefabID.InstanceID` as string), `Uplink/Mod.cs`

**Interfaces:**
- Consumes: `ZoneMessages` (M4), `UplinkClient.SendReliable` / `OfferLatest` (M6), `ZoneStore` (M7).
- Produces:
  - Layout: for each cell of the box, `Grid.Objects[cell, (int)ObjectLayer.FoundationTile]` → tile (`PrefabID().Name`); `Grid.Objects[cell, (int)ObjectLayer.Building]` → distinct buildings (`KPrefabID.InstanceID`, `BuildingComplete.Def.WidthInCells/HeightInCells`, `Building.GetCell()` as anchor, `Rotatable?.GetOrientation().ToString() ?? "Neutral"`).
  - Slow: `Grid.Element[cell].id.ToString()`, `Grid.Mass[cell]`, `Grid.Temperature[cell]` for every box cell.
  - Fast: plants whose cell is in the zone (from `Components.Crops`, same fields as the colony state plus `instanceId`); buildings in the zone: `instanceId`, `prefabId`, `operational` (`Operational.IsOperational`, null when absent), `active` (`Operational.IsActive`), `statusItems` (`KSelectable.GetStatusItemGroup()` entries → `{"id": item.Id, "name": item.GetName(entry.data)}`), `storage` (`Storage.items` grouped by prefab id → `{"id","mass"}`).
  - `ZoneSampler` (MonoBehaviour on the Game object, real-time timers like `Sampler`): every `ZoneFastSeconds` → `OfferLatest("fast:"+zoneId, ZoneFastEvent, …)`; every `ZoneSlowSeconds` → `OfferLatest("slow:"+zoneId, ZoneSlowEvent, …)` and compute the layout; when `LayoutSignature` changed (or the zone version changed) → `SendReliable(ZoneLayoutEvent, …)`. Each zone and each reader isolated (try/catch, skip-log once per zone per session).
- [ ] **Step 1: Build and install.**
- [ ] **Step 2: Manual check**: the sodicane zone's layout arrives once, then again only after building a tile in the room; slow state every 30 s; fast state every 5 s while plants grow; `Player.log` reports `Uplink: zone <name> layout N bytes` once per send.
- [ ] **Step 3: Commit** `feat: zone layout, slow and fast state`.

---

## Part 2: the site (`~/dev/ony-planner`)

Run tests with `php artisan test --compact <path>`.

### Task S1: Element colours

**Files:**
- Create: migration `add_ui_color_to_elements_table` (`ui_color` unsigned integer, nullable)
- Modify: `app/Services/GameImport/OniExport/ElementReader.php`, `app/Services/GameImport/ElementImporter.php`, `app/Models/Element.php`, fixture `tests/Fixtures/OniExport/database/elements.json` (create, with `elementTable` entries for the fixture's elements)
- Test: `tests/Feature/GameImport/OniExport/ElementReaderTest.php`

**Interfaces:**
- Produces: `ElementRecord['ui_color']: int|null` read from `database/elements.json` → `elementTable[*]` matched on `id`, key `uiColor` (24-bit `0xRRGGBB`; Water = 25599 = `#0063FF`, SandStone = 9994805 = `#988235`); `Element::$ui_color` and accessor `uiColorHex(): ?string` (`"#0063ff"`).
- [ ] **Step 1: Write the failing test** `it('reads the element ui colour from elements.json')`: fixture Water `uiColor: 25599` → record `ui_color` 25599; an element missing from `elements.json` → null.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/GameImport/OniExport/ElementReaderTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** reader (read `elements.json` once; missing file → all null), importer column, model cast and accessor.
- [ ] **Step 4: Run** the test and `tests/Feature/GameImport`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: import element ui colours`.

### Task S2: Uplink payload unwrapping

**Files:**
- Create: `app/Services/Uplink/UplinkPayload.php`
- Modify: `app/Listeners/AnswerUplinkBenchmark.php` (use it)
- Test: `tests/Unit/Services/UplinkPayloadTest.php`

**Interfaces:**
- Produces: `final class UplinkPayload { public static function decode(mixed $data): ?array }` accepting `{"payload": base64}` (gzip detected by `1f 8b`), a JSON string, or an array; returns the decoded associative array or null when unreadable (never throws). Max decoded size 64 MB (gzip bomb guard: `gzdecode($bytes, 64 * 1024 * 1024)`).
- [ ] **Step 1: Write the failing tests**: decodes base64+gzip, base64, JSON text, array; returns null for broken base64, for a non-object JSON, for gzip over the limit.
- [ ] **Step 2: Run** the test file. Expected: FAIL.
- [ ] **Step 3: Implement**; switch the bench listener to it; keep `AnswerUplinkBenchmarkTest` green.
- [ ] **Step 4: Run** both test files. Expected: PASS.
- [ ] **Step 5: Commit** `refactor: shared uplink payload decoding`.

### Task S3: Sessions, zones and versions storage

**Files:**
- Create (via `php artisan make:model -mf`): `UplinkSession`, `Zone`, `ZoneVersion`
- Modify: `app/Services/Uplink/ColonyIngestor.php` (pruning)
- Test: `tests/Feature/Uplink/ColonyIngestorTest.php`, `tests/Feature/Models/ZoneModelsTest.php`

**Interfaces:**
- Produces tables (all with timestamps):
  - `uplink_sessions`: `colony_guid` string, `session_id` string unique, `loaded_game_seconds` double, `cycle` int, `started_at` datetime.
  - `zones`: `colony_guid` string, `zone_guid` string, unique (`colony_guid`,`zone_guid`); `name` string; `world_id` int; `version` int; `cell_count` int; `orphaned` bool default false; `archived_at` nullable datetime; `layout` json nullable; `slow_state` json nullable; `fast_state` json nullable; `layout_at`, `slow_state_at`, `fast_state_at` nullable datetimes.
  - `zone_versions`: `zone_id` FK cascade, `version` int, `cells` json (sorted list), `valid_from_game_seconds` double; unique (`zone_id`,`version`).
  - Models: `Zone hasMany versions`, `Zone::scopeActive()` (not archived), casts for json/datetimes; factories with realistic values (sodicane room cells).
  - `ColonyIngestor` pruning keeps colonies that own zones: `Colony::query()->whereNotIn('id', $keptIds)->whereNotIn('guid', Zone::query()->select('colony_guid'))->delete()`.
- [ ] **Step 1: Write the failing test** in `ColonyIngestorTest`: `it('never prunes a colony that owns zones')` (11 colonies, the oldest owns a zone → it survives).
- [ ] **Step 2: Run** it. Expected: FAIL.
- [ ] **Step 3: Implement** migrations, models, factories, pruning.
- [ ] **Step 4: Run** `tests/Feature/Uplink tests/Feature/Models`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: zones, versions and uplink sessions storage`.

### Task S4: Ingesting `uplink-data`

**Files:**
- Create: `app/Listeners/IngestUplinkData.php`, `app/Actions/Uplink/StartUplinkSession.php`, `app/Actions/Uplink/SyncZoneList.php`, `app/Actions/Uplink/StoreZoneLayout.php`, `app/Actions/Uplink/StoreZoneState.php`, `app/Events/ZoneUpdated.php`
- Test: `tests/Feature/Uplink/IngestUplinkDataTest.php`, `tests/Feature/Uplink/ZoneActionsTest.php`

**Interfaces:**
- Consumes: `UplinkPayload::decode` (S2), models (S3).
- Produces:
  - `IngestUplinkData::handle(MessageReceived $event): void`: cheap prefix check (first 200 bytes contain `"uplink-data"` or one of the five event names), decode envelope, `UplinkPayload::decode($envelope['data'])`, dispatch by event; for reliable events, after the action succeeded, `$event->connection->send(json_encode(['event' => 'uplink.ack', 'channel' => 'uplink-data', 'data' => json_encode(['id' => $id])]))`; never throws (`report()`); refuses `schema` > 1 (no ack, logged once per colony).
  - `StartUplinkSession::handle(array $message): void` (upsert on `session_id`).
  - `SyncZoneList::handle(array $message): void`: upsert each zone (name, world, version, cell count, orphaned, `archived_at = null`); zones of that colony absent from the list get `archived_at = now()`.
  - `StoreZoneLayout::handle(array $message): void`: upsert zone `layout`, `layout_at`, `version`; insert a `zone_versions` row only when `cells` differ from the latest version's cells (`valid_from_game_seconds` = message `gameSeconds`).
  - `StoreZoneState::handle(string $kind, array $message): void` (`slow`|`fast`) → column + `_at`; unknown zone → ignored (the list arrives first).
  - `ZoneUpdated implements ShouldBroadcast` (queued) on public channel `uplink`, `broadcastAs()` = `zone.updated`, payload `{zoneGuid, colonyGuid, kind}`; dispatched by the actions; for `fast` states throttle to one per zone per 2 s (cache lock).
- [ ] **Step 1: Write the failing tests**: 
  - `it('acks a zone list after storing it')` (fake connection captures sent frames; ack id equals the message id);
  - `it('is idempotent on the same message twice')` (two identical layouts → one `zone_versions` row, two acks);
  - `it('opens a new version only when cells change')`;
  - `it('archives zones missing from the list and restores them when they return')`;
  - `it('stores the orphaned flag')`;
  - `it('does not ack and stores nothing for a newer schema')`;
  - `it('ignores frames on other channels and the colony state')`;
  - `it('broadcasts zone.updated on the uplink channel')` (`Event::fake([ZoneUpdated::class])`).
  Messages built with a helper `uplinkData(string $event, array $inner): string` that wraps `{payload: base64(gzip(json))}` like the mod.
- [ ] **Step 2: Run** the two test files. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `tests/Feature/Uplink`. Expected: PASS.
- [ ] **Step 5: Restart Reverb and check with the bench**: `php artisan uplink:bench --sizes=100,1000 --encoding=base64` still acks (no regression of the event loop). Measure against a Reverb started with `XDEBUG_MODE=off`.
- [ ] **Step 6: Commit** `feat: ingest zone messages from uplink-data with acks`.

### Task S5: Zone pages (server)

**Files:**
- Create: `app/Http/Controllers/ZoneController.php`, `app/Services/Zones/ZonePresenter.php`, `app/Data/ZoneListItemData.php`, `app/Data/ZoneShowData.php`, `app/Data/ZoneMapData.php`, `app/Data/ZoneEntityData.php`, `lang/en/zones.php`, `lang/fr/zones.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Zones/ZoneControllerTest.php`, `tests/Unit/Services/ZonePresenterTest.php`, `tests/Feature/Zones/ZoneTranslationsTest.php`

**Interfaces:**
- Consumes: `Zone` (S3), `Element::uiColorHex()` (S1), game data models (`Building`, `Plant`, `PrefabName` for names; sprite paths like `GameSprite` uses).
- Produces:
  - Routes: `GET /zones` → `zones.index`, `GET /zones/{zone}` → `zones.show` (route model binding on `zones.id`; archived zones → 404 on show, hidden from index).
  - `ZonePresenter::present(Zone $zone): ZoneShowData` with: `zone` (id, name, worldId, version, cellCount, orphaned, updated times), `map: ZoneMapData` (`gridWidth`, `box`, `zoneCells` list of `[x,y]`, `terrain` list of `{x,y,elementId,colour,mass,temperature}` from slow state, `tiles` `{x,y,prefabId,connections}` where `connections` is the 4-neighbour mask of same-prefab tiles (bit order up=1,right=2,down=4,left=8, matching `public/assets/game/connections/<prefab>/<mask>.webp`), `buildings` `{x,y,width,height,prefabId,name,sprite}`, `plants` `{x,y,instanceId,prefabId,name,sprite,growth (0..1),secondsUntilHarvest,wilting,grown}`), `entities: list<ZoneEntityData>` (plants and buildings with `kind`, `name`, `sprite`, `x`, `y`, `state` (`growing`|`grown`|`wilting`|`operational`|`not_operational`), `problems` list of status names, growth, time to harvest, auto-harvest), `problems` (entities with problems first), `counts` per kind.
  - Problems rule (slice A): a plant that is wilting; a building whose `operational` is false; any status item whose id is in `ZonePresenter::PROBLEM_STATUS_IDS` (start with the ids seen on the farm station's missing fertilizer and on wilting; the list is a constant, documented).
  - `ZoneController::index` (active zones of the latest colony with name, cell count, problem count, last update) and `show`.
- [ ] **Step 1: Write the failing tests**: presenter maps a fixture zone (sodicane room: 40 cells, 13 plants, one farm station with the missing-fertilizer status) to 13 plant entities with `x`/`y` from `cell % gridWidth` and `intdiv(cell, gridWidth)`, one problem, terrain colours as hex, tile connection masks; controller renders `Zones/Show` with `zone.name`, 404 for archived, index lists only active zones of the latest colony; fr/en key parity.
- [ ] **Step 2: Run** the three files. Expected: FAIL.
- [ ] **Step 3: Implement**; generate TS types (`php artisan typescript:transform` as the project does) and Wayfinder routes.
- [ ] **Step 4: Run** `tests/Feature/Zones tests/Unit/Services/ZonePresenterTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: zone pages server side`.

### Task S6: Zone pages (client)

**Files:**
- Create: `resources/js/pages/Zones/Index.vue`, `resources/js/pages/Zones/Show.vue`, `resources/js/components/zones/ZoneMap.vue`, `resources/js/components/zones/ZonePanel.vue`, `resources/js/components/zones/ZoneEntities.vue`, `resources/js/composables/useZoneLive.ts`
- Modify: the app navigation (where `colony.nav.*` links are) to add "Zones"
- Test: `tests/Browser/ZonePageTest.php` (only if the project's browser tests run in CI; otherwise the manual check below)

**Interfaces:**
- Consumes: `ZoneShowData` TS type (S5), Wayfinder `ZoneController.show`, `GameSprite` component, `useT`.
- Produces:
  - `useZoneLive(zoneGuid: string, only: string[])`: `useEchoPublic('uplink', '.zone.updated', …)` filtered on `zoneGuid`, debounced `router.reload({ only })` (400 ms, like `useUplink`); returns `live` / `ageLabel` from the zone's latest `*_at`.
  - `Show.vue`: header (crest sprite of the most common plant, name, room chip when relevant, cell count, live indicator), tabs **Carte** / **Entités** (Historique tab shown disabled with "slice B" omitted: not rendered in slice A), layout per mockup v3.
  - `ZoneMap.vue`: draws box cells; terrain colour per element (mixed in sRGB with `--ink-0`, gas at 10 %, solids 45 %, liquids 35 %), tiles with connection sprites, buildings and plants with world sprites anchored bottom-left, zone outline (amber, per-cell edges), layers **Croissance** (plant growth bar), **Matériaux** (default terrain), **Température** (cell colour by temperature, blue to amber scale with legend), **Problèmes** (dims everything but problem entities, red pin); hover card; click selects and emits `select(instanceId)`. Must stay smooth at 2 000 cells (canvas for terrain if the DOM grid is slow; builder's choice, measured).
  - `ZonePanel.vue`: verdict with illustration, "À régler" list (sprite + missing item icon when the status names an element), contents counts, selection detail. No production/efficiency in slice A.
  - `ZoneEntities.vue`: filters with counts (Problèmes, Plantes, Bâtiments), table per mockup, problems first then by time to harvest; row click switches to the map tab and selects.
- [ ] **Step 1: Implement** the composable and components.
- [ ] **Step 2: Run** `bun run lint` and `bun run types` (or the project's equivalents in `package.json`). Expected: no errors.
- [ ] **Step 3: Manual check** with the game running (dev server is always on): `/zones` lists "Ferme sodicane"; its page matches the game (13 sodicanes at the right cells, farm station problem, growth bars); a harvest in game updates the bars within ~5 s without reloading the page; Température layer shows the room's 35 °C.
- [ ] **Step 4: Commit** `feat: zone pages`.

### Task S7: End-to-end verification

- [ ] **Step 1:** Run every suite: `dotnet test ~/dev/uplink/Uplink.Core.Tests`, `php artisan test --compact`, `bun run lint`.
- [ ] **Step 2:** Walk the spec's "Verification with the user" items that belong to slice A (zone by room click, erase a cell, reload the save, the site follows; zone page correct) with the user, in the game.
- [ ] **Step 3:** `php artisan uplink:bench --encoding=base64 --sizes=100,1000,4000` and note the numbers in the plan's PR description.
- [ ] **Step 4:** Update `~/dev/uplink/README.md` (protocol: channels, events, wire format, acks, settings) and commit in both repos.
