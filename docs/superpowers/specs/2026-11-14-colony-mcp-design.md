# Colony MCP server — design

Date: 2026-11-14. Status: draft for approval.

## Why

The planner holds the whole colony as synced by Uplink (colony state, zones with layout, slow and
fast state, captures) plus the imported game data. Today the AI assistant (Claude Code) can only
reach it through ad-hoc tinker queries and raw JSON, which is slow, costly in tokens and gives no
spatial picture. The user wants to **ask questions about their game** in conversation — sync
state, what is in a zone, which entity is in trouble, what to change in a layout — and later let
the assistant **plan and build rooms**. A local MCP server, built inside the app on the same
models and services as the UI, is the integration point for all of that.

The consumer is an LLM. Output must be **token-compact** and **spatially unambiguous**; the user
does not need to read it (they have the UI and the captures).

## Goals (v1)

The user's criteria, in their order:

1. The assistant gets a **very clear idea of where things are** in a zone.
2. The assistant can **recommend layout / design changes**.
3. The assistant knows the **composition of the cells** (element, mass, temperature).
4. The assistant knows the **state of entities** (age, calories, tame, plant growth, building
   operational / status items) — with what the mod sends today.
5. The assistant can **look up any game object** (element, building, critter, plant, food, item,
   codex entry) by id or name and get its useful data and its **texture** (WebP sprite).
6. The assistant can **see the game captures** (WebP) to check before / after, and the **app's
   real rendering** of the zone page.
7. Everything is **explorable**: from a zone to an entity, from an entity to its game object,
   from a game object to related objects.

## Non-goals (this project)

- **Critter happiness / mood / overcrowding / fertility**: needs a mod change; goes to the
  critters project. The MCP shows it once the mod sends it.
- **Overlays**: pipes, wires, automation, conveyors, detected rooms, and any producer / consumer
  balance per zone (power, liquids). A future project of its own.
- **Server-side verdicts** ("kill this critter", "this ranch is unbalanced") and their
  documentation as rules: later; v1 exposes facts and the problems the app already computes.
- **Full machine panel** (contents, recipe, everything the game shows on click): later.
- Room planning and construction (write tools beyond `request_capture`): later.
- Remote access, auth, HTTP transport: local stdio only.
- MCP resources, prompts and UI apps: tools only for v1.
- State history / diffing over time: no history is stored beyond captures and zone versions.

## Anti-goals

- **Duplicated logic**: the MCP must not reimplement what `ZonePresenter`, `CritterPresenter`,
  `FoodViability` compute. Shared logic is extracted and used by both the UI and the MCP.
- **A guessed value presented as read** (rule from the zones spec). Derived values (critters
  matched to a zone by cell) are marked `derived`; missing values say `unavailable`.
- **Stale data presented as current**: every response carries the age of its data.
- **Cached colony state** in the long-lived stdio process (same trap as Octane): no statics or
  singletons holding colony / zone state; read the database on every call.
- **Raw JSON dumps** of zone state: a 20 KB `slow_state` must never reach the model as-is.
- Images sent unrequested (they cost tokens).

## Decisions made by the user

- MCP local only, for the assistant in Claude Code.
- `laravel/mcp` installed as a direct dependency (done, `^1.2`, 2026-11-14) so its
  `mcp-development` Boost skill is available; follow that skill and `search-docs`.
- Captures in WebP are part of v1 (before / after, real rendering).
- Global `lookup` of any block / object, with its texture and useful info.
- Critter happiness → critters project. Overlays and producer / consumer → later project.
- Verdict rules computed server-side and documented → later, out of scope.
- The ASCII map is for the assistant, not for the user; its readability for the user is not a
  goal.
- `request_capture` is the first write tool.
- Room examples for later planning and for tests: the technical zones (Research lab, Dormitory,
  Kitchen, Dining room, Bathroom, Infirmary, Electric central, Underfloor, Left-side Polluted
  Water Bassin); the farm zones (`PlantFarm/…`, `CritterFarm/…`, `AquaFarm/…`) are farms.

## Server

- `App\Mcp\Servers\OniServer` (name `oni`), registered in `routes/ai.php` with
  `Mcp::local('oni', OniServer::class)`, added to `.mcp.json` next to `laravel-boost` and
  `larecipe` (`php artisan mcp:start oni`).
- Server instructions (the `$instructions` text): what the tools are, the coordinate system, the
  map glyph scheme, units, and the suggested flow (`colony_status` → `list_zones` →
  `zone_overview` → `zone_map` → `entity` / `lookup` → `zone_captures`).
