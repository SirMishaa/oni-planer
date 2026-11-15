# Critter trips: event-driven movement

Date: 2026-11-15. Follows the critters feature (`2026-11-14-critters-design.md`) and its in-game checkpoint.

## Problem

The fast stream samples every critter every 5 s. Fish dart 1–4 cells, then idle, so most snapshots show `moving=false`. A sample taken mid-trip carries only the 1 or 2 nodes left. The browser has to guess the movement between samples (`critterMotion.ts` glides at the critter's speed since 073634f), and the result is approximate. Also, every update waits for the queue job, Postgres and an Inertia partial reload before the browser sees it.

## Decision

The mod sends one **trip** message per movement, when it starts, with the whole path. It sends nothing else while the critter follows that trip. It sends again only for:
- a new trip;
- a re-plan (the destination changed: moving prey, blocked path);
- an arrival or a failure.

The browser plays the trip node by node. The 5 s fast stream stays the source of truth for state, rules and correction.

Trips travel as client events on the public `uplink-data` channel. Reverb already rebroadcasts client events to the other subscribers (`accept_client_events_from`). The browser receives them directly. No queue, no database, no reload. The site's `RouteUplinkMessage` ignores the type: no job, nothing stored.

## Game hooks (decompiled `Navigator`)

- **Start:** Harmony postfix on `Navigator.GoTo(KMonoBehaviour target, CellOffset[] offsets, NavTactic tactic)`. The method calls `AdvancePath()`, so afterwards `path.nodes` is the full path to the reserved cell (node 0 is the current cell).
- **Re-plan:** postfix on `Navigator.AdvancePath(bool)`. It rebuilds the path when `ValidatePath` fails. We send a new trip only when the path's last cell differs from the trip already sent; normal per-node advances send nothing.
- **End:** `GameHashes.DestinationReached` and `GameHashes.NavigationFailed` (the Navigator state machine triggers them on entering `arrived` / `failed`). We send a stop carrying the final cell and position.
- **Filter:** creatures only, in active zones plus their border ring, the same set the fast stream reads. Duplicants and robots are ignored. The `AdvancePath` postfix runs for every navigator on every node, so its first check must be a cheap set lookup.

## Wire: `client-zone.trips` (schema 1)

One batched message per flush (about every 250 ms, only when something happened), grouped by zone:

```
{ schema, session, gameSeconds, zones: [ { zoneId, trips: [
    { instanceId, kind: "trip"|"stop", cell, x, y, navType, speed, facing,
      path: [ { cell, navType } ]   // trip only, nodes[1..]
    } ] } ] }
```

- Within one flush, the latest entry per critter wins.
- Coordinates follow the fast stream's critter fields (same reader helpers), so the browser maps them exactly like `ZoneCritterState`.

## Browser

- It extends the relay composable (task "critters move on the raw game frame"): it subscribes to `client-zone.trips` for the current zone.
- **Trip:** it replaces the critter's track. Travel from the drawn pose to the trip's start, if needed, uses the existing travel rule, then the path plays at `speed` (non-looping steps 0.6 s).
- **Stop:** the critter travels to the final cell and rests.
- **Precedence:** a fast-stream sample or reload never rewinds a critter that has a trip newer than the sample. When the sample disagrees beyond the teleport distance, the sample wins (lost event).

## Measure first

Before tuning anything, log in game for 60 s on the user's farms:
- trips per second;
- the cost of the postfixes, as total ms per second;
- the flush size.

Budgets: postfix cost ≤ 0.5 ms/s, flush ≤ 4 KB. If over budget, throttle per critter (at most one trip per 0.5 s) before redesigning.

## Non-goals

- No persistence and no MCP exposure of trips.
- No change to the fast stream cadence; its critter section stays as it is.
- No animation export (OniExtract, later).
