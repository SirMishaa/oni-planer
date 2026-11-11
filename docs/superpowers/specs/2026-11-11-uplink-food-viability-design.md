# Uplink and food viability — design

Date: 2026-11-11. Status: draft for approval.

## Why

The site so far shows game definitions (buildings, elements, critters, codex). The user
wants it to know **their running colony** and answer questions about it without typing
anything in by hand. The first question, from their current Aquatic-pack game: *"With my
current food production, how many cycles can I survive, and when does each food source
arrive?"* They have farms, fish and salt plants, and nothing that adds it up.

This turns the site from an encyclopedia with planners into a companion that is connected to
the game. It is project 1 of three agreed with the user:

1. **This document** — the Uplink mod, the live channel, and one screen: food viability.
2. Zones — an in-game selection tool that sends a named area (a farm, a ranch) to the site;
   per-zone production with critter age; viability sums zones.
3. Living zones — the zone drawn in the site (terrain, liquids) in real time, production
   limited by the base's resources (no sand, less output), updates sent only when a zone
   changed substantially.

Projects 2 and 3 are **not** designed here, but this design must not block them (see
"Constraints from later projects").

## What a throwaway spike already proved (2026-11-10/11)

- The game's bundled `System.dll` has a working `ClientWebSocket`: a mod connected from the
  game (Windows) to Laravel Reverb (WSL, port 8080), subscribed to a public channel and sent
  the colony state as a Pusher client event every 5 s. No extra DLL in the mod.
- Reading the whole colony (6 duplicants, 199 plants, 99 critters, 4 power circuits) takes
  4–19 ms on the main thread; the message is ~137 KB.
- The game exposes, per plant: time until harvest, growth rate and what modifies it
  ("wild growth rate" is 4× slower than "domestic"), wilting, planted vs wild. Per critter:
  age, calories, wildness, fertility. Per duplicant: calories, traits, attributes, skills.
- Reverb's default 10 KB message limit must be raised (`REVERB_APP_MAX_MESSAGE_SIZE`,
  `REVERB_MAX_REQUEST_SIZE`; the dev `.env` uses 5 MB).
- Spike defects to fix in the real version: `NaN`/`Infinity` in JSON; tree branches counted
  as plants; nothing about allowed foods, auto-harvest, or the printing pod.

The spike code (`spike/colony-sync` branches in the site and in `~/dev/OniExtract2024`,
`App\Listeners\Spike\DumpColonySnapshot`) is thrown away. The Reverb installation is kept.
A real snapshot from the spike is at `storage/app/colony-spike/latest.json` and is the
reference for shapes and for test fixtures.

## Goals

- A mod, **Uplink**, that sends the loaded colony's state to the site over a WebSocket while
  the user plays, with no action beyond installing it and loading a save.
- The site stores the last state of the colony and shows a **Viability · Food** page that
  answers, in plain words and with the game's own art: how many cycles the colony holds, what
  food arrives when, what is in stock, who can eat what, and what reserves exist if the user
  decides to use them.
- The page updates while the game runs and still works when the game is closed.
- A reusable **cycle timeline** component.

## Non-goals (this project)

- Zones, the in-game selection tool or toolbar, drawing the map in the site.
- Manual entry of colony data. Everything comes from the mod.
- Sending only changes (deltas). Version 1 sends the full state.
- Spoilage, cooking chains, what duplicants actually ate last cycle, dependency on base
  resources, critter slaughter planning by age.
- Redesigning the navigation/sidebar or the existing pages. The sidebar stays simple; the
  user dislikes it and it will be redone with the site shell later.
- Accounts, multi-user, remote hosting, authentication of the channel. This runs on the
  user's machine.
- Renaming the site. Only the mod gets the new name.

## Anti-goals

- A dashboard of tiles and tables with no game art. The user rejected the first mockup for
  exactly this ("il manque énormément de visuels").
- A number the user cannot trust: every figure on the page must be traceable to what the
  game reported, and anything assumed must be said on the page.
- Counting food that will not be eaten (forbidden to the duplicants) or that needs a
  decision (killing critters) as if it were available.
- A mod that slows the game or can crash a colony.

## Decisions made by the user