- Read tools carry `#[IsReadOnly]`; `request_capture` does not.
- Colony scope: one colony is active today. Tools take an optional `colony` (guid); default is
  the colony with the latest `last_seen_at`.

## Conventions shared by every tool

- **Header line** on every response: colony name, cycle, data age (`fast 4 s, slow 22 s,
  layout 3 min`), and a `STALE` flag beyond a threshold (builder's call: e.g. no message for
  > 60 s, or `last_seen_at` older than the session).
- **Coordinates**: world `x,y` (ONI: `cell = y * gridWidth + x`, y grows upward) in every list;
  maps use local coordinates with the world origin and zone version printed in the header.
- **Units**: kg, °C (converted from K), game seconds and cycles (600 s); stated once in the
  server instructions and abbreviated in output.
- **Ids**: zones by numeric id or name (case-insensitive, unique match required); entities by
  the mod's `instanceId`; game objects by prefab / element id.
- **Limits**: list-like outputs are capped and say `truncated, N more, narrow with …`.
- **Text first**: compact text (aligned lines), not JSON. Structured content is optional, the
  builder decides per tool.
- **Localised names**: game names through `GameText` in the app locale, id kept next to it
  (`Pokeshell (Crab)`), so either can be used in follow-up calls.

## Tools

### `colony_status`
Sync health: colony name / guid, cycle, game seconds, `last_seen_at`, current session (started,
loaded at), mod link state; per zone: `layout_at`, `slow_state_at`, `fast_state_at` and their
age; counts of pending / failed `UplinkCommand`s. Colony-wide summary in a few lines:
duplicants count, food totals (kcal and days if `FoodViability` gives it), critters by species,
plants by species.

### `list_zones`
One line per active zone: id, name, world, cell count, box `WxH` and world origin, data age,
problem count (from the existing problem summary), number of captures, archived / orphaned
flags.

### `zone_overview(zone)`
The zone in one screen:
- header (above), box, version, cell count;
- **buildings** grouped by prefab: count, operational / active, status items, storage summary;
- **plants**: species, growth %, wilting, harvest ready, time to harvest;
- **critters** (`derived`: colony critters whose `cell` is in the latest zone version's cells
  and same world): species, tame, age / max, calories / max, drops;
- **elements**: per element, cell count, total mass, min / max temperature, state (gas /
  liquid / solid);
- **problems** the app already computes (plants and buildings);
- pointers: `zone_map`, `zone_captures` count, app URL of the zone page.

### `zone_map(zone, rect?, layers?)`
The spatial view, validated on a real render (zones 66 and 71) against a capture:

- **Two aligned grids**: `T` terrain (elements and tiles, always) and `O` occupancy (buildings
  over their whole footprint, critters, plants). Optional layer selection; nothing hides
  anything else.
- **Terrain glyphs**: `#` tile, `=` mesh tile (and other tile kinds get stable glyphs), one
  stable glyph per natural solid element, `.` oxygen, `,` polluted oxygen, `_` CO₂, `~` water,
  `%` polluted water, `'` vacuum; `:` the border ring (cells around the zone that are in the box
  but not in the zone); a space for box cells that are neither.
- **Occupancy glyphs**: **stable across the whole colony** per prefab (same letter for a
  RanchStation in every zone; builder chooses the mapping, e.g. derived from the building list
  order or a fixed table with fallback), lowercase for critters and plants, `.` empty.
- **Rows labelled** with their local y, a column ruler (units digit, plus a tens line beyond
  10), world origin and zone version in the header.
- **Legend** lists only glyphs present; occupancy legend gives one line per instance:
  `glyph Prefab#instanceId w(x,y) WxH state flags`.
- **Size cap** ~40×25 cells; beyond it the tool returns a reduced summary and asks for a `rect`
  (local or world coordinates).
- Footprints use the **PHP footprint rule of `ZonePresenter::occupants`** (anchor cell =
  bottom-left, fill `width × height` upward), extracted and shared. The one-cell offset seen on a
  WaterPurifier in the zone 71 spike came from the spike centring the footprint, not from the UI;
  no UI fix is needed. The TypeScript drawing (`zoneMapGeometry.ts`: mirroring, hanging
  buildings) is not ported; the map only needs cell occupancy.

### `zone_cells(zone, rect)`
Per cell in a small rect (capped, e.g. ≤ 100 cells): world x,y, element, mass, °C, tile /
building, in zone or border.

