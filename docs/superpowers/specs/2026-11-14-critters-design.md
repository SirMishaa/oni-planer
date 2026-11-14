# Critters and eggs in zones — design

Date: 2026-11-14. Repos: site `~/dev/ony-planner`, mod `~/dev/uplink`, OniExtract `~/dev/OniExtract2024` (images only, see Non-goals).

## Intent

Zones show terrain, buildings and plants, but no critters. The zone page is meant to be central in the app, and a ranch zone without its critters answers nothing. The player wants to **see** the critters and eggs of a zone live, as in the game, and to **plan** with them. That means:

- know whether the room or pond is too crowded and how many critters or eggs to remove, because crowding stops egg laying, and eggs count toward it;
- know what each group of critters is, what it eats and produces, and whether the zone feeds it.

This project also lays down two foundations that later work builds on:

- **game rules as dedicated, tested classes**, ported from the game's code, which the docs reference;
- **resource flows**, in a shared vocabulary. Later, a colony-wide balance of production, stock and use will aggregate them, and suggestion rules will read them. For example: "algae is low; these Pacus also accept Lettuce seeds, which you have in surplus".

## Goals

1. The mod sends every critter and every egg with a stable identity, its position and movement, its statuses and the values the game exposes, plus the game's own crowding verdict for its room or pond.
2. The zone map shows critters and eggs with the game's static images, flipped by heading, moving smoothly between messages.
3. The zone panel shows a permanent **Population** block: the game's numbers, a gauge of the margin, and how many of each type to remove.
4. The Entities tab shows critters and eggs as a **flock** (troupeau), grouped, not as one table row each.
5. Each critter group shows its diet and production per cycle, and its **need against what the zone provides** (feeder stock, edible plants).
6. Rules (`Overcrowding`, `CritterFeeding`) are pure, tested PHP classes ported from the game. The site checks its own result against the game's verdict and reports any mismatch.
7. MCP `entity` and `zone_overview` cover critters and eggs. The docs get a page per rule.

## Non-goals

- **Movement animations** (swim, walk, fly, climb per nav type) and the fix of the broken idle export (`Pacu_idle.webp` lacks the eye layer). This is a later OniExtract project. v1 uses static images.
- Colony-wide production/stock/use balance and food-change suggestions. They are later projects that read this project's flows.
- Feeder filters and delivery points (roadmap step 3). v1 assumes a feeder's stock is meant for the critters around it.
- Delivery rate (kg brought per cycle). v1 shows stock and autonomy. Deriving a rate from successive messages comes later, and only if measurement shows it is needed.
- Presence-based acceleration (faster messages for the zone being viewed). Send always, measure first.
- Server-side kill or diet verdicts. They come later, as more rule classes.
- Hypothesis planning ("and if I add 4 Hatches") and crowding computed on a zone that is not exactly a game room. The `Overcrowding` class supports both, but exposing them is a later project, alongside zones linked to game rooms (user decision, 2026-11-14).

## Anti-goals

- A long per-entity table. With 40+ critters it is unreadable. This was rejected in the mockups.
- One group-wide alert painting every vignette red, which drowns individual alerts.
- Presenting a planner-computed number as the game's. Anything the planner derives is labelled "derived", as in the MCP.
- Hand-written status names ("À l'étroit", "Surpeuplé"). Status names come from the game's strings.
- Rule logic scattered through presenters, controllers or Vue components.
- Critters moving in jumps every message, or drifting away from where the game has them.

## What the game does (read in Assembly-CSharp)

The builder relies on these facts. They come from the decompiled game code.

### Crowding: `OvercrowdingMonitor`

