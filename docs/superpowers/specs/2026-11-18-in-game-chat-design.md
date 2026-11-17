# In-game chat with Claude — design

Date: 2026-11-18. Repos: site `~/dev/ony-planner`, mod `~/dev/uplink`.

## Intent

While playing, the player regularly wants to ask "why does this machine not run?" or "can this craft be optimised?". Today that means alt-tabbing to a Claude Code session, taking a screenshot by hand and re-explaining what is selected. Claude already has everything needed to answer once it has the context: the `oni` MCP (colony, zones, entities, captures, game data) and the ability to read code, including decompiling a mod (that is how the Algae Growing Basin question of 2026-11-17 was solved).

The in-game chat brings that conversation into the game: a shortcut opens a panel, the player types a question, the context (what is selected, the zone, a capture) leaves with it automatically, and Claude's answer comes back in the same panel.

## Goals

- A shortcut opens a chat panel in the game; the conversation keeps its history and can be continued.
- The context sent with each question is visible and editable before sending: the game's current selection, zones, ad-hoc areas, rooms, a capture.
- Answers come from Claude Code run headless (`claude -p`) on the player's machine, so they count against the player's Claude subscription, not API credits (user's decision).
- On the site, the call goes through the Laravel AI SDK (`laravel/ai`) behind a custom `claude-code` provider, so the app keeps one facade for AI calls (user's decision).

## Non-goals

- Claude changing anything: no file edits, no builds, no mod installs, no commands sent to the game. **Read-only for this version** (user's decision; write access may come later).
- A chat page on the site. The site stores and relays; the UI is in the game. (A read-only view of the conversations on the site is fine but not required.)
- API-key billing, other AI providers, a hosted service. The site stays local.
- Several conversations at the same time, or several players.

## Anti-goals

- A panel that blocks the game: typing must not trigger game hotkeys, and the game keeps running while Claude works.
- Context sent without the player seeing it. Everything that leaves with a question is shown as a chip first.
- A request that silently disappears. If Claude fails or times out, the panel says so.
- Hiding cost: the panel shows roughly how big the context is, and Claude's working time.

## Decisions

### The panel (mod)

Validated on mockups (`~/dev/uplink/.superpowers/brainstorm/…/chat-panel-v2.html`):

- **Square panel in the game's style** (dark slate body, the game's magenta header), built with PLib like the zone picker. Moved by dragging its header.
- **Header buttons:** new conversation, pin, minimise, close.
  - **Pin:** the panel stays open when Escape is pressed or another tool is used.
  - **Minimise:** the panel becomes a small badge in a corner, which shows a "reply ready" state when an answer arrives.
- **History:** the conversation's messages, the player's and Claude's, scroll in the panel. Under each question, the list of context items that left with it. Each answer shows its duration and which tools Claude used (e.g. "38 s · MCP oni ×3").
- **While Claude works:** one status line ("Claude réfléchit… lit la zone Underfloor"), updated as Claude calls tools if streaming is available (see "left to the builder").
- **Input:** one text field and a Send button; Enter sends. While the field has focus, keys go to the field, not to the game.
- **Shortcut:** opens or minimises the panel and focuses the field. Rebindable in the game's key bindings like the zone tool's (`PActionManager`). Default key: builder's call, one that does not clash with the game's defaults.
- **Language:** the panel's own strings in English like the rest of the mod (`ZoneToolStrings`); Claude answers in the language the player writes in.

### Context: chips and the « Viser » tool (mod)

Option A of the mockups (user's choice):

- **Context strip** above the history: one chip per item, removable with its ×, and an estimate of the context size.
- **Default chips** when a question is typed: the game's current selection (building, plant, critter… whatever `SelectTool` has selected), the zone containing it if any, and the latest capture of that zone.
- **« ⌖ Viser… » chip** opens a map tool built on the zone tool (`ZoneTool`, a `DragTool` in Box mode), with four modes and the game's layer filters:
  - **Objet:** click an object to add it.
  - **Boîte:** drag a box; it becomes an ad-hoc area chip ("Boîte 12×6"). It is **not** saved as a zone.
  - **Pièce:** click inside a room to add the room (same logic as the zone tool's Room mode).
  - **Zone:** pick an existing zone (same list as the zone picker).
  - **Layer filters:** the game's tool parameter menu (`ToolMenu.Instance.toolParameterMenu`, as `FilteredDragTool` uses it): All, Buildings, Liquid pipes, Gas pipes, Wires, Automation… **In this version the filter is only a hint passed to Claude** ("the player is interested in the liquid pipes here"), shown on the chip (user's decision). The mod does not sync pipes, wires or automation today (`ZoneReaders` reads `ObjectLayer.Building` and `FoundationTile` only); syncing those layers is a separate, later project.
  - Right click or Escape returns to the chat panel.
- **Capture chip:** the zone's latest capture by default; a "new capture" action requests a fresh one (the existing manual capture path). For a box, a room or a selection outside every zone, the capture is of that area's bounds (see "Context outside zones").
- **What travels from the mod with the question:** only references and geometry (selected instance ids, zone ids, box bounds, room cells, filter hints, capture reference or "take a new one") plus the question, so messages stay small.

### Context outside zones (site ↔ mod)

The site only stores data for zones (zone layouts, states and captures; the `oni` MCP's `entity` tool only finds things inside zones). An object, a box or a room outside every zone, or a selection the mod never syncs (a duplicant, a geyser), has nothing stored. **Option (c), user's decision:** the site asks the game for that data when the question arrives.

- After receiving the question, for each context item the site cannot resolve from stored zone data, the site sends a command to the mod (new command type through the existing command envelope, `uplink.command`) asking it to sample that area or entity once.
- The mod samples it **with the zone sampling code**, as a temporary zone that is read once and never saved in `ZoneStore` nor shown in the zone list: a box or a room gives the same layout and state sections as a zone; a single entity gives at least what `entity` shows for a zoned one (state, statuses, storage, problems). A selected duplicant or other entity type the zone readers do not cover is sent with what the readers can give; extending readers to new entity types is not part of this project.
- The site stores this sampled context with the question (not as a zone) and only then starts Claude, so the job waits for the game (with a timeout; on timeout Claude starts with what is available and the prompt says what is missing).
- **Captures of arbitrary bounds:** the same request can ask for a capture of the area's bounds. The capturer already renders a box; the new part is a capture not linked to a zone, stored with the question. Claude reads it like a zone capture.
- Items already inside a stored zone are resolved from stored data, without asking the game, unless the player asked for a new capture.

### Conversation and transport

- The mod sends the question as a new reliable message (e.g. `client-chat.ask`) through the existing reliable path, with a correlation id, a conversation id, the question and the context references.
- The site answers with messages on `uplink-data` addressed to the colony (same addressing as `uplink.command`): a status update while Claude works, then the answer or an error. Exact message types and whether they reuse the command envelope or are plain broadcasts: builder's call.
- **Conversations are stored on the site**, linked to the colony, so they survive a game reload and the mod can show the last conversation when the panel opens. The Laravel AI SDK's conversation tables (`agent_conversations`, `agent_conversation_messages`) are the natural store; the context references of each question are stored with it.
- **One question at a time:** while Claude works on a question, Send is disabled (or queues one question, builder's call).
- **Long work:** a question runs in a queued job, never in the request that received the message. A timeout (several minutes, value builder's call) ends with an error message in the panel.

### Claude Code provider for the Laravel AI SDK (site)

- Add `laravel/ai` (v1.4.1 at the time of writing; PHP ^8.3, Laravel ^12|^13: compatible with the app's Laravel 13 and PHP 8.5). Adding the dependency is approved by the user as part of this design.
- **Registration through `Ai::extend('claude-code', …)`.** The SDK's docs do not mention custom providers, but `AiManager` extends `Illuminate\Support\MultipleInstanceManager`, whose `extend($driver, Closure)` registers a custom creator. A `claude-code` entry in `config/ai.php` uses `'driver' => 'claude-code'`. This relies on undocumented SDK internals, so a test must fail loudly if an SDK update breaks it.
- **`ClaudeCodeProvider`** extends `Laravel\Ai\Providers\Provider` and implements `Laravel\Ai\Contracts\Providers\TextProvider`, with a gateway implementing `Laravel\Ai\Contracts\Gateway\StepTextGateway` that runs `claude -p` as a process and maps its JSON output (`--output-format json`, or `stream-json` when streaming) to the SDK's step response.
- **Tools live inside Claude Code, not in the SDK loop.** `claude -p` runs its own agent loop with its own tools (MCP `oni`, file reading). From the SDK's point of view a call is one step with a final text answer and no tool calls. SDK-side PHP tools are not passed to Claude Code in this version.
- **Read-only enforcement** through Claude Code's own permission flags (allowed tools list, permission mode), not through the prompt alone: the `oni` MCP tools, Read, Grep, Glob. `request_capture` is allowed even though it sends a command to the game: it changes nothing in the colony and the capture is invisible to the player *(my call)*. No other tool that writes or sends commands is allowed. Decompiling a mod needs a shell command (`ilspycmd`); allowing only that command (and `rg`) through a Bash allow pattern is the builder's call, verified against Claude Code's docs (`code.claude.com/docs`, `.md` versions first).
- **Working directory** of `claude -p`: the site repo, so the project's instructions and the `oni` MCP configuration apply. The process runs as the same user as the queue worker, so it uses the player's existing Claude login.
- **Continuing a conversation:** preferably by resuming the Claude Code session of the previous answer (`--resume <session id>`, stored with the conversation), so Claude keeps what it already read; falling back to replaying the transcript in the prompt when resuming fails. Builder's call on the details.
- **The capture** is passed as a file path in the prompt (the site already stores captures on disk), and Claude opens it with Read.
- **The prompt** given to Claude: the question, the resolved context (what the chips point to, in the same compact text format as the MCP tools where possible, or as pointers to the MCP calls to make), and a short system part saying it answers a player in the game, read-only, briefly, in the player's language.

## Left to the builder

- The default shortcut key, panel default position and size.
- Message type names and envelopes for ask / status / answer / error, within the existing reliable and command conventions (`docs/site/development/uplink.md`).
- How long answers reach the mod if they exceed the channel's message size (`reverb.max_message_size`): chunking, or the mod fetching the answer from the site over local HTTP.
- Whether the status line streams Claude's tool calls (`stream-json`) or only shows "working".
- How much of each context item is inlined in the prompt versus left for Claude to fetch through the MCP.
- The size estimate shown on the context strip (rough is fine).
- Timeout value; queueing a second question or disabling Send.

## Testing

- Site: the provider and gateway against a fake `claude` executable (a script returning recorded JSON), including errors, timeouts and the resume path; a test that `Ai::extend('claude-code')` still resolves the provider; message handling for ask/answer with the existing Uplink test helpers.
- Site: the context-request flow (unresolvable items trigger the sampling command, the job waits, a timeout starts Claude with what is available) with faked mod answers.
- Mod: game-free logic (context references, chip model, message building, temporary-zone sampling request handling) in `Uplink.Core` with xunit tests, like the rest of the mod; the panel, the map tool and the area capture are checked in the game.
- End to end by hand: ask the Algae Growing Basin question from the game and get a useful answer.
