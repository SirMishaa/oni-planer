# Zones foundation (with plant farms as the proof) — design

Date: 2026-11-12. Status: draft for approval.

## Why

Uplink (project of 2026-11-11) streams the whole colony to the site every few seconds:
duplicants, food, plants, critters. It answers colony-wide questions (food viability) but
nothing about **one place** in the base: "is my sodicane farm running, what blocks it, what
does it produce, what does it eat, how efficient is it?".

The user wants a **generic zone system**: in the game, they mark an area (a room, a farm, a
power plant, later a whole factory); the site models that area faithfully (cells, elements,
buildings and their states, entities) and keeps it in sync. Specialised readings are then
built on top of a zone: farms first, later ranches, power, construction planning.

The rule behind it, stated by the user: **no forms**. Nobody types "12 lettuces" into the
site. If a value does not come from the game, the site does not have it.

This project builds the foundation and validates it with **one** reading: plant farms.

## Goals

1. An in-game **Zone tool** to create, paint, erase, rename and delete named zones; a click
   inside a room fills the zone with that room's cells.
2. Zones are **saved with the colony save** and survive a reload.
3. The mod **syncs each zone** to the site: its layout, its live state, and events, each at
   its own controllable rate.
4. The mod sends **plant events for the whole colony** (harvest, ripe, wilt, recover), so a
   zone's history starts when the mod was installed, not when the zone was drawn.
5. The site stores zones, the latest state of each, plant events and per-cycle summaries.
6. A **zone page** in the site: a map tab (map first, right-hand panel, layers, harvest
   timeline), an entities tab (one row per entity, its state), a history tab.
7. **Farm reading**: production per cycle, consumption, conditions against the plant's
   tolerances, problems, and **efficiency**, both one figure per zone (to compare farms)
   and per plant (to find the bad one), broken down by cause.
8. **Rules documentation** in the site (LaRecipe), so every calculation rule is written
   down, linked to the code and the game source, and reviewable.

## Non-goals (this project)

- Critter ranches (next reading on the same foundation; the user chose plants to validate).
- Power, construction planning, any other reading.
- Creating or editing a zone from the site (the game is the source of truth for geometry).
- Game element textures for terrain (colour per element now; textures later, agreed).
- Game HUD icons (thermometer, status icons): not in the OniExtract export; generic icons
  for now, extraction later.
- Authentication / private channels (still local only, as in the Uplink spec).
- Persisting the mod's unsent event queue across game restarts (accepted loss; see Parts).

## Anti-goals

- Any manual data entry for something the game knows.
- A guessed value presented as read. When the mod cannot read something, the site says it
  is unavailable (same rule as the Uplink spec).
- The game waiting on the network, or a zone tool that makes the game stutter.
- The Reverb listener doing heavy work: it runs on Reverb's event loop; heavy work freezes
  every socket.
- Being capped by WebSocket message size: sending a whole factory once must work.
- Archiving raw states as history (10 zones × 10 KB × every 5 s ≈ 1.7 GB/day).

## Decisions made by the user

- Generic zones first; farms are a reading of a zone, not the foundation.
- Scope of readings: plants and critters eventually; **plants** for this project.
- Selection like the PlanningTool mod (paint cells with a drag tool, eraser) **plus** a click
  in a room fills the zone with the room. PlanningTool has no licence: ideas only, no code.
- Only designated zones are detailed; the colony-wide snapshot continues as today.
- A cell may belong to several zones. Zones live in the save. Geometry is edited in game only.
- Zone page layout (mockup v3, `.superpowers/brainstorm/…/zone-layout-v3.html`): mockup A
  (map first, panel on the right) with B's layers and harvest timeline, an **Entities** tab
  listing every entity with its state, game sprites and icons used generously.
- Terrain drawn as a colour per element now, game textures later.
- Record the harvest moment to compare ideal vs real time and derive efficiency; the user
  wants **both** a zone figure (compare farms) and per-plant detail.
- Send harvests for the whole colony, not just zones.
- Refresh rates must be controllable: some data needs 5 s, some does not.
- No message-size cap: sending a whole factory once must work. **Measure before designing
  around a limit** (see "Transport, measured").
- **Everything goes over Reverb** (WebSocket); no HTTP channel from the mod: the UI already
  needs WebSocket for live updates.
- Add LaRecipe and start documenting the rules now.
- The user may be asked to run REA for decompilation; REA (and `ilspycmd`, installed) are
  available to verify game APIs.
