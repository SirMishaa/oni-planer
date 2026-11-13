# Uplink sync orchestration (roadmap 1b) — design

Date: 2026-11-13. Status: draft for approval.

## Why

The next roadmap items (zone edges, building settings and stocks, critters, automation) all add
data the mod reads in the game and the site stores, and item 3 needs the site to send commands
to the mod. Today every new message means touching one big Reverb listener
(`IngestUplinkData`) and one big mod reader (`ZoneReaders`), and everything the mod sends is
decoded and stored **inside the Reverb process**, which also serves every open page.

1b prepares the ground so each future addition is small and isolated, and moves the stack to a
setup measured to hold far more than a large colony needs.

The user's rule for this project: **do not solve imaginary scaling problems**. Anything that only
pays off under a load nobody has measured stays out (see Non-goals).

### Measurements that drove the infrastructure choice

Throwaway bench (2026-11-13, branch `spike/ingest-bench`, not merged): 30 zones built from the
user's 4 real zones with contents ×4 (160–500 cells, 16–52 plants, 4–36 buildings, ~16 KB raw
per state, sent gzip+base64 like the mod), one socket, slow:fast = 1:6, 20 s per step.
The mod's current cadence (fast every 5 s, slow every 30 s) gives **7 msg/s for 30 zones**.

| Setup | 7/s | 150/s | 400/s | Ceiling |
|---|---|---|---|---|
| SQLite, stored in Reverb (today) | 29 ms | falls behind | 11 % lost | ~115/s |
| SQLite + database queue (1 or 4 workers) | 54–65 ms | collapses (`database is locked`) | collapses | ~18/s |
| PostgreSQL, stored in Reverb | 10 ms | 2 ms | Reverb saturated | ~335/s |
| PostgreSQL + database queue, 4 workers | 25 ms | 19 ms | 7 s behind | ~165/s |
| PostgreSQL + Redis queue, 1 worker | 32 ms | 29 ms | 2.2 s behind | ~330/s |
| **PostgreSQL + Redis queue, 4 workers** | 24 ms | 20 ms | **23 ms, no loss** | > 400/s |

(Median latency, send → state stored.) Messages ×2.5 bigger (large colony) cost at most ×2.5
per message: the chosen setup keeps a margin of more than 20× over the real need.
Also observed: a queue worker's memory grows per job and the worker stops at its 128 MB limit;
in the bench nothing restarted it.

## Goals

1. **PostgreSQL + Redis.** PostgreSQL replaces SQLite for all data. Redis holds the queue, the
   cache and cache locks. Tests run on PostgreSQL.
2. **Reverb only receives.** The Reverb listener reads the envelope, picks the message type and
   queues it. It never decodes the payload (no gzip, no JSON of the data) and never touches
   the database.
3. **One concrete class per message type**, with one handler each: session start, zone list,
   zone layout, zone slow state, zone fast state, colony state. Adding a message type means
   adding a class and its handler, nothing else.
4. **No stale overwrite.** A state processed late never replaces a newer one.
5. **Domain events on the site** (session started, zone state stored, zone archived/removed,
   colony state stored…) that storage, verdict, broadcasting and future alerts subscribe to.
6. **Pluggable zone sections in the mod**: terrain, plants and buildings become sections the
   zone sampler composes; critters and stocks will be added as new sections.
7. **One command envelope from the site to the mod** (id, acknowledgement, expiry), proven by
   one real command, ready for building settings (item 3) and automation (item 5).
8. **Performance baselines**: level 1 in the normal test suite, level 2 as a bench command run
   before a deploy or a big merge.

## Non-goals

- On-demand sync (time-limited "who is watching" requests), the watcher registry, deltas. They
  come back only if a measurement shows a need.
- Event sourcing. States are full snapshots where only the latest matters; an event store would
  need the same per-stream version as goal 4, and storing every state is ~10 GB/day per colony.
  If history is wanted later (temperature curves), a sampled history table is enough.
- New sections (critters, stocks, building settings): this project only makes them easy to add.
- Copying the current SQLite data into PostgreSQL (see Migration).
- Production deployment, authentication, private channels.
- Splitting the colony snapshot (`client-colony.state`) into sections: it already has its own
  section classes and is not on any upcoming item's path.

## Anti-goals

- The Reverb event loop doing heavy work: it freezes every socket, the pages included.
- An older state overwriting a newer one because two workers finished out of order.
- Reliable messages (session, list, layout) processed out of order and losing data.
- Performance tests that fail on a slower machine without a real regression.
- A wire format change the other side silently misreads.

## Design

### 1. Infrastructure

