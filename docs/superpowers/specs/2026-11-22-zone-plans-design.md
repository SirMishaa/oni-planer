# Zone plans (planning mode v1) — design

Date: 2026-11-22. Repo: site `~/dev/ony-planner` only (no mod change).

## Intent

When Claude gives building advice through the `oni` MCP ("put an Auto-Sweeper here to grab the eggs, move the fish release out of the pond, replace these mesh tiles with solid tiles"), the player has to translate prose and coordinates into the game. A **zone plan** is a proposed variant of a zone: the zone's real layout with Claude's changes applied, drawn with the same realistic renderer as the zone page. The player flips between **Actuel** and **Suggestion** on the zone's map and sees exactly what to build where.

Origin: the AquaFarm/PacuOne & PacuTwo session (cycle 93–94), where egg handling and culling advice needed placements that text could not show well.

Two readers:

- **The player (main).** Looks at the suggestion on the zone page. Can move or delete a suggested change.
- **Claude, through the MCP.** Writes the plan, reads it back as text grids to check every placement, and sees the player's moves and deletions.

## Goals

- One plan per zone, written by Claude through a new MCP tool, replacing the previous plan of that zone.
- A plan is a list of **changes** on the real layout, each with a short note (why):
  - **add** a building or a tile (prefab, bottom-left cell, orientation);
  - **remove** an existing building (by instance id) or tile (by cell);
  - **replace** = remove + add on the same cells (mesh tile → solid tile), shown as one change.
- Zone page, map tab: an **Actuel / Suggestion** switch, only shown when the zone has a plan. Suggestion draws the zone with the plan applied, real sprites at real positions, with the existing layers and zoom.
- Changed elements stand out in Suggestion mode (added: outline/tint; removed: ghosted or crossed on its old footprint). Hover or click on a change shows its note.
- The player can **move** a change (drag, snapped to the grid) and **delete** a change. Both persist.
- The MCP rejects an add that overlaps an existing building, another added building, or a solid cell, as the game would; the error names the conflicting cell/building so Claude can fix the placement.
- Claude can read the plan back: a plan view of `zone_map` (same T/O grids, plan applied, changes marked in the legend with their id and note) and the list of changes with their state.
- **Follow the game:** each time the zone's layout updates, the plan is reconciled:
  - an **add** whose prefab now exists in the game on the same footprint (same anchor, same orientation) is marked **applied**; it stays in the plan with an "appliqué" badge;
  - a **remove** whose target no longer exists in the game (instance gone, or tile gone from that cell) is resolved and dropped from the plan;
  - a replace is applied when its add is applied and its remove resolved.

## Non-goals (v1)

- Adding a building from the app (later).
- Recomputing crowding, feeding or any derived value for the plan.
- Several plans per zone, plan history, sharing.
- Power, plumbing, logic or conveyor layers as such: conveyor rails and wires may be added as buildings if the renderer already draws them, nothing more.
- Sending the plan to the game (no build orders through Uplink).

## Anti-goals

- A plan the player could not build: overlaps or placements on solid cells must not be stored.
- Suggestion mode that looks different from the real map (other renderer, placeholder boxes when the sprite exists).
- Losing the player's edits when Claude reads the plan; only an explicit new plan from Claude replaces it.

## Constraints

- Follows the existing zone stack: `Zone` model, `ZonePresenter`, `ZoneMapData` (`tiles`, `buildings` as `ZoneTileData` / `ZoneBuildingData`), `ZoneMap.vue` and its painters, the MCP tools in `app/Mcp/Tools` (`ZoneMapTool` grids).
- Plans are user data like zones: never truncated by `game:import`. Postgres.
- Footprint, orientation rules, ceiling hanging and sprite of a planned building come from the imported game data (`Building` model, `ZonePresenter::spriteUrl`), the same way real buildings get them.
- Octane: no request state in singletons.
- Wayfinder routes for any app endpoint (move, delete); TypeScript strict SFCs; region markers in templates/scripts.
- MCP: a new session is needed after tool changes (known).
- Tests: Pest feature tests for the MCP tool (validation, replace semantics), reconciliation, and the move/delete endpoints; unit tests for the plan-applying function on the map data.
- Docs: `product/colony` (or a new product page) and `development/mcp` updated in the same push, through the LaRecipe skills.

## Decided with the player

- Look first; move and delete in the app now, add later.
- The MCP side is a first-class reader and writer, not an afterthought.
- Built elements are marked applied; removals whose target is gone are dropped (compare presence).
- **Unsent layers** (wires, conveyor rails, pipes, logic, backwall pieces such as drywall and tempshift plates): accepted in a plan as **unchecked** changes. They are drawn (sprite when one exists, placeholder otherwise), skip placement validation, never get an applied state, and are flagged as unchecked in the UI and in the MCP read. Later the mod will send all layers (wires, pipes, logic…); the plan model must not prevent checking them then (keep the layer of each change).

## Claude's calls (check while reading)

- One active plan per zone; a new plan from Claude replaces it wholesale (including the player's edits to the old one).
- **Validation** of an add (write time and player move), from the imported game data: overlap with buildings/tiles on the same object layer, solid cells, permitted rotations, and support for floor- and ceiling-placed buildings (`build_location_rule`). Other build rules are not checked in v1.
- **Validation context:** an add is checked against the real layout with the plan's other changes applied, so "remove A, add a bigger B over it" works. A replace's add may have a different footprint from what it removes. Deleting or moving a change that another change depends on (it would leave an overlap) is refused with a message.
- **States of a change:** pending, applied (adds only), conflicted. An add the game later makes impossible (something built on its cells, terrain filled) becomes **conflicted**: kept, shown, reported to Claude. An applied add whose building disappears from the game goes back to pending (or conflicted if its spot is now taken). Removes are resolved and dropped when their target is gone.
- **Move:** only adds (including unchecked ones) move. Removes and replaces can be deleted, not moved.
- A moved change keeps its note; Claude sees it marked as moved by the player.
- Applied adds stay visible with a badge rather than disappearing, so the player sees progress.
- **In-game chat (Shift+Q) is read-only** for plans in v1: it may read a plan, never write or clear one (deny the write action in the chat's tool config). Writing from the chat, and plans on transient zones, can come later.

## Left to the builder

- Storage shape (table per plan + changes table, or a JSON column on a plan row), ids of changes.
- MCP surface: a dedicated `zone_plan` tool (write / read / clear) and a `plan: true` flag on `zone_map`, or another split, as long as Claude can write, read back as grids, and clear.
- How Suggestion mode merges into `ZoneMapData` (server-side applied layout vs client-side merge) and how live reloads keep the plan in sync.
- Visual treatment of added/removed/applied elements, within the existing map style.
- Exact matching rule edge cases (rotated buildings, tiles with connections).