### `entity(instanceId)`
Plants and buildings only in v1: **critters have no `instanceId`** in what the mod sends today
(`CrittersSection`: `id`, `worldId`, `cell`, `tame`, `age`, `calories`, `drops`), and mod changes
belong to the critters project. Until then critters appear in `zone_overview` and the map
legend as `species @ w(x,y)` (marked `derived`), without an id; the critters project adds the
mod's `InstanceID` and `entity` then covers them. *(Builder-check call.)*

Full detail of one entity, wherever it is (no zone required): everything the mod sends for it
(plant fields, building operational / active / status items / storage, critter fields), the
zones containing it, its world position, and its game object (name, id, pointer to `lookup`).

### `lookup(query, kind?, image?)`
Search any game object by id or name — matching **French and English** names (the user plays
and browses in French; app locale is `fr`; ids and English names are what the game data uses)
*(builder-check call)* — optionally filtered by kind (element, building,
critter / morph, plant, food, item, codex). Several matches → a short list; one match → detail:
- key data per kind (element: state, thermal properties, phase changes; building: size,
  materials, power, heat, category; critter: morphs, diets, outputs; plant: inputs, outputs,
  growth; food: kcal, quality; codex: summary);
- related objects (materials, diets, crops, codex links) by id, so the next call is obvious;
- the sprite **only when `image: true`** (WebP from `game.assets_path/{category}/{id}.webp`),
  otherwise its availability.

### `zone_captures(zone, capture?)`
Without `capture`: list of the zone's captures (id, taken at, cycle, trigger, size, whether
the layout changed since — from `layout_signature`). With `capture` (id, or `latest`,
`previous`): returns that WebP as image content (`Response::fromStorage(..., disk: 'public')`).
Also returns the app URL of the zone page so the assistant can open the real rendering with
the Playwright MCP.

### `request_capture(zone)`
Sends `zone.capture` through the same path as the UI button (`SendUplinkCommand`), returns the
command id and status. The assistant follows it through `zone_captures` / `colony_status`
(command status: pending, done, failed and reason). Refuses an archived zone. "Linked" exists
only in the browser today (heartbeat in `Zones/Show.vue`); the MCP does **not** add a server
rule and does not change `ZoneCaptureController`: it sends the command anyway and, when
`Colony.last_seen_at` is older than ~30 s (the colony state repeats every ~15 s), says the game
looks offline so the command will likely stay pending. *(Builder-check call.)*

## Shared logic to extract

`ZonePresenter` mixes UI shaping (sprites, palette, DTOs) with logic the MCP needs. Extract,
without changing UI behaviour (existing tests stay green):

- plant / building problem rules (`plantProblems`, `buildingProblems`, `problemSummary`);
- growth computation;
- building footprint / occupants from layout (anchor, orientation, size);
- terrain decoding of `slow_state` (palette + per-cell arrays over the box);
- critters-in-zone matching (new; used by the MCP now and the critters project later).

Builder chooses the shape (small services under `app/Services/Zones/`, following sibling
conventions). `CritterPresenter`, `FoodViability`, `ViabilityPresenter` are called as they are
where they help `colony_status` / `lookup`.

## Testing

- Pest tests per tool using `OniServer::tool(Tool::class, [...])->assertOk()->assertSee(...)`
  with factories (zones, versions, captures, colony) — no dependency on the live database.
- Map rendering tested on a small factory zone with a known layout: glyph placement, footprint
  of a multi-cell building, border ring, size cap.
- Extracted services: the UI's existing zone tests prove nothing changed; new unit coverage
  only where logic is new (critter matching, map grid).
- Manual check: connect Claude Code to `oni`, run the tools on zones 66 (Bathroom) and 71
  (CritterFarm/Carapoke), compare `zone_map` with their latest capture.

## Docs

A LaRecipe page `development/mcp`, **in French** like the rest of the docs: what the server is, how to
connect it, the tool list, the coordinate system and glyph scheme, with `sources:` pointing at
`app/Mcp/**` and the extracted services. Follows the repo's docs workflow (affected pages,
validate, docs migration via LaRecipe MCP tools, user reviews the docs diff before commit).

## Left to the builder

- Exact glyph table and the stable prefab → letter mapping.
- Staleness thresholds.
- Text layout of each tool's output, within the conventions above.
- Whether some tools also return structured content.
- Service extraction shape and names.