- Efficiency breakdown: **slow growth is split by cause now** (wild, missing fertilizer or
  irrigation, temperature, pressure, atmosphere, light, radiation…), which needs a new event
  family from the mod (growth condition changes).
- Editing a zone's cells **freezes the past**: closed cycles keep the figures of the old
  shape; the new shape counts from the edit on.
- Consumption counts: plant inputs (game data), the farm station's fertilizer, and a simple
  **power cost of the lamps** in the zone.
- The wiki's plant table (oxygennotincluded.wiki.gg/wiki/Plant) and the mutation multipliers
  are the reference for what plant data the site should have.

## Parts

### 1. Mod: the Zone tool (in game)

- A toolbar button and a hotkey open the Zone tool, a `DragTool` subclass (like Dig). The
  tool has a zone picker (existing zones + "new zone"), a **paint** mode, an **erase** mode,
  and **room fill**: clicking a cell that belongs to a room adds all the room's cells
  (`Game.Instance.roomProber` → the cavity's cells). Rename and delete are in the picker.
- While the tool is active, every zone is drawn as a translucent tint with an outline; the
  selected zone stands out. Zones are not drawn when the tool is closed.
- A zone: stable id (GUID), name, world id, set of cells. Cells may overlap other zones.
- **Saved in the colony save** through a `KMonoBehaviour` on a persistent game object with
  `[Serialize]` fields (the usual ONI mod pattern; builder verifies the exact hook).
- A zone whose world is destroyed (rocket interior removed…) is kept and marked orphaned.

### 2. Mod: what it sends

Four kinds of data, each with its own cadence:

| Kind | Content | When |
|---|---|---|
| **Layout** | per cell: built tile / building id and its anchor, orientation, size; zone outline | on zone create/edit, on build/deconstruct inside the zone, on site request |
| **Slow state** | per cell: element, mass, temperature | configurable, default 30 s |
| **Fast state** | entities in the zone: plants (existing plant fields + **stable instance id**), buildings' operational state and status items (e.g. "fertilizer missing 10 kg"), storages | configurable, default 5 s |
| **Events** | plant harvest / ripe / wilt / recover / **growth conditions changed**, colony-wide | immediately |
| **Zone list** | ids, names, worlds of every zone in the save | on load and on any create/rename/delete |

- **Transport, measured** (`php artisan uplink:bench`, k6 + Excimer, 2026-11-12, local):
  with `data` as JSON, Reverb decodes it and its `Validator::make($event, …)` walks the whole
  decoded array, blocking the event loop (most of Reverb's time in `Validator::parseData`).
  Round trips with Reverb started with `XDEBUG_MODE=off` (Xdebug's develop mode roughly
  doubled every figure): JSON 100 KB 17 ms, 1 MB 165 ms, 4 MB 680 ms; `data` sent as
  `{"payload": "<base64>"}` (content kept opaque): 8 ms, 61 ms, 260 ms (×2.6). Reverb requires
  client-event `data` to be an object, hence the one-key wrapper.
- **Wire format (all new messages)**: `data = {"payload": base64(gzip(json))}`; the site
  unwraps it (base64, then gzip when the bytes start with `1f 8b`). The colony state may stay
  as it is (73 KB) or move to the same format; builder's choice. No chunking: gzip makes
  the 5 MB frame limit (`REVERB_APP_MAX_MESSAGE_SIZE`) far away for cell data; if a real
  payload exceeds it, raise the limit (measure with `uplink:bench` first).
- **Channels**: browsers subscribe to `uplink`; client events are fanned out to every other
  subscriber, so heavy mod data (layouts, states, events, zone list, session start) goes on a
  dedicated channel **`uplink-data`** that pages never subscribe to. After storing, the site
  broadcasts a small server event on `uplink` (zone id, kind) so open pages reload their props.
- **Acknowledgements by correlation id** (at-least-once delivery, idempotent receiver, like
  MQTT QoS 1): every message the mod must not lose (events, layouts, zone list, session start)
  carries a message id; the listener stores it, then answers the sender directly
  (`$event->connection->send(…)`, proven by the bench listener) with `{"id": …}`. The mod keeps
  each message in its ordered queue until acked and resends unacked ones after a reconnect or
  a timeout; the site ignores duplicates (unique keys below). Latest-only states are not
  acked.
- Only send a state when it changed (as today).
- **Rates are set from the site**, per zone and per kind, and pushed to the mod over the same
  Reverb channel (an event the mod listens to; it replies with what it applied). Defaults
  apply until the site has spoken. `uplink.json` stays for the URL and the global interval.
- **Plant events** come from the game's own event system, not polling: the mod registers on
  `Components.Crops` (`Register(onAdd, onRemove)`) and subscribes on each crop's game object.
  Verified in build 744825: `Crop` triggers `CropPicked` (-1072826864) when a harvest spawns
  the fruit (`SpawnAndGetConfiguredFruit`, `wasCropPicked: true`); `Harvestable.Harvest()`
  triggers `Harvest` (1272413801). `Wilt` (-724860998) / `WiltRecover` (712767498) exist.
  **To verify:** which of `CropReady` (-1686619725) / `GrowthStateMature` (-2116516046)
  fires when a plant becomes ripe, and on which object for tree branches.
- **Growth conditions changed** (needed for the slow-growth breakdown): for every plant in the
  colony, the mod keeps the last known set {domestic or wild, growth rate (maturity delta),
  each requirement met or not with its reason: fertilizer, irrigation, temperature, pressure,
  atmosphere, light, radiation; mutation id} and emits an event when it changes. The
  readings come from the plant's own components (status items / requirement monitors such as
  `WiltCondition`, `ReceptacleMonitor`, fertilization/irrigation monitors); the exact API per
  requirement is **to verify** in the game's code (REA / ilspycmd). The check rides on the
  fast-state sampling for all crops (cheap: a few hundred plants), events only on change.
- Each plant in the colony state also carries its **current condition set** (same shape):
  a resync point. If the site's reconstructed intervals disagree with it, or a session has a
  gap (queue overflow, offline close), the affected harvest interval is marked
  **incomplete** and gets no efficiency rather than a guessed one.
- Plant fields in the colony state gain `instanceId` and `mutationId`.
- Lamps in a zone (buildings tagged `LightSource`) report on/off in the fast state.
- **Sessions and sequence**: each colony load starts a **session** (new id) and the mod sends
  a session-start message with the loaded game time (total game seconds). Every event carries
  the session id and a **monotonic sequence number** within it; the site's unique key is
  (colony, session, seq), never a float game time. On session start, the site marks events of
  earlier sessions later than the loaded game time as **superseded** (kept, excluded from
  every calculation). A gap in a session's sequence marks that session's affected intervals
  incomplete. (Designer's call.)
- Every event carries: plant stable id (`KPrefabID.InstanceID`), prefab id, cell, world,
  game time (cycle + seconds in cycle, and total game seconds), real time, and for a harvest
  the crop id and units.
- **Events never use the latest-only outbox**: a separate ordered queue, kept in memory while
  the site is unreachable, sent in batches, removed only once the site acknowledged them,
  capped (drop oldest, log once; the gap shows in the sequence). Lost if the game closes
  while offline: accepted, and visible as a gap.
- `schema` goes to 2 for the colony state only if its shape changes; zone messages and
  events carry their own `schema`.

### 3. Site: receiving and storing

Measured and estimated volumes (local machine): colony state 73 KB every ≥5 s (~15 KB/s);
a 128-cell zone ≈ 10 KB; harvests ≈ 5–15 per minute at ×3 speed, ~200 B each. Total
~30–40 KB/s: negligible for Reverb. Octane is not on this path (the listener runs in the
Reverb process). The constraints are storage and the event loop, not bandwidth.

- The Reverb listener only **unwraps, validates and hands off**: small writes inline (latest zone
  state upsert, event insert) or dispatch to the queue; anything that computes (summaries,
  efficiency) runs in **queued jobs** (the queue already runs with the dev stack).
- Tables (user data, never truncated by `game:import`):
  - `zones`: colony, mod zone GUID, name, world, cells (packed), layout (latest), slow state
    (latest), fast state (latest), rates, last seen.
  - `uplink_sessions`: colony, session id, loaded game seconds, started at.
  - `plant_events`: colony, session, seq, plant instance id, prefab id, cell, world, type,
    payload (conditions for condition events), game seconds, cycle, real time, crop id, units,
    superseded flag. Unique on (colony, session, seq).
  - `zone_cycle_summaries`: zone, cycle, harvested units per crop, efficiency figures and
    their breakdown, consumption. A **cache**: a pure function of (non-superseded events,
    zone version valid at each event's time), recomputed by a job whenever its inputs change.
  - `zone_versions`: zone, cells, valid from (game seconds). Editing cells in game opens a new
    version; events are attributed to a zone using the version valid at the event's game time.
    Cycles before the zone's first version use that first version (history from mod install).
- No raw state history. Retention of `plant_events` is unlimited (≈50 rows/cycle).
- **The past is frozen by zone versions alone**: a cell edit opens a new version, so a
  recompute of an old cycle still uses the shape the zone had then. Summaries stay
  recomputable at any time.
- **Deleted zones** (absent from the mod's zone list) are archived, not deleted: hidden from
  the zones list, history kept. (Designer's call.)
- **Colony pruning**: `ColonyIngestor` keeps the 10 most recent colonies; a colony that owns
  zones or plant events is never pruned. (Designer's call.)
- Broadcast to the browser: the zone page listens on a channel and refreshes from the
  latest stored state; it does not re-parse mod messages itself.

### 4. Site: the farm reading (calculations)

Plain PHP, unit-tested, each rule documented (Part 6).

- **Membership**: a plant belongs to a zone when its cell is in the zone's cells.
- **Production per cycle**: from the game's live values (crop units × 1 / crop duration for
  each growing plant) **and** the measured harvests per cycle from `plant_events`.
- **Consumption**: per plant, from game data inputs (`plant_variant_inputs`: irrigation and
  fertilizer per cycle, e.g. sodicane: salt 10 kg/cycle), only while the plant grows
  domestically (mutation fertilizer multipliers applied); the **farm station's** fertilizer
  per tending; **lamps**: rated power from game data (`buildings.power_consumption`) × the
  share of samples where the lamp was on, shown in W and kJ/cycle. Nothing else is measured
  in this project.
- **Conditions**: zone cell temperatures / atmosphere / pressure (slow state) against the
  plant variant's tolerances (`plant_variants`).
- **Problems**: status items the mod reports (wilting and its reasons, missing fertilizer,
  building not operational), ranked first everywhere.
- **Efficiency** for one plant, between two harvests:
  `ideal = crop duration` (the plant's maturity maximum × 600 s, as the mod already sends it,
  mutations included) and `real = time between the two harvests`; `efficiency = ideal / real`.
  The gap is split into **slow growth by cause**, **wilted** (time between wilt and
  recover), **waiting for harvest** (ripe → harvested). Slow growth comes from the growth
  condition intervals: over each interval of length `dt` at growth rate `r` against the
  domestic rate `r₀`, the lost time is `dt × (1 − r / r₀)`, attributed to the causes active
  in that interval (wild, each unmet requirement); when several are active, split equally
  (designer's call, documented as a rule so it can be reviewed). Zone efficiency = weighted over its plants' harvest cycles in the
  period. A plant with fewer than two harvests has no efficiency yet (shown as such).
- Tree branches (each branch its own crop, already sent per branch): each branch is one
  "plant" for efficiency.

**Game data gaps found (to close in the OniExtract pipeline, in this project):**
`plant_variants.atmosphere_element_id` is empty for all 54 variants and is a single value
where the game accepts a list of safe elements; light is a boolean without the lux needed;
radiation tolerance (Spaced Out) is not stored; element `uiColor` (needed for the map) is in
the export but not imported. Conditions that depend on a missing field are shown as
unavailable until the import provides them.

**Reference for completeness: the wiki's plant table.** Its columns are what a player
expects to know per plant: where it grows, height, decor, temperature range, pressure range,
accepted gases, light (lux), irrigation and fertilizer (domestic and wild), product, mature
time (domestic and wild, wild = ×4), kcal per cycle, max rads per cycle (domestic, wild),
germs. Mutations: yield, life cycle and fertilizer multipliers (e.g. Bountiful yield ×2,
fertilizer ×1.2; Exuberant life cycle ×0.25, fertilizer ×1.5), plus light/temperature/extra
product effects. The **source stays the game export** (OniExtract), never a wiki scrape
(the wiki is a derived work under its own licence and can be wrong); the wiki table is used
as a **cross-check** in the import tests for a few plants (e.g. Sodicane: 10–40 °C,
150 g–10 kg, O₂/polluted O₂/CO₂, 10 kg salt, 4/16 cycles). Fields the export lacks are
added to the OniExtract fork. The mutation data already in `plant_mutation_effects` is
checked against the same multipliers.

### 5. Site: the zone page

From mockup v3 (layout and icons), mid-fidelity, with the existing dark tokens.

- Header: crop crest sprite, zone name, room type when the zone matches a room, cell count,
  world, live indicator ("live · 3 s ago", stale when the mod is silent).
- **Map tab**: the zone and a margin around it, drawn from layout + slow state: terrain as a
  colour per element (`uiColor` from the export), built tiles with connection sprites,
  buildings and plants with world sprites, zone outline. Layers: Growth (bar per plant),
  Materials, Temperature, Problems. Hover card per entity. Click selects.
  - Right panel, in this order: **state** verdict with illustration ("Running, 1 thing to
    check"), **to fix** (problems with building sprite + missing item icon, link to locate),
    **production** (food icon, units/cycle, last 10 cycles), **efficiency** (zone %, broken
    down), **consumption** (element icons), **conditions**, **selection**.
  - Bottom: harvest timeline over the next cycle with food icons grouped when close.
- **Entities tab**: filters (Problems, Plants, Buildings, later Critters) with counts; one
  row per entity: sprite, position, state, growth, time to harvest, yield, auto-harvest,
  **efficiency**; problems first, then by next harvest; a row click jumps to the map.
- **History tab**: per-cycle production and efficiency of the zone; per-plant efficiency.
- **Zones list**: all zones of the colony with their headline figures (efficiency, production,
  problems), to compare farms.
- Responsive enough to be usable next to the game; phone is not a goal.

### 6. Rules documentation (LaRecipe)

- Install `saleem-hadad/larecipe` (supports Laravel 13; user approved the dependency).
- One page per rule set: zone membership, production, consumption, conditions, efficiency
  and its breakdown, event semantics. Each rule states: the formula, the game source it
  relies on (class/method, build), the code that implements it, the test that covers it.
- Also a page for the Uplink protocol (states, zones, events, sessions, rates, wire format, acknowledgements), replacing the
  spec as the living reference once shipped.

### 7. Code organisation (designer's call, for the user to confirm)

- Keep the project's current pattern, no new package: **single-purpose classes** with one
  public method, grouped by domain under `app/Services/<Domain>` (as `Viability/` and
  `Uplink/` today) — e.g. `Zones/`, `Farming/` — with spatie/laravel-data objects in and out.
  Writes triggered by the mod go through small action classes in the existing (empty)
  `app/Actions/` folder, called from the listener or from jobs.
- `lorisleiva/laravel-actions` is not needed: its value is running one class as controller,
  job, listener and command; here the listener must stay thin, jobs are few, and pure
  calculators are easier to test and document as plain classes.

## Delivery slices

One project, three slices planned and shipped in order (from the direction review; no
decision of the user is dropped):

- **A. Foundation**: zone tool, save persistence, zone list, sessions, `uplink-data` channel with acks, layout
  and slow/fast state sync, `uiColor` import, zone storage and versions, map tab (layers,
  panel without efficiency) and entities tab. Rates from `uplink.json` defaults.
- **B. Farm reading**: plant events (harvest, ripe, wilt, recover) with sessions/seq,
  production, consumption (plants, farm station, lamps), problems, efficiency with the
  **wilted** and **waiting for harvest** parts, history tab, zones list, site-set rates.
- **C. Causes and data**: growth-condition events and the slow-growth breakdown by cause,
  OniExtract gaps (atmosphere list, lux, rads, …) with the wiki cross-check, LaRecipe and the
  rules pages (pages for A and B rules are written in C at the latest).

## Constraints from later projects

- Ranches will reuse zones, layout/state sync, events (eggs laid, hatch, death, butcher —
  to verify in game code) and the entities tab. Do not hard-code "plant" into zone storage or
  the page frame.
- Power and factories will need large one-off layouts: layout messages must not assume a farm-sized zone (measure with `uplink:bench`).
- Game textures will replace element colours: keep terrain drawing behind one function.

## Left to the builder

- Exact save hook for zones, tool UI details (picker look, hotkey), tint colours in game.
- Event queue cap, batch size, ack timeout before resend.
- Packing of cells in the database; whether layout/states are columns or a side table.
- Which status items count as "problems" beyond wilt reasons and missing deliveries.
- Map rendering technique (DOM grid, canvas, SVG) as long as it handles a few thousand cells.
- Names of channels and events, keeping the `client-…` convention for mod → site.

## Verification with the user

- In game: create "Ferme sodicane" by clicking the room, erase a cell, reload the save: the
  zone is back. Change it: the site updates.
- Site: the zone page shows the 13 planted sodicanes at the right place, the farm station
  problem, production, and the timeline matching the game's hover times.
- Harvest a plant (or let auto-harvest run): the event appears, and after two harvests the
  plant gets an efficiency with its breakdown.
- Change the slow-state rate from the site: the mod applies it (log line) and the "last
  updated" times follow.
- The docs show each farm rule with its formula, game source and test.