- Every critter declares `spaceRequiredPerCreature` (personal space, in cells). The importer already stores it as `critter_morphs.overcrowding_threshold`.
- The region is the critter's **cavity** (`RoomProber.GetCavityForCell`, `CavityInfo.NumCells`) or, for fish, its **pond**. `FishOvercrowingManager` rebuilds ponds every 1000 ms by flood-filling navigable liquid from each aquatic entity, and gives each pond a `cellCount`, its fishes and its eggs. A fish is a critter with `FishOvercrowdingMonitor.Def`. Land critters ignore fish (`Analyze(..., excludeFish: true)`).
- `Occupancy.Analyze` gives `OccupiedCellCount` (sum of each critter's personal space), `HatchedEggOccupiedCellCount` (sum over eggs of the space of the critter each egg will hatch into) and `CritterCounts` per prefab tag.
- Three states. The game names are the `CREATURES.MODIFIERS.OVERCROWDED.*` strings.
  - **Overcrowded** (`CROWDED`): `CellCount - OccupiedCellCount < 0`, critters only. Happiness modifier = −(over-occupied critter count).
  - **Cramped** (`CRAMPED`, `IsFutureOvercrowded`): `CellCount - OccupiedCellCount - HatchedEggOccupiedCellCount < 0` and there is at least one egg. It removes the `Expecting` tag and applies fertility ×−1, so laying stops. **This is where eggs weigh.**
  - **Confined** (`CONFINED`): `CellCount < spaceRequiredPerCreature`. Happiness −10 and fertility ×−1. Burrowed and digger critters are immune.
  - When Confined is active, it suppresses the Overcrowded and Cramped effects.
- **How many to remove.** The game computes it only for Overcrowded: `ceil((Occupied - CellCount) / personalSpace)` per type, capped at that type's count, plus "can fix" = whether removing that type alone is enough (`BuildCritterOccupancies`). These helpers are **private** and only feed the tooltip text. For Cramped, the game computes no removal count. The planner derives it the same way from the future overflow (`Occupied + EggOccupied - CellCount`), and labels it derived.
- Eggs carry `OvercrowdingMonitor.Def` with space 0. They are still placed in the cavity's `eggs` list (`UpdateRegion` runs before the zero-space early return), and aquatic eggs go into the pond's eggs.
- **Unknown, to verify in game:** whether an egg inside an incubator or a storage still counts in the cavity it sits in. This decides whether moving eggs to an incubator relieves Cramped.

### Movement: `Navigator`, `TransitionDriver`

- `Navigator.path.nodes` (cell + `NavType` per node), `CurrentNavType`, `facing`, `defaultSpeed`, `IsMoving()`.
- **Looping** transitions (walk, swim) move at `transition.speed` (initialised from `defaultSpeed`) cells per second, so they are speed-driven. **Non-looping** transitions (jumps, climbs, drops) end when their animation ends, so they are animation-driven and have no speed in the data. Override layers can alter transitions.
- No path means idle, falling, carried (`Pickupable` held) or stored.

### Eggs: `IncubationMonitor`, `Components.IncubationMonitors`

- Enumerate eggs through `Components.IncubationMonitors`, because eggs have no `Brain` and `CrittersSection` would miss them.
- Exposed: `incubation` (amount, %), `viability`, `wildness`, `incubator` (the `EggIncubator` when stored in one), `def.spawnedCreature`, `def.baseIncubationRate`. `GameTags.Stored` tells whether the egg is in a storage.

### Fertility, happiness, stomach

- `FertilityMonitor`: `fertility` amount (%), `breedingChances` (egg tag + weight, the next-egg odds), `IsReadyToLayEgg`.
- `HappinessMonitor`: thresholds happy ≥ 1, glum ≤ −1, miserable ≤ −10, with wild and tame variants.
- `CreatureCalorieMonitor.Stomach`: `GetCalorieEntries()` (calories eaten per food tag since the last poop), `GetNextPoopEntry()`, `IsHungry()`, `diet`.

### Statuses

They come from `Effects` (each with `is_bad`) and from the `KSelectable` status items. The mod sends their **ids**, and the site names them from the imported game strings.

## Decisions

### Data from the mod

- **Critters go in a new zone Fast section** (`ZoneSections.Fast`, "one section and one line"), cut per zone by cell, like plants. They ride the existing zone fast message (every 5 s, confirmed by the user) and its ordering by session and `gameSeconds`.
- **Eggs go in a new zone Slow section** (`ZoneSections.Slow`, every 30 s). The user's call: eggs barely change. The crowding regions stay in the Fast section, because the game's egg-occupied count is part of them, so the Population block stays current even though the egg list itself lags. The colony snapshot keeps its `critters` list for the viability page until that page moves to the new data. Whether to drop it then is the builder's call.
- **The ring of border cells counts**, as for buildings. A critter in the zone's border ring belongs to the zone.
- Per critter: `instanceId` (KPrefabID.InstanceID), prefab id, cell, precise position, `facing`, `navType`, remaining path (cells + nav types), `defaultSpeed`, `isMoving`, tame/wild, baby or adult, age (value/max), calories (value/max), fertility (value/max) and breeding chances, happiness (value + state), stomach entries and next poop, hungry, status item ids and effect ids (with `is_bad`), and the id of the crowding region it belongs to.
- Per egg: `instanceId`, prefab id, cell, incubation %, viability, wild/tame, `spawnedCreature`, location (`floor` / `incubator` / `storage`, with the building's id when stored), and region id.
- **Crowding regions** (per zone message, one entry per cavity or pond touching the zone): a region id (cavity handle or pond index, stable for the session), kind (`cavity` / `pond`), the cells it covers inside the zone, and the public values: `CellCount`, `OccupiedCellCount`, `HatchedEggOccupiedCellCount`, `CritterCounts`, and for each critter type present the three booleans and the Overcrowded modifier. These values are what the consistency check compares.
- The new sections are additive optional fields, so no schema bump. The orchestration spec versions per message type, and additive optional fields do not bump it.
- A pond has no stable handle (ponds are rebuilt every second). Its region id is `pond:` + the smallest instance id among its fishes and eggs. A cavity's region id is `cavity:` + its handle.

### Rules: classes in `app/Services/GameRules/`

- One class per game mechanic: `Critters/Overcrowding`, `Critters/CritterFeeding`. Each is `final` and stateless, with no database and no Eloquent. Typed input in, typed output out (Spatie Data, as in `app/Data`). Methods mirror the game's names. The PHPDoc cites the game source (`OvercrowdingMonitor.RegionAnalysis.ComputeOverOccupiedCritterCount`).
- Per-critter values (personal space, diet, outputs) come from `game:import`. Only engine constants live in the class (fertility ×−1, Confined −10).
- `Overcrowding` input: cell count, critters (type, personal space, fish or not, digger or burrowed), eggs (type of the critter they will hatch into). Output: the three states, occupied, egg-occupied, free cells, and per type the over-occupancy, "can fix", and whether the number is the game's (Overcrowded) or derived (Cramped).
- **Consistency check.** On ingest, the site runs `Overcrowding` on the region's raw inputs and compares the result with the game's public values. A mismatch is logged and shown in the Uplink inspector. A game update that changes a rule then shows up immediately instead of producing silently wrong numbers.
- **Usage.** Live, the zone shows the game's verdict for each region. For planning, the same class runs on a zone or on a hypothesis ("and if I add 4 Hatches"), and the result is labelled derived.

### Resource flows: the foundation for later balance and suggestions

- `CritterFeeding` returns **flows**, not critter-specific totals. A flow is: resource (game tag), rate per cycle, direction (consumed / produced), source (critter group, plant, building), and zone.
- A consumed flow for a critter with a varied diet carries **the list of accepted foods**, each with its yield (calories per kg, and the output it gives). The data is already in `CritterDietData`.
- A building's stock is an **inventory** (resource, quantity, place), in the same vocabulary. The mod already sends building storage contents (`ZoneReaders.StoredItems`).
- Need against supply, per group: the group's consumption per cycle compared with feeder stock in the zone, plus edible plants in the zone for grazers. Shown as a deficit ("missing 0.8 kg algae/cycle") or an autonomy ("stock for 1.6 cycles").
- The flow and inventory types live where the later colony balance can import them without depending on critters.

### Zone page: map

- New "Critters" layer. Each critter is drawn with its static game image (`critters/{id}.webp`), flipped by `facing`. Eggs use their game image.
- **Movement replay.** For looping transitions, the critter moves along its remaining path at `defaultSpeed` cells/s. For non-looping ones, it takes a duration estimate (builder's call). It snaps back to the game position on each message, easing the correction rather than teleporting unless the gap is large. Measure on the big basin ("Left-side Polluted Water Bassin", zone 65) before tuning anything.
- Selection uses the existing "sticker" style. The selected critter shows its remaining path as dots.
- Panel:
  - the zone verdict;
  - the **Population** block, which is permanent (option B of the mockups): for each crowding region, a gauge of cells occupied by critters plus eggs "at hatching" against the game's limit, then per type the count, personal space and how many to remove, labelled game or derived. One block per region when the zone covers several;
  - the zone's content, with critters and eggs counted;
  - the selected critter's sheet: statuses, happiness, fertility (marked frozen when laying is stopped), stomach, age, next-egg odds.

### Zone page: Entities tab, as a flock

- New filters "Critters" and "Œufs".
- One band per group: variant × adults / babies / wild. The band header has the image, the count and the group's alerts with counts.
- One vignette per critter: image, flipped, plus a mini gauge. The player picks the gauge's metric (fertility, growth, stomach…), and it also sorts the vignettes.
- A group-wide alert stays on the band. Only individual alerts outline a vignette in red with "!".
- Hover or click opens the sheet. The selection is shared with the map.
- Under the vignettes: the group facts (happiness spread, median stomach, age range), and the group's diet, production and need against supply.
- Eggs: grouped by type and location, placed on a timeline up to hatching (now → +N cycles), with a note when viability is dropping.

### Images

- `game:import` copies the egg images from the OniExtract `ui_image` export. Eggs are identified by **prefab** (Egg tag, `spawnedCreature`), not by a filename grep: a grep also matches EggCracker, EggIncubator, CookedEgg and EggShell. An image coverage test fails if any egg prefab lacks an image.
- `Butterfly.webp` is empty (96 bytes) and gets regenerated. The coverage test checks critter images too, and rejects empty files.

### MCP and docs

- MCP `entity` gets critter and egg details. `zone_overview` gets the population per region and the flock summary. `zone_map` already prints critters in lowercase, so it now uses the new section.
- Docs: a "Règles" section in the product docs, one page per rule class. Each page lists the class in `sources:`, so `larecipe:affected` flags it when the rule changes. The colony page and the MCP page get updated for the new data.

## Slices (in order, each testable on its own)

1. **Mod data and ingest.** Critter, egg and region sections, schema bump, storage, and MCP `entity`. Verify on the big basin and a ranch, using `request_capture` to compare.
2. **Overcrowding.** The rule class, its tests, the consistency check, and the Population block.
3. **Flock.** The Entities tab (troupeau), with the image import and the coverage test.
4. **Map layer.** Static images and selection first, then the movement replay last, measured on the big basin.
5. **Feeding flows.** `CritterFeeding`, flows and inventory, and need against supply in the flock.

Docs ship with each slice.

## Left to the builder

- Exact class, Data and column names; whether critters get their own table or stay in the zone's fast state JSON.
- The duration estimate for non-looping transitions, and the easing and snap thresholds of the replay.
- The metric list for the vignette gauge, beyond fertility, growth and stomach.
- Whether the colony snapshot keeps `critters` after the viability page moves.
- How the site resolves status and effect ids to names, if the imported strings need a new catalogue.

## Mockups

`.superpowers/brainstorm/49639-1794689505/content/`: `critters-map-v4.html` (map, panel, option B chosen for Population) and `critters-entities-v2.html` (option B, the flock, chosen).
