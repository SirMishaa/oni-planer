# Critters Index/Show Visual Redesign — Design

**Date:** 2026-07-05
**Status:** Approved (design); pending implementation plan

## Context

`Critters/Index` and `Critters/Show` (shipped in the
[critter page + ranch planner](2026-06-10-critter-page-ranch-planner-design.md)
sub-project) work functionally but lag well behind the visual/UX bar set by
`Buildings/Index` (category grouping, per-category color/illustration, dense
icon-first grid, sticky filter toolbar, hover/entrance animations). A
Playwright screenshot audit (`scripts/design-audit/`) confirmed the gap and
surfaced two concrete bugs along the way:

- `CritterImporter::import()` (`app/Services/GameImport/CritterImporter.php:24`)
  never reads `dlc_id` from the extractor JSON, so `critters.dlc_id` is always
  null even though the extractor (`CritterExtractor.cs`) already emits it
  correctly.
- Family/variant display names are technical identifiers (`OilFloater`,
  `HatchHard`) instead of the localized names the game provides. The
  localized name **is** resolved correctly per morph
  (`CritterMorph.name`, via `StringResolver`), but `Critter.name` is only ever
  set to the raw id on first import, and both `CritterVariantData.name` (used
  for `Show` tabs) and `CritterPresenter::familyName()` (used for the `Index`
  grid and `Show` header) read from `Critter.name`/`variant_family` instead of
  the resolved base-morph name.

Only 30 of 56 critter species have an extracted `.webp` (no habitat/tag data
exists in the extractor output at all — `min_temp`/`max_temp` per morph and
diet are the only derivable attributes). A true "aquatic"/"amphibious" game
tag would require extending the IL scan in `CritterExtractor.cs` and is
explicitly out of scope for this round.

## Goal

Bring `/critters` and `/critters/{family}` to the same visual/interaction
quality as `/buildings`: a rich, filterable, animated grid with correct
display names and a graceful fallback for missing artwork — using only data
already available (after the two bugfixes above), no extractor changes.

Non-goals: a real aquatic/habitat tag (needs extractor IL work — future
sub-project), a formal cross-page design-token system (future sub-project),
new critter artwork extraction (`scripts/kanim/` is a separate, larger
effort), restructuring the `Show` page's stats/diet/production panels (already
adequate).

## Chosen Approach

Fix the two backend bugs first (cheap, unlocks correct data), then redesign
`Critters/Index` as a flat animated grid with a sticky filter toolbar (mirroring
`Buildings/Index`'s toolbar, not its category-grouped sections — critters have
no equivalent official category), and fix `Critters/Show`'s variant tabs to use
resolved names.

Rejected: grouping the grid by temperature-tolerance or diet buckets — the
underlying data (a morph-level range/list) doesn't cleanly bucket a whole
family without misleading edge cases (e.g., a family whose baby and adult
morphs have different ranges), and it would fabricate a taxonomy the game
doesn't expose. Filter chips avoid that problem: they narrow a flat list
instead of asserting a single category per family.

## Backend Fixes

**`dlc_id` population** — `CritterImporter::import()`: read
`$raw['dlc_id'] ?? null` and include it in the `firstOrCreate` create-array;
if an existing `Critter` row's `dlc_id` differs (shouldn't normally happen
since `dlc_id` is stable per critter, but morphs of the same family are
imported across multiple loop iterations), update it — same pattern already
used for `variant_family` at line 26-28. Requires a
`php artisan game:import --skip-extractor` re-run to backfill existing rows.

**Display names** — in `CritterPresenter`:

- `familyName()`: prefer the **base morph's** resolved translation
  (`$first->morphs->firstWhere('is_base', true)?->getTranslation('name', 'en')`)
  over `variant_family` for the *display* name; keep `variant_family` for
  **grouping/slugging only** (already isolated in `groupByFamily()`, which
  slugs `variant_family ?? critter_id` — that logic is unaffected).
- `variantData()`: source `CritterVariantData::name` from the variant's base
  morph's resolved name instead of `$critter->getTranslations('name')`, so
  `Show`'s variant tabs render "Oil Floater" / "Hatch Metal"-style text
  instead of raw ids. Fall back to `critter_id` when a morph has no resolved
  name (unresolved localization key — existing best-effort pattern elsewhere
  in the presenter).

No DB schema change, no reimport needed for the name fix — it's presenter-only
and covered by existing `CritterPresenter` tests plus new assertions.

## Critter Index (`Critters/Index`) Redesign

**Layout:** flat grid (no category sections — see Rejected above), same tile
motion language as `Buildings/Index` (`codex-tile-in`-style staggered
entrance, hover `-translate-y` + shadow).

**Tile contents, per family:**

- Primary image: family's representative variant image, or the **generic
  silhouette fallback** (see below) when none exists.
- **Variant preview stack:** when `variant_count > 1` and at least one other
  variant has an image, show up to 2 small overlapping thumbnails
  (bottom-right corner, ~40% size, ring border) previewing the other variants
  — directly addresses "we don't see the other variants" from a list tile
  without needing to open the family page.
