# Zone map rendering (liquids, gases, terrain, temperature) — design

Date: 2026-11-12. Status: approved by the user on 2026-11-12.

## Why

The zone map (`resources/js/components/zones/ZoneMap.vue`, painted by `zoneMapPainter.ts`) is
going to be the centre of the app: it is where the user looks at a farm, a ranch, later a whole
factory, and where critters will later swim at their synced positions. Today it reads as a
debug view: every cell is a flat square of the element's **UI colour** mixed into the page
background (`terrainMix`), so water comes out grey, solids are noisy blocks of saturated colour,
nothing moves, and the temperature layer uses a zone-relative three-stop scale (blue, grey,
amber, mixed into the background) that looks inverted and has no nuance.

The user wants the map to look **close to the game**, simplified where needed, and satisfying
to watch: coloured, transparent, slightly animated liquids and gases, real terrain textures,
and the game's own temperature overlay. Seen during the S7 in-game test of the zones slice
(branch `feat/zones-slice-a`), on the user's Pacu farm.

## Goals

1. **Liquids** look like the game: the element's render colour with its own transparency,
   each cell filled by its mass, a gently moving surface, per-liquid effects, light particles
   and decorative bubbles.
2. **Gases** sit between the game's near-invisible look and readability: a tint whose strength
   follows the cell's mass, with drifting particles and wisps, so concentrated chlorine or CO₂
   is noticeable at a glance.
3. **Solid terrain** uses the game's real textures with organic, ink-outlined edges like the
   game; constructed tiles keep their own tile textures and joins.
4. **Temperature layer** reproduces the game's overlay with its four modes.
5. **Data-driven for mods**: every per-element visual property comes from the game export, so
   elements added by mods render correctly without code changes.

## Non-goals

- Critters on the map (next project; this design must leave room for sprites moving over the
  liquid layer).
- Zone borders (buildings standing on the room's walls/floor counted in the zone), building
  settings/storage, support for the user's currently skipped mods in `game:import`, the
  in-game "Uplink zone" info block. All recorded for later.
- Changing the Uplink mod's messages: it already sends element, mass and temperature per cell
  in the slow state.
- Germs in the hover card (not sent by the mod).

## Anti-goals

- Hard-coded style tables per element ("Water → blue, 0.5 alpha"). Rules read properties.
- An element silently disappearing or rendering as a hole because its texture or properties
  are missing: missing data must be caught by tests and shown with a visible fallback.
- An animation that makes a large map stutter or burns CPU when the tab is idle.
- Pixel-art squares where the game has organic edges (the square mode was considered and
  rejected).

## Decisions taken with the user

### Liquids (direction "close to the game" chosen on a mockup)

- Colour: the substance's **render colour** (`Substance.colour`, exported as `color` by
  OniExtract, e.g. water `#55bed2`), not `uiColor`. Its alpha is exported too.
- Each liquid cell is filled to `mass / maxMass` of its height; the top of a liquid body shows
  a softly animated wavy surface with a light highlight line.
- Per-liquid effects follow the substance's own properties, read from the export:
  texture family (`Substance.SubstanceTexture`: None, Magma, MoltenMetal, Polluted, Oil, Thick,
  Sap, CrystalFragments, Ovolene, Mucus, Ink, PollutedBrine), caustics (`usesCaustics`),
  glow (`glows`), opacity (`isOpaqueLiquid`), metallic (`metalic`), texture scroll speed
  (`LiquidShaderProperties`). Salt water and polluted brine therefore render differently from
  fresh water because the game says so, not because we list them.
- Particles in suspension and **decorative** bubbles, driven by the liquid's properties (for
  example no bubbles in an opaque liquid). In the game a cell holds one element only, so there
  is no dissolved gas; real bubbles are brief gas releases under liquid that the site cannot
  see at its sampling rate. Real bubbles from known gas-emitting buildings are a later idea,
  not this project.

### Gases

- Tint in the gas's render colour, strength following the cell's mass, plus drifting
  particles and wisps; balanced so concentrated gases read at a glance.

### Solid terrain

- Real per-element textures extracted from the game (confirmed present in
  `sharedassets0.assets`: `granite`, `igneous_rock`, `sand_stone`, `dirt`, `sand`, `algae`, …,
  1024×1024). Constructed tiles use the game's tile sheets (`tiles_metal`, `tiles_mesh`,
  `tiles_solid`, … with `_tops` variants).
- Organic edges with the game's ink outline, built from the game's edge masks (`mask_atlas`
  was found in the same assets), not from random noise.
- Grid hidden by default, **toggle button** to show it over the whole map.
- Hover frames **only the hovered cell** (no local grid) and shows a game-style card: element
  name as title, then state, mass, temperature (°C), and fill % for liquids. Other values we
  already have may be added.
- Textures shown as-is, never mixed into the page background.

### Temperature layer

