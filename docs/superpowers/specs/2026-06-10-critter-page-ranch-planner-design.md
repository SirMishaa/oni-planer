# Critter Page + Ranch Planner — Design

**Date:** 2026-06-10
**Status:** Approved (design); pending implementation plan

## Context

Sub-project 2, consuming the ranching data populated by the
[critter ranching data extraction](2026-06-10-critter-ranching-data-extraction-design.md)
sub-project. The database now holds, per critter/variant: diet
(consume→produce, kg/cycle, conversion ratio), egg outputs (per cycle),
calories/cycle, lifespan, overcrowding (pen size), temperatures, and a
`variant_family` grouping label.

This sub-project adds the **player-facing UI**:

1. A **critter page** (visual, per variant family) showing imagery, stats, diet,
   outputs and lifecycle for each variant.
2. A **ranch planner**: a multi-species tool where the player adds critters
   (any species/variant) with counts and chosen food, and sees the per-critter
   breakdown plus a net per-element consume/produce balance per cycle.

Naming follows existing convention: `Critter` model, `critters` table,
`critter_id`, `CritterImporter`. New controllers are `CritterController` and
`RanchController`.

## Goal

Ship a polished critter family page and a URL-shareable, client-side ranch
planner that answers: *"For N critters of these variants eating this food, what
do I consume and produce per cycle?"*

Non-goals: saved/named ranches behind auth, runtime-accurate maturation/idle
simulation, geyser/plant integration, mobile-native app.

## Chosen Approach — Inertia catalog prop + client calc

`CritterController` (index + show) and `RanchController` (index) render Inertia
pages. The planner receives one compact `catalog` prop (all variants, adult-only
ranching data) plus an `elements` map; **all math runs client-side** in a Vue
composable. Planner selection state is encoded in the URL query so links are
shareable and reload-safe. No new database tables, no extra API endpoints, no
auth.

Rejected: a separate JSON API endpoint (extra request/loading state for no
payload win at ~49 critters); server-computed totals (round-trip per stepper
edit, contradicts client-calc).

## Routing

Named routes:

- `GET /critters` → `CritterController@index` → Inertia `Critters/Index` — grid
  of variant families (reuse the Codex tile look).
- `GET /critters/{family}` → `CritterController@show` → Inertia `Critters/Show`
  — one page per family; `{family}` is a kebab slug of `variant_family`.
  Singletons (null `variant_family`) are a family of one keyed by `critter_id`.
  404 on unknown slug.
- `GET /ranch` → `RanchController@index` → Inertia `Ranch/Index` — the planner.

Nav entries for **Critters** and **Ranch** added to `AppLayout`.

Family resolution: group `critters` by `variant_family`; null family → standalone
family keyed by `critter_id`. Slug = kebab(`variant_family` ?? `critter_id`).

## Data Layer (spatie/laravel-data DTOs)

DTOs live in `App\Data`. TypeScript definitions are **auto-generated** via the
typescript-transformer (`php artisan typescript:transform`) into
`resources/js/types/App/Data/`; the front end imports those types.

- `ElementRefData { element_id, name, icon?, url? }`
  - `name` = English display name (e.g. `Carbon` → "Coal").
  - `icon` = `/assets/game/elements/{element_id}.webp` when the file exists, else null.
  - `url` = the element's **Codex** entry when one exists (preferred — the
    requested codex liaison), else `route('elements.show', element_id)`, else null.
- `CritterDietData { consumed: ElementRefData, produced?: ElementRefData, amount_per_cycle, conversion_ratio? }`
- `CritterOutputData { kind: 'egg'|'dropping'|'resource', element_id, label, icon?, url?, amount_per_cycle }`
  - Eggs: `element_id` is a prefab id (e.g. `HatchEgg`, not an element);
    `label` = egg display name, `url` → that critter's family page, `icon` →
    the critter's animation webp.
- `CritterMorphData { morph_id, is_base, name, min_temp, max_temp, calories_per_cycle, incubation_time, lifespan, overcrowding_threshold, diets: CritterDietData[], outputs: CritterOutputData[] }`
- `CritterVariantData { critter_id, name, image?, adult: CritterMorphData, baby?: CritterMorphData }`
  - `image` = `/assets/game/critters/{critter_id}.webp` when present.
- `CritterFamilyData { slug, name, description, image, variants: CritterVariantData[] }`
- `CritterFamilyListItemData { slug, name, image, variant_count, eats_summary?, makes_summary? }`
- `RanchCatalogData { families: CritterFamilyData[], elements: Record<element_id, ElementRefData> }`
  - Catalog families carry **adult-only** morphs (babies omitted — adults do the
    ranching) to slim the payload.

Per endpoint:

- `index` → `CritterFamilyListItemData[]`.
- `show` → `CritterFamilyData` (adult + baby).
- `ranch` → `RanchCatalogData`.

A shared `CritterPresenter` service builds family grouping, element refs, and egg
refs so index/show/ranch do not duplicate logic. It is the unit that owns
"how a critter/element becomes a DTO".