- Real-time channel over **WebSocket**, not files. Laravel **Reverb** on the site.
- The mod is developed **together** with the site, so the UI is designed from real data.
- The mod is a **separate mod in its own repository**, named **Uplink** (the name must not
  contain "ONI"). It is not part of the OniExtract fork.
- Viability means **food** for now.
- Allowed foods per duplicant must be respected.
- Wild plants are a real food source. A wild plant with **auto-harvest enabled** is someone's
  job already and counts as automatic; one without is "if you go and get it".
- Critters are a possible food source but are **never** counted automatically; filtering by
  species is not acceptable (project 2's zones will carry that intent).
- No manual entry for now. When the game is closed the site shows the last state received,
  with how old it is.
- The page is **one page in blocks**, not tabs. The visual of mockup v2 is approved
  ("le visuel de cette page est super, on peut garder"), heavy on game sprites.
- The cycle timeline is a **generic, reusable component**, scrollable horizontally as far as
  data was computed, with an adjustable scale.

Everything below that is not in this list is the designer's call and can be overridden.

## Parts

### 1. Uplink mod

- New repository `~/dev/uplink` (C#, .NET Framework 4.8, Harmony, references the game's
  Managed DLLs like the OniExtract WSL build; own `build-and-install.sh` installing to
  `Documents\Klei\OxygenNotIncluded\mods\dev\Uplink`). No dependency on OniExtract. Licence:
  the user's choice at repository creation; nothing is copied from the GPL fork except ideas.
- **Lifecycle** (designer's call): connects by itself when a colony finishes loading, stops
  when the colony unloads, reconnects with backoff (1 s → 30 s) if the site is not running.
  The game never waits on the socket: one background thread owns sending, one reads, and a
  bounded outbox drops the oldest snapshot when the socket is behind.
- **Sampling**: on the Unity main thread, every 5 s of real time (also while paused),
  build the state and hand the serialized message to the socket thread. Skip the send when
  the serialized state is identical to the last one sent.
- **Settings**: `uplink.json` next to the DLL (`url`, `intervalSeconds`). Written by the
  site's install helper (below). A settings screen in the game is out of scope.
- **Feedback in game**: a small status line on the pause screen (connected / reconnecting /
  not configured) and `Player.log` lines prefixed `Uplink:`. No toolbar yet.
- **Safety**: every section of the snapshot is built in isolation; a failure in one section
  sends `{ "error": … }` for that section and logs once, never throws into the game.

### 2. Protocol

- Transport: Reverb, Pusher protocol 7. The mod subscribes to the public channel
  `uplink` and sends client events (Reverb accepts client events from channel members).
  Local machine only; channel authentication is a later concern and the event names leave
  room for a private channel per colony.
- One event in version 1: `client-colony.state`, a full snapshot:

```json
{
  "schema": 1,
  "colony": { "id": "<save colony guid>", "name": "DeepWaterGo", "cycle": 59,
              "cyclePercent": 0.08, "build": "U59-744825", "dlcs": ["DLC5_ID"] },
  "worlds": [{ "id": 0, "name": "…", "discovered": true }],
  "duplicants": [{ "id": "<stable id>", "name": "Ellie", "model": "Minion", "worldId": 0,
                   "calories": { "value": 2790000, "max": 4000000, "deltaPerSecond": -1666.67 },
                   "forbiddenFoods": ["SpicyTofu"], "traits": ["…"] }],
  "food": [{ "id": "Lettuce", "worldId": 0, "units": 66.9, "calories": 26775000 }],
  "plants": [{ "id": "SeaLettuce", "worldId": 0, "cell": 151004, "cropId": "Lettuce",
               "cropUnits": 12, "cropDurationSeconds": 7200, "planted": false,
               "autoHarvest": true, "wilting": false, "grown": false,
               "secondsUntilHarvest": 1140.0, "growthPerSecond": 0.000417 }],
  "critters": [{ "id": "Pacu", "worldId": 0, "cell": 0, "tame": true,
                 "age": { "value": 12.3, "max": 25 }, "calories": { "value": 0, "max": 0 },
                 "drops": [{ "id": "FishMeat", "units": 1 }] }],
  "printingPod": { "secondsUntilNextPrint": 1260.0 }
}
```

- Numbers that are not finite are sent as `null`. Calories are in the game's unit (cal, not
  kcal). Ids are the game's prefab ids, the same ids the site's game data uses.
- `plants` contains only entities with a crop; tree branches are folded into their trunk or
  dropped (builder's choice, stated in the mod's README).
- `schema` lets the site refuse or adapt to an older mod.

**To verify first, in the game's code (each is believed available, none is proven):** the
per-duplicant forbidden-food list (consumable permissions), the per-plant auto-harvest flag,
the printing pod timer, what a critter drops when butchered, a colony id that is stable across saves of the same colony, a stable
duplicant id. If one is not readable, the page states that the corresponding information is
unavailable; it is not replaced by a guess.

### 3. Site: receiving and storing

- Reverb stays installed (`laravel/reverb`, `@laravel/echo-vue`, `pusher-js`); it runs with
  the dev stack (`composer run dev` gains `reverb:start`).
- A listener on `Laravel\Reverb\Events\MessageReceived` (runs inside the Reverb process)
  validates `client-colony.state` against schema 1 and upserts the colony:
  `colonies` table — `guid` (unique), `name`, `cycle`, `state` (JSON, the last snapshot),
  `schema`, `last_seen_at`. One row per colony; history of past states is out of scope.
  The listener must stay cheap (it runs on Reverb's event loop): validate, write, return.
- `colonies` is user data, **not** game data: it must be excluded from the `game:import`
  truncation (`#[GameData]` models are truncated; `Colony` is not one).
- Install helper: `php artisan uplink:install` writes `uplink.json` into the mod folder with
  the Reverb URL and app key. The mod folder is a new `config/game.php` entry
  (`uplink_mod_path`, default the Windows `mods/dev/Uplink` folder).
- **Food data is missing from the site today.** The `food_properties` table is empty and
  keyed by element; nothing imports foods. The export already has them
  (`database/food.json`, 75 foods with `CaloriesPerUnit`, `Quality`, `CanRot`). This project
  adds a food import to `game:import` (a reader like the others, a `foods` game-data table
  keyed by prefab id, name through `prefab_names`, icon from `ui_image/<id>.png` via
  `game:import-assets`). The unused `food_properties` table is left alone.

### 4. Site: the calculation

A PHP service turns a stored state plus the game data already in the database (food calorie
values, names, sprites) into the page's data. One place, tested with fixtures taken from the
real snapshot. Definitions:

- **Consumption**: for each duplicant that eats (standard model), calories burned per cycle
  from the game's own rate (−1 000 kcal/cycle base; traits that change it are already in the
  reported rate). Bionic duplicants do not eat and are listed as such.
- **Stock**: `food`, summed per food id.
- **Arrivals**: for each plant that is growing (not wilting), a harvest at
  `now + secondsUntilHarvest`, then again every regrowth period derived from its current
  growth rate, each yielding `cropUnits × kcal per unit`. Kcal per unit comes from the
  imported `foods` table; a crop that is not a food (wood, fibre, pearls) is not an arrival.
  - *Automatic* arrivals: planted crops, and wild crops with auto-harvest on.
  - *On decision*: wild crops without auto-harvest.
  - Stopped plants (wilting, or no growth) produce nothing and are reported as a warning.
- **Autonomy** (the headline): simulate forward, cycle by cycle, up to a horizon of 50 cycles:
  add automatic arrivals, feed every duplicant from the foods it is allowed to eat (most
  constrained duplicant first). Autonomy is the first cycle in which someone cannot be fed.
  If nobody starves within the horizon the page says "50+". Also computed: the same
  simulation with no arrivals at all ("without any harvest, the stock lasts N cycles") and
  with on-decision wild harvests added.
- **Reserves, never in the headline**: tame critters as a count per species, and the
  calories their meat would give when the mod can report what each critter drops when
  butchered (`critters[].drops`, to verify with the other game-code checks; the site's game
  data only knows eggs). Without drops the row shows the count only. Wild
  non-auto-harvest crops as kcal per cycle; the printing pod as "next print in N cycles".
- **Stated limits, shown on the page in one line**: spoilage is ignored; raw crops are
  counted at their raw calorie value even if the user cooks them; harvests assume someone
  picks the crop when ripe.

### 5. Site: the page

Route `colony/viability` (Inertia page `Colony/Viability.vue`), under a "Ma colonie" group in
the existing navigation. Approved mockup: `.superpowers/brainstorm/60173-1794354759/content/viabilite-v2.html`
(not committed; the description below is the specification).

Blocks, top to bottom:

1. **Verdict**: the autonomy in cycles as the largest thing on the page, coloured and
   labelled by state (holds / tight under 10 cycles / critical under 3), one sentence in plain
   words, the "without any harvest" figure, and a compact projection chart of the stock over
   20 cycles with three directly labelled lines (plantations only, with wild harvested,
   no harvest).
2. **Arrivages**: the cycle timeline. One row per source (plant sprite, name, count,
   warnings such as "2 ne poussent plus"), one mark per harvest placed at its cycle, a count
   badge when several fall together, the row total on the right. Three mark styles, each with
   a label in the legend so colour is not the only cue: planted, wild auto-harvested, wild
   to fetch.
3. **Au menu**: stock per food, as a canteen menu — food icon, name, units, dotted leader,
   kcal — and a note when some duplicants may not eat it.
4. **Convives**: one row per duplicant — portrait placeholder, name, calorie reserve as a
   meter with the number, and the foods it may eat as icons (forbidden ones dimmed and
   labelled).
5. **Réserves, si tu décides**: the on-decision options with sprites and what each would
   add, with the sentence that none of it is counted in the headline.

**Cycle timeline component** (`CycleTimeline.vue`, generic): props are rows
(`id`, label slot, summary slot) and events (`rowId`, `cycle` as a decimal, `kind`, payload
for the mark slot), a start cycle and the last computed cycle. Time runs left to right;
horizontal scroll reaches the last computed cycle; the scale (pixels per cycle) is
adjustable; the label and summary columns stay fixed while scrolling; events closer than a
threshold merge into one mark with a count; a "now" line marks the current cycle. It knows
nothing about food.

**Live behaviour**: the page loads from the stored state. It also listens on the `uplink`
channel; on a `client-colony.state` event it reloads its props (debounced), so all numbers
keep coming from the PHP calculation. A status element shows "en direct" with the colony
name and cycle while events arrive, and "vu il y a 2 h, cycle 59" otherwise.

**States**: never connected (an empty state that explains installing Uplink and running
`uplink:install`, with nothing fake on screen); connected but no colony loaded; live; stale;
state from a newer schema than the site understands; a section the mod could not read.

**Look**: as mockup v2 — dark, blue-green tinted neutrals (the game is dark and saturated and
runs next to the site in a dim room), one warm "ration" yellow for calories, green / amber /
red for state always paired with a word, Barlow with Barlow Semi Condensed for numbers and
headings, game sprites at generous size, hairline rules instead of cards. Follow
`PRODUCT.md`; WCAG AA; light theme is not required for this page yet.

**Language**: French and English UI strings like the rest of the site; game names through
the existing string catalogs.

**Known gaps in art**: no duplicant portraits exist (initials until a later project renders
them from the mod); no icon for fish fillet in the current export (use a neutral food
placeholder and list the missing ids in the import report, do not borrow a critter sprite in
the real page).

## Constraints from later projects

- Plants and critters carry `worldId` and `cell` now, so a zone (a set of cells) can claim
  them later without changing the snapshot.
- The channel and event naming (`client-colony.*`) leave room for `client-zone.*` events and
  for per-colony private channels.
- The timeline component is generic so zones, critters and power can reuse it.
- The mod's sampler is structured as independent sections so a later version can send only
  the sections that changed.

## Left to the builder

- Internal structure of the mod and of the PHP service; table and class names beyond those
  given; how tree branches are folded; the debounce and merge thresholds; chart drawing
  technique; test layout. Follow the repository's rules (`CLAUDE.md`, `.ai/rules`), Wayfinder
  for routes, `lang="ts"` and region markers in Vue files.
- Tests: the calculation and the ingestion listener are covered by Pest tests using a
  trimmed copy of the real snapshot; the mod's pure code (serialization, non-finite numbers,
  change detection) is testable without the game and should be; the game-reading code is
  verified in the user's colony.

## Verification with the user

The work is done when, in the user's own colony: the mod connects without any click, the
page shows their duplicants, stock and arrivals with the game's art, the headline matches a
hand check of stock ÷ consumption for the no-harvest case, toggling auto-harvest on a wild
plant moves its marks between the two wild styles within seconds, and closing the game
leaves the page readable with its age shown.