- Four modes with a selector like the game: **Absolute** (default), **Adaptive**,
  **Heat flow**, **State change**.
- Absolute reproduces `SimDebugView` exactly: thresholds start at the user minimum and step by
  `absoluteTemperatureColorIntervals` = 273.15, 10, 10, 10, 7, 63, 1700, 10000 K (stops at
  −273.15, 0, 10, 20, 30, 37, 100, 1800, 11800 °C), linear blend between the two surrounding
  threshold colours. Colours come from the game (`temperatureThresholds[].colorName` resolved
  through `GlobalAssets.colorSet`), exported by OniExtract.
- Adaptive uses the game's relative formula (`relativeTemperatureColorIntervals` = 0.4, 0.05,
  0.05, 0.05, 0.05, 0.2, 0.2 of the span) over the zone's min..max.
- Heat flow is **approximated** from each cell's temperature change between two slow states
  (the game reads real heat flow, which the mod does not send), using the game's three
  heat-flow colours. Before a second sample exists the layer shows a "waiting for a second
  reading" state. Note: the slow state period defaults to 30 s (`ZoneSlowSeconds`, 5–600 s).
- State change shows how close each cell is to its element's transitions (low/high transition
  temperatures, already in the `elements` table): towards the cold colour near freezing or
  condensing, towards the hot colour near melting or boiling. Exact colours and ramp taken
  from the game's own state-change mode (to decompile).
- One legend per mode under the map with its thresholds in °C; the hover card always gives the
  exact value.

### Data-driven, mod-friendly (explicit user requirement)

- OniExtract iterates `ElementLoader.elements` at export time, so modded elements are
  included. It exports for every element: render colour **with alpha**, texture family,
  caustics, glow, opacity, metallic, scroll speed, max mass, and for solids the texture read
  from the loaded material (so modded textures come along).
- The site renders from those properties through rules (opaque → no transparency, no bubbles;
  caustics → caustics; glow → halo; colour + alpha → tint). It does not know "water" or
  "chlorine".
- The only hand-written mapping is **texture family → visual pattern**, because that list is a
  closed enum defined by the game and reused by mods. An unknown family falls back to a
  default look and a test flags it.
- Tests: every imported element has its render properties; every solid element has a texture
  asset; every texture family in the data has a pattern; the absolute temperature formula
  gives the expected colour at known temperatures (e.g. exactly the 20 °C stop colour at
  20 °C); the state-change position is right around a known transition.

## How (decided by Claude, open to the user's veto)

- **Rendering split**: static layers (terrain textures, edges, tiles, buildings) are painted
  once into cached canvases and redrawn only when the slow state or layout changes. Animated
  liquids and gases run on a **WebGL2** layer written directly against the browser API (no new
  dependency). If WebGL2 is unavailable the map stays fully readable without animation.
  Animation pauses when the tab is hidden or the map is off screen.
- **Data path**: extend the OniExtract fork (`~/dev/OniExtract2024`, `BElement`) with the new
  substance fields, the temperature/heat-flow/state-change colours, and solid texture names;
  extend the Python asset pipeline (`scripts/`, UnityPy) to extract element textures, tile
  sheets and edge masks as WebP; import the new fields in `ElementReader`/`ElementImporter`
  and assets in `game:import-assets`; expose them through the zone map data
  (`ZoneTerrainElementData`, palette) to the front end.
- Existing pieces to replace or extend: `terrainMix` and the mixing in `paletteStyles`,
  `TEMPERATURE_STOPS`/`temperatureColour`/`temperatureRange` in `zoneMapGeometry.ts`, the
  temperature layer in `zoneMapPainter.ts`, the layer bar in `ZoneMap.vue`.
- The user will re-run the OniExtract exports in game (Export Game Data, Export Building
  Images) when asked; `game:import` is destructive and must be confirmed with them before
  running.

### Calls made by Claude while writing this (check them)

- **Which layers get the new look**: today the Growth, Materials and Problems layers all paint
  the same element-coloured terrain and differ only by their entity overlays. The new realistic
  terrain, liquids and gases become the base of those three layers. The Temperature layer
  replaces the terrain colours with the overlay colours, as the game's overlay does, and keeps
  entities faint as today.
- **Buildings and plants** keep their current sprites and drawing in this project; only the
  layers under them change.
- **Hover card** applies on every layer; on Temperature it also names the mode's band (for
  example "Temperate").

## Left to the builder

- Exact WebGL shader design, texture atlas packing, particle counts, wave amplitudes and
  speeds, how edge masks are composed at cell junctions, caching strategy details.
- How fill level is drawn for liquid cells that are not at the top of a body (full cells vs
  partially filled cells hanging under solids).
- Hover card layout details and which extra known values to show.
- Whether the WebP assets are one atlas or one file per element.
- Visual polish pass with the Impeccable skill once the renderer works.