## Critter Family Page (`Critters/Show`)

- **Hero:** large animated webp, family name, description, **+ Add to ranch** CTA
  (links to `/ranch` with the variant pre-added via URL state).
- **Variant switcher:** tabs across the family's variants; the selected variant
  drives every panel and swaps the hero animation.
- **Selected-variant panels (adult):**
  - Stats: temperature range, lifespan (or "—"), calories/cycle, overcrowding/pen.
  - Diet: consumed-element chips (icon + link) → produced-element chip, kg/cycle,
    conversion ratio. Empty diet → "Diet data unavailable" note (best-effort gap).
  - Outputs: egg rate + any byproduct outputs.
  - Lifecycle: incubation (cycles); optional small baby block.

## Critter Index (`Critters/Index`)

Grid of family tiles reusing the `codex-thumb` visual treatment, each linking to
its family page. Tiles show the family animation, name, variant count, and short
eats/makes summaries.

## Ranch Planner (`Ranch/Index`)

Two-column layout (stacks on mobile):

- **Main column:** a searchable add-critter picker (from the catalog); each added
  row shows the animation thumbnail, a **variant dropdown**, a **food dropdown**
  (shown only when the chosen variant has more than one diet option), a count
  stepper, an inline eats/makes mini-summary, and a remove control.
- **Sticky summary (right):** the net per-element balance — for each element, its
  icon + name + signed net kg/cycle + a bar (green = net produced, red = net
  consumed), sorted by magnitude; a separate eggs section listing eggs/cycle per
  critter type. Clicking an element opens its Codex/element page via `ElementRefData.url`.

**URL state:** rows are encoded in the query string, e.g.
`?r=Hatch.Sand:6,PacuTropical:3` (critter_id, optional chosen-food element id,
count). A `useRanch` composable decodes the URL into rows, recomputes on change,
and writes back to the URL (replaceState) so the link is always shareable.

## Calculation Model

All quantities are **per cycle**, using **adult** morph rates (best-effort data).

- **One food per critter (core rule):** a critter's multiple diet entries are
  *alternative* foods, not simultaneous. Each row selects exactly one food
  (default: the first diet entry). The food dropdown appears only when >1 option.
- **Consumed** = `chosenDiet.amount_per_cycle × count` of the chosen food's
  consumed element.
- **Produced byproduct** = `chosenDiet.amount_per_cycle × chosenDiet.conversion_ratio × count`
  of the chosen food's produced element (e.g. Hatch 140 kg × 0.5 = 70 kg Coal/cycle).
- **Eggs / other outputs** = `output.amount_per_cycle × count`.
- **Net per element** = Σ produced − Σ consumed, merged across all rows by
  `element_id`.
- **Best-effort gaps:** a variant with no diet rows shows a `diet n/a` badge and
  contributes only its egg/other outputs to the net; `lifespan == 0` renders "—".

## Error Handling & Edge Cases

- Unknown family slug → 404.
- Missing critter/element imagery → graceful fallback (category icon / no icon),
  as the Codex panel already does.
- Empty planner → friendly empty state with a prompt to add a critter.
- A critter/variant referenced in the URL but absent from the catalog is skipped
  (and dropped from the rewritten URL) rather than throwing.

## Testing & Verification

**Backend (Pest, factories: Critter, CritterMorph, diets/outputs, Element, CodexEntry):**

- `CritterController@index` → families list shape, `variant_count`, slug.
- `@show` → `CritterFamilyData` with variants, adult+baby morphs, diets carrying
  resolved element refs (name/icon/url), egg outputs; 404 for unknown family.
- `RanchController` → catalog shape (adult-only) and `elements` map (name, icon
  presence, codex-preferred url).
- `CritterPresenter` units: element-ref url preference (codex → element), icon
  file existence, egg → family link.

**Front (vitest) — `useRanch` composable:**

- net balance merged across rows; one-food-per-critter (only the chosen food
  counted); produced = amount × ratio; eggs aggregation; empty-diet row
  contributes outputs only; URL encode/decode round-trip.

**Gates (must pass):**

- `bun run typecheck` (`vue-tsc --noEmit`) using generated types.
- vitest suite green.
- `composer test` — includes `pest --type-coverage --min=100`, `pest --coverage
  --exactly=100.0`, lint check, and `phpstan`. **New PHP code therefore needs full
  test + type coverage**; keep controllers thin and logic in the testable
  `CritterPresenter` / composable.
- `composer lint` (rector, pint, front formatting) clean.

**Manual verify:** load `/critters`, `/critters/hatch`, `/ranch`; confirm
animations render and the net balance updates live as rows/variants/foods/counts
change.

## Out of Scope

Saved ranches + auth, plant/geyser supply integration into the balance,
maturation/idle-time simulation, and a global "is my base self-sufficient"
cross-system solver — all candidates for later sub-projects that can reuse the
`useRanch` calc and the catalog DTOs.