- Name (now resolved/translated), variant count, `eats_summary`.
- Small DLC badge (reusing the `info-chip--dlc` style from `Buildings/Index`)
  when `dlc_id` is set, once the importer fix lands.

**Generic silhouette fallback:** a single hand-drawn SVG (paw/claw-ish
generic-critter silhouette, in the same line-art style as `Buildings/Index`'s
`CATEGORY_SVG` icons), tinted via an OKLCH hue derived from a hash of the
family slug (deterministic per family, so the same family always gets the
same tint — same trick `Buildings/Index` uses for its `--tile-hue` per
category, applied per-family here instead).

**Filter toolbar (sticky, mirrors `Buildings/Index`'s header):**

- Text search (name + `critter_id`), same input style.
- **Temperature chips:** Cold / Temperate / Hot, computed client-side per
  family from its variants' base-morph `min_temp`/`max_temp` (thresholds:
  <15°C cold, >35°C hot, else temperate — a family matches a bucket if *any*
  of its variants' base morph overlaps that range).
- **Diet chips:** derived from each family's `eats_summary` (already computed
  server-side per first variant); chips are the distinct set of consumed-
  element display names present across all families.
- **DLC chip:** "Aquatic Pack" vs "Base game" (or similar), once `dlc_id` is
  populated. Hidden entirely if no family in the payload has a non-null
  `dlc_id` (defensive — keeps the toolbar clean if a future import regresses).
- All chip filters combine with AND; search is always applied on top.

All filter/search state is local Vue `ref`s (no server round-trip, no URL
state needed — matches the existing `Critters/Index` pattern, unlike the
ranch planner which needs shareable links).

## Critter Show (`Critters/Show`) Fix

Only the variant-tab label source changes (see Backend Fixes above — presenter
returns the right name already). No template/layout changes to `Show.vue`.

## Data Layer Changes

- `CritterFamilyListItemData`: add `dlc_id: ?string` (for the DLC chip/badge)
  and keep existing fields. No new DTO needed for the variant preview stack —
  reuse `image` resolution already available per variant in `familyData()`;
  the list item gains a `variant_images: string[]` (up to 2, excluding the
  primary) for the stack. It also gains `min_temp: float` / `max_temp: float`
  — the min of all variants' base-morph `min_temp` and the max of all
  variants' base-morph `max_temp` — so the temperature chips can bucket a
  family client-side without shipping full per-variant morph data to the
  index page. Using the combined range implements the "matches every bucket
  it overlaps" rule directly: a family whose variants span both a cold and a
  hot morph will show under both chips, which is the intended inclusive-OR
  behavior, not a bug.
- `CritterFamilyData` / `CritterVariantData`: no shape change beyond the name
  source fix (same field, corrected value).
- Regenerate TS types via `php artisan typescript:transform` after DTO edits.

## Error Handling & Edge Cases

- Family with zero images anywhere → silhouette fallback for primary tile,
  no preview stack.
- Family with images on some variants but not the primary → primary tile
  still uses silhouette (keeps "primary image" semantics simple); preview
  stack only pulls from variants that *do* have images.
- Temperature bucket edge case (family straddles two buckets across variants)
  → family matches every bucket it overlaps (inclusive OR across variants),
  rather than being hidden or arbitrarily assigned one bucket.
- No diets at all for a family → excluded from diet chip options; family
  still shows under "no filter" and under temperature/DLC chips.

## Testing & Verification

**Backend (Pest):**

- `CritterImporter` test: `dlc_id` from JSON lands on the `Critter` row.
- `CritterPresenter` tests: `familyName()` returns the resolved base-morph
  name (not `variant_family`) when available, falls back to `variant_family`
  only when no morph name resolves; `variantData()`'s `name` matches the base
  morph's resolved translation; `familyList()` includes `dlc_id` and
  `variant_images`.

**Front (manual — no existing vitest suite for these pages; add unit tests
only if filter logic grows non-trivial):**

- `/critters`: verify search, each filter chip (temperature/diet/DLC) alone
  and combined, silhouette fallback renders for imageless families, preview
  stack shows for multi-variant families with extra images.
- `/critters/{family}`: verify tabs show resolved names for a multi-variant
  family (e.g. Hatch).
- Re-run `scripts/design-audit/capture.sh` after implementation and compare
  against the pre-redesign screenshots already captured.

**Gates (must pass):** `composer test` (includes 100% type + line coverage,
lint, phpstan per project convention), `bun run typecheck`, `vendor/bin/pint
--dirty --format agent`.

## Out of Scope

Real aquatic/amphibious tag data (extractor IL extension), new critter
artwork extraction to close the 26-species image gap, a formal shared design-
token system extracted from `Buildings/Index`, any change to `Show.vue`'s
panel layout beyond the name fix, `Ranch/Index` visual work.