- `.env.example`, local setup docs and `composer run dev` move to PostgreSQL (`pgsql`, local
  socket) and Redis (`phpredis`; the extension is installed, the server is Valkey).
  `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`.
- `composer run dev` runs the workers described in §2 instead of one `queue:listen`.
  Builder's call how (several `queue:work` processes, or Horizon if the user approves the
  dependency); they must restart on code change in dev or the docs must say how to restart them.
  Workers run with a memory limit and are restarted when they stop (the bench showed growth).
- `phpunit.xml`: tests on PostgreSQL (a dedicated test database), queue `sync`, cache `array`,
  broadcast `null` as today.
- Migrations already run in foreign-key order on PostgreSQL (commit "fix: run migrations in
  foreign key order", 2026-11-13).

### 2. Ingestion path

```
mod ──ws──▶ Reverb listener ──(type, raw data)──▶ Redis queue ──▶ worker: message handler
                (no decode)                                         ├─ decode + validate
                                                                    ├─ store (conditional)
                                                                    └─ domain event ──▶ listeners
                                                                         (broadcast ZoneUpdated, ack, verdict…)
```

- **Listener** (Reverb process): cheap header check as today, `json_decode` of the envelope
  only, maps the event name to a message class, dispatches it with the raw `data` string. Must
  never throw. Unknown event names are ignored as today.
- **Two queues**:
  - `uplink-reliable`: session start, zone list, zone layout. Consumed by **exactly one
    worker**, so they are processed in the order received (Redis list, single consumer).
    This keeps "list before layout" and "session before list" without extra machinery.
  - `uplink-state`: slow, fast and colony states. Several workers (4 in dev).
- **Schema check** (`SCHEMA`, refusing newer versions) moves into the handlers, after decode.
- **Acknowledgements.** Reliable messages are acknowledged only once stored (today the listener
  answers on the sender's connection). A worker has no connection, so the ack is
  **broadcast** as `uplink.ack` on `uplink-data` (the mod is subscribed to it). The frame the
  mod receives must stay the one it parses today; the builder verifies this against
  `PusherFrames.AckId` in the mod. Unacked messages are resent by the mod after 15 s
  (`DataOutbox.ResendAfter`), so a layout for a zone the site does not know yet is simply not
  acked.

### 3. No stale overwrite

States already carry `gameSeconds` (game time, increasing during a session). Zones get
`slow_game_seconds` and `fast_game_seconds`, and a state is written with one conditional
statement:

```sql
update zones set fast_state = ?, fast_game_seconds = ?, fast_state_at = ?
where id = ? and (fast_game_seconds is null or fast_game_seconds < ?)
```

No row updated → the state is older than the stored one and is dropped (no domain event).

Loading an older save makes `gameSeconds` go back, so ordering is **per session**:

- The mod adds `sessionId` to slow and fast states (additive field, see §5).
- Zones store the session of their last state. A state from a session that is not the
  colony's current session (the one recorded by the latest session start) is dropped. A
  state from the current session replaces a state from another session whatever its
  `gameSeconds`, then the `gameSeconds` guard applies within the session.
- This covers both races: an old-session state still in the queue after the session start
  is dropped; a new-session state processed before its session start is
  dropped too, and the next one (5 s later) is stored. That short gap is accepted (my call).
- A state without `sessionId` (older mod) is treated as belonging to the current session, so
  the site can land before the mod.

**Colony state** (`client-colony.state`) gets the same guard: the mod adds `gameSeconds` and
`sessionId` to it (additive), the site stores the colony state only if it is newer within the
current session. Without these fields (older mod), last write wins, as today (my call).

`previous_slow_temperature` keeps its current meaning, but is only computed from a previous
state of the same session.

User's rule: **reloading an older save must never interfere with curves, history or
per-cycle summaries.** Anything time-based (today `previous_slow_temperature` and the plant
events/per-cycle summaries; later curves) must never compare or mix values from two sessions.

### 4. Message classes and domain events

- One class per message type (session start, zone list, zone layout, zone slow state, zone
  fast state, colony state), each with one handler. Names and namespaces are the builder's
  call, following the existing `App\Actions\Uplink` conventions.
- Existing actions (`StartUplinkSession`, `SyncZoneList`, `StoreZoneLayout`,
  `StoreZoneState`, `ColonyIngestor`) are reused or split, not rewritten for the sake of it.
- Handlers raise **domain events** describing what happened in the planner (session started,
  zone list synced, zone layout stored, zone state stored, zone archived/orphaned, colony state
  stored, command acknowledged). Broadcasting `ZoneUpdated` (with its 2 s throttle for fast
  states), acks and the food verdict move to listeners of these events. Exact list is the
  builder's call; each must have a current subscriber or an upcoming item that needs it.
- No event store, no replay.

### 5. Mod: pluggable zone sections

- The zone sampler composes **sections**. A section declares its key in the message, which
  stream it belongs to (layout, slow, fast) and reads its part for one zone (or for all zones
  at once when that is cheaper, like plants today).
- Today's readers become sections: terrain cells (element, mass, temperature), layout tiles,
  layout buildings, plants, building states. Composition, not inheritance.
- **The wire output stays compatible**: the refactor itself changes nothing (same keys, same
  shapes; the mod's tests prove it on a fixture zone, same message before and after). The only
  changes are the **additive** fields of §3 (`sessionId`, colony `gameSeconds`), which the
  site treats as optional, so either side can land first.
- Adding a section = one class + one registration line.

### 6. Command envelope (site → mod)

- Site sends `uplink.command` on `uplink-data`:
  `{schema, id, colonyId, type, issuedAt, expiresAt, payload}` (UTC timestamps, not game time).
  Every running mod receives it (public channel): a mod whose loaded colony is not `colonyId`
  ignores it silently (another game, or another save loaded); the site then marks it expired.
  The mod answers `refused` only for commands addressed to its colony that it cannot run (my
  call).
- Mod answers `client-command.ack`: `{id, status: done | refused | expired, reason?}`; it goes
  through the reliable queue like other reliable messages.
- Mod keeps a registry of command handlers by `type`; an unknown type is answered `refused`.
- Site stores commands (table: id, colony, type, payload, status, issued/expires/acked at) and
  raises a domain event on ack. A command not acked before `expiresAt` is marked expired by the
  site. Resend policy is the builder's call (none is fine for now).
- **Proof command: `zone.resync`** — asks the mod to resend a zone's layout and both states.
  The site sends it when it receives a state for a zone that has no stored layout. It is useful
  now and exercises the whole path. A way to trigger it by hand (artisan command) is enough;
  no UI.
- **Versioning is per message type** (my call): the `schema` number in each message versions
  that message only. Additive optional fields do not bump it; the new command messages start
  at 1; a breaking change to one message bumps that message only. Each handler checks its own
  message's version and refuses newer ones, as today. No global bump in this project.

### 7. Performance baselines

**Level 1 — in the normal suite** (`php artisan test`, user's decision):

- One test per message type: a realistic fixture (large-colony profile, real format, committed
  under `tests/Fixtures`) sent to its handler N times on PostgreSQL; the median must stay under
  a budget.
- Budgets are generous on purpose (about 3× the median measured on the user's machine, noted
  next to each budget) so a slower machine does not fail without a real regression. Tune N so
  the whole group adds only a few seconds to the suite.
- An architecture test fails if a message type has no performance test, so each new message
  arrives with its baseline.

**Level 2 — on demand** (before a deploy or a big merge):

- The bench spike becomes an option of the existing command, e.g.
  `php artisan uplink:bench --ingest`: seeds a bench colony (N zones, scale factor), sends
  states at a list of rates through the running Reverb, waits for the queue to drain, prints
  throughput, median/p95 latency, lost messages, and cleans the bench colony up.
- It runs against the real stack (`composer run dev`), with the bench colony isolated by its
  own colony id. Options for zones, rates, duration, scale. Results table like the one above.
- The bench spike's code (`spike/ingest-bench` worktree) is reference only; the k6 script there
  shows how to send gzip+base64 states at a fixed rate on one socket.

### 8. Migration

- Fresh PostgreSQL database: `migrate`, `game:import` for game data; the mod resends session,
  zone list and layouts on reconnect. The builder verifies that reconnect really resends
  everything (and documents the step if not). The SQLite file is left untouched as a backup.
- Docs to update (LaRecipe, same push): `development/local-setup`, `development/architecture`,
  `development/uplink`, `development/testing-and-performance`.

## Calls made while writing (to check)

- Additive `sessionId` on zone states and `sessionId` + `gameSeconds` on the colony state.
- A new-session state that beats its session start is dropped (next one stored ~5 s later).
- Colony state without the new fields: last write wins.
- Commands carry `colonyId`; a mod with another colony loaded ignores them silently.
- Schema versions are per message type, no global bump.
- Level 1 budgets ≈ 3× the measured median.

## Left to the builder

Class names and namespaces, how dev workers are launched, the exact domain event list, the
command resend policy, N and the measured budgets for level 1, the bench command's option
names, and how session reset and late states are reconciled (§3), within the constraints above.
