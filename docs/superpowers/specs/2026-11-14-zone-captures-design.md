# Zone captures — design

Date: 2026-11-14. Repos: site `~/dev/ony-planner`, mod `~/dev/uplink`.

## Intent

The site redraws each zone from the data Uplink sends (terrain, liquids, buildings, plants, soon critters). Nothing shows what the game itself displays for the same zone, so checking the site's render means asking the player for a screenshot. A **zone capture** is a picture of the zone rendered by the game, stored on the site next to the zone.

Two readers:

- **Claude (main reader).** During development, Claude triggers a capture and opens the PNG to compare it with the site's map, without asking the player. This matters more as the editor grows (power, plumbing, logic and conveyor layers, then an MCP through which Claude proposes factory changes): captures are the ground truth for "does the site show what the game shows".
- **The player.** A tab or button on the zone page shows the latest capture, with the previous ones to see how the zone evolved.

## Goals

- Capture a zone on demand, from the zone page and from an artisan command that waits for the picture and prints its path.
- Capture automatically when the zone changed significantly, never for uninteresting changes.
- Keep the last 15 captures per zone, with their real date, game cycle and the layout they were taken at.
- Show the latest capture and a strip of the previous ones on the zone page.

## Non-goals

- Hosting: the site stays local (Octane in dev, assets compiled for speed). No upload over the network, no storage service. Transport through the shared disk is acceptable.
- Captures of overlays (power, rooms…), several zoom levels, or video/timelapse.
- Comparing captures automatically with the site's render (diffing pixels). Claude compares by eye.

## Anti-goals

- A capture visible to the player as a camera jump, a flicker or a UI flash.
- A capture loop that fills the disk: a big pool whose liquids move every frame must not trigger captures.
- Slowing the game noticeably: a capture is rare and may take a frame or two, never a sustained cost.

## Decisions

### What a capture shows

- The zone's box with the same margin as the site's map (the slow-state box, `CellBox.Around(zone.Cells, …, Margin)`), so the two frame the same cells.
- The normal game view: no overlay, no UI, no cursor, no hover card, no selection highlight, no zone tool overlay.
- A fixed scale in pixels per cell, the same for every capture, so two captures compare directly. The value is the builder's call (see below).
- PNG.

### When a capture is taken

- **Manual:** a "Capture" button on the zone page and `php artisan uplink:capture {zone}` both send one command to the mod through the existing site → mod command channel (`Uplink.Core.Commands`, `SendUplinkCommand`). The artisan command waits for the capture to be stored (with a timeout) and prints the stored file's absolute path so Claude can open it.
- **Automatic — the end of a building spree:** the mod already knows when a zone's layout changes (it resends the layout then, `LayoutSendTracker`, keyed by a layout signature). A capture is taken when the layout signature differs from the one of the zone's last capture **and** has not changed for a while (settle delay, about 2 minutes of game time: game seconds, so it does not run while paused), so building twenty tiles gives one capture at the end, not twenty. The signature is the one `LayoutSendTracker` already uses (finished buildings and tiles; queued orders do not count). *(Builder-check answer, my call.)*
- Liquids, gases, temperatures, plant growth and critters never trigger a capture on their own.
- **Guard:** at most one automatic capture per zone every 10 real minutes. Manual captures ignore the guard, but they count as the zone's last capture: they reset the guard and record their layout signature, so no automatic capture follows an unchanged layout. *(My call.)*
- A zone with no capture yet gets one automatically once its layout is known and settled, so every zone has at least one picture.
- **Persistence:** the per-zone capture state (layout signature of the last capture, its real and game time) lives in the save, in `ZoneStore` next to the zones, so loading a game or restarting the mod does not capture every zone again. A save from before this feature has no state: its zones count as "no capture yet". *(My call: the save is already the mod's source of truth for zones.)*
- **Zones on another world (Spaced Out):** the game only draws the world on screen. An automatic capture of a zone on another world waits until the player views that world. A manual one is refused with a reason ("zone on another world") rather than switching worlds behind the frozen view. *(My call.)*

### Transport (local only)

- The mod renders the capture and writes the PNG on disk, in its own mod folder (`<mod folder>/captures/<zoneId>/<file>.png`), then sends a small reliable message (new type, e.g. `client-zone.capture`) carrying the zone id, the file name, the session and game time, the layout signature the capture was taken at and the trigger (manual or automatic, with the command id for a manual one).
- **Failures:** the capture command is acked "done" as soon as the mod accepts it (rendering happens frames later). A failed or refused capture is reported on its own `client-zone.capture` message, with a failure reason and no file; the site records nothing but broadcasts the failure so the button and the artisan command show it. The command's 60 s expiry only covers reaching the mod, not finishing the capture. *(My call: keeps the shared command layer unchanged.)*
- The site reads the file through the mod folder path it already knows (`game.uplink_mod_path`, WSL view of the Windows folder), stores it under its own storage, records it, acks the message, and only then may the mod delete its copy. A missing file is logged and acked (the mod resends nothing for it).
- No image bytes travel through Reverb (its messages are limited to about 10 KB).

### Storage on the site

- One row per capture: zone, file path, taken at (real), game seconds / cycle, layout signature, trigger, size in pixels.
- **Which site layout a capture matches:** the layout message carries the same signature, stored with the zone's current layout. The page and the artisan command say whether a capture matches the site's current layout ("plan actuel") or an older one. No layout history is kept. *(My call: Claude compares a capture with the current site map; the signature says when that comparison is fair.)*
- Keep the 15 most recent per zone, automatic and manual together; storing the 16th deletes the oldest row and its file.
- Stored as WebP quality 90, like the game assets (the same converter): about 85 % lighter than the PNG with no visible difference, and Claude reads it as well. *(Decided with the player on 2026-11-14, replacing "keep a lossless picture".)*

### On the zone page

- A "Capture" tab (or panel) next to the map: the latest capture at full size, its date ("il y a 3 min · cycle 412") and trigger, then a strip of the previous ones; clicking one shows it.
- A "Capture" button that sends the command and shows the new capture when it arrives (live update through the existing zone broadcast).
- While the game is not linked, the button is disabled with a short reason.

### In the game

- Follow the game's own timelapse capture (`Timelapser`): freeze what the player sees with a dedicated camera (`CameraController.Instance.timelapseFreezeCamera` and its `FillRenderTargetEffect`), move and size the camera on the zone box, render into a `RenderTexture`, read it back, restore the camera position and size, unfreeze. Verify in the decompiled code which cameras must render for the normal view without UI (the game has several: base, overlay, UI…).
- A capture requested while another is running waits for it.
- Never throws into the game loop; a failed capture is logged once and, for a manual one, reported back on the command's ack so the site can show the failure.

## Left to the builder

- Pixels per cell (suggestion: 32, the game's own sprite density is higher; pick what keeps a 400-cell pool under a few MB).
- Exact settle delay and guard values within the ranges above.
- File naming, storage path on the site, the table's name and shape.
- How the artisan command waits (poll the table, or listen to the broadcast).
- Exact layout of the tab and strip, following the existing zone page style.

## Verification

- Mod: unit tests in `Uplink.Core.Tests` for the trigger logic (settle delay, guard, first capture, manual bypass) and the capture message; the rendering itself is checked in game by the player.
- Site: Pest tests for storing a capture (file copied, row created, ack), pruning to 15, a missing file, and the artisan command's output; the page in the browser.
- End to end: `php artisan uplink:capture <zone>` on the big polluted pool (zone 65) returns a path whose picture matches the game.
