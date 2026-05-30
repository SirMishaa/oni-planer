# Codex Ingestion — Design

**Date:** 2026-05-28
**Status:** Approved (pending spec review)

## Context

The game data pipeline (`dotnet-extractor` → `json-cache/*.json` + `StreamingAssets` YAML → `*Importer`)
already populates structured entities: 333 buildings, 175 elements, 49 critters, 41 plants, 26 geysers,
recipes, duplicant traits. What is missing is the **in-game Codex** content, which holds rich text the
planner will reuse (building cards, production recipes, lore).

Three distinct data shapes exist in the game files, and they must not be conflated:

1. **Per-entity text** — `STRINGS.BUILDINGS.PREFABS.<ID>.DESC` / `.EFFECT`. Intrinsically **1:1** with an
   entity. The `description` column is currently empty; `.EFFECT` (mechanic text) is never imported.
2. **Codex articles** — 311 hand-authored YAML files under `StreamingAssets/codex/**` (Creatures, Plants,
   Systems, Journals, …). Long-form content as ordered, typed blocks. One article may reference **0, 1 or N**
   entities.
3. **Structured data** (recipes, production, stats) — already extracted via C#. Out of scope here.

## Goals

- Populate per-entity `description` (building, element, critter, plant) and add an `effect` text for buildings.
- Ingest the 311 Codex articles into a browsable, queryable model.
- Link any entity to the articles that mention it, via a polymorphic many-to-many relation.
- Keep retrieval trivial so presentation (cards, recipe panels) can be built later.
- Shape all imported text as **locale-keyed maps** so a future French community translation slots in with no
  rework (see "i18n shape" below).

## Non-Goals (deferred)

- Presentation / formatting of codex content (cards, panels). Store faithfully now, format later.
- Importing the **French community translation**
  ([Steam Workshop 928348692](https://steamcommunity.com/sharedfiles/filedetails/?id=928348692)). Not built
  now, but the data shape must accommodate it without migration (`{"en": …, "fr": …}` maps everywhere).
- Asset extraction of codex sprites — we store the sprite *reference*, not the image.
- **User/planner data reconciliation** across reimports — see "Forward constraint" below. Not built now.
- Refining the entity↔article matching beyond best-effort id convention.

## Architecture: ① Hybrid + trait

Decision recap: **inheritance/STI rejected** (heterogeneous tables, no clean Laravel STI, articles are not
entities). **Polymorphism used surgically** — only for the article↔entity link. Per-entity text stays in
columns. Behaviour shared via a **trait**, not a base class.

### 1. Per-entity text (columns, 1:1)

- Fill the translatable `description` on **building, element, critter, plant** from each entity's `.DESC`.
  - `elements` and `buildings` already have a `description` column.
  - `critters` and `plants` do **not** — add a translatable `description` column (migration), plus
    `description` to each model's `$translatable` array, `casts`, and `#[Fillable]`.
- Add a translatable `effect` column to `buildings` only, filled from `…PREFABS.<ID>.EFFECT`
  (`.EFFECT` is a building-specific mechanic string; the other entities have no equivalent).
- For id-cache-sourced entities (buildings), DESC/EFFECT keys are **derived from the existing
  `name_localization_id`** by swapping the `.NAME` suffix (`.NAME` → `.DESC` / `.EFFECT`); the cache does not
  provide these ids and we do not touch the C# extractor. Elements are YAML-sourced and resolve DESC via
  their existing localization mechanism in `ElementImporter`.
- Done inside the respective importers via `StringResolver::resolveToJson()` (locale-keyed map).
  Destructive reimport → no backfill.

### 2. `CodexEntry` model

```
codex_entries
  id              pk
  codex_id        string  unique      // YAML `id`: "Genetics", "GlomSpecies", "HEADQUARTERS"
  category        string              // source folder: Systems, Creatures, Plants, Journals…
  parent_codex_id string  nullable    // YAML `parentId` → self-referencing tree
  title           json                // translatable, from `title` stringKey
  icon_asset      string  nullable    // YAML `iconAssetName` / first image sprite
  content         json                // ordered containers of resolved blocks (see below)
  dlc_id          string  nullable
  timestamps
```

`content` mirrors the YAML `contentContainers`, with all `stringKey`s **resolved at import** so the
front-end only renders:

```json
[
  {
    "layout": "Vertical",
    "lock_id": null,
    "blocks": [
      { "type": "text", "style": "Title", "text": { "en": "Genetics" } },
      { "type": "divider" },
      { "type": "image", "sprite": "codex_genetics", "height": 128 }
    ]
  }
]
```

- Block types map from the YAML custom tags: `!CodexText` → `text` (keep `style`), `!CodexImage` → `image`
  (keep `sprite`, `height`), `!CodexDividerLine` → `divider`. Unknown tags are skipped (logged).
- Custom tags parsed with `Symfony\Component\Yaml\Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS)`,
  yielding `TaggedValue` objects.
- Text resolved via `StringResolver::resolveToJson()` → **locale-keyed map** (`{"en": …}`), not a plain
  string, so the FR translation merges in later without reshaping. `title` uses the same. Markup is already
  stripped by the resolver. Missing strings → block dropped.
- The self-referencing tree uses `parent_codex_id` → `codex_id` (string match), resolved after all entries
  are inserted.

### 3. Polymorphic link (article ↔ entities)

```
codexables  (pivot)
  codex_entry_id   FK → codex_entries.id  (cascade on delete)
  codexable_type   string
  codexable_id     unsignedBigInteger
  unique(codex_entry_id, codexable_type, codexable_id)
```

- `CodexEntry::buildings()` etc. via `morphedByMany`.
- Entities gain the relation through a **`HasCodexEntries` trait**:

  ```php
  trait HasCodexEntries
  {
      /** @return MorphToMany<CodexEntry, $this> */
      public function codexEntries(): MorphToMany
      {
          return $this->morphToMany(CodexEntry::class, 'codexable');
      }
  }
  ```

  Used by `Building`, `Critter`, `Plant`, `Element`, `GeyserType` (the entity types with codex categories).

- **Matching (best-effort, at import).** After entities and entries exist, for each `CodexEntry` try to
  resolve its `codex_id` against each entity's natural key (`building_id`, species id, plant id,
  `element_id`, geyser id). Match → pivot row. No match → the article stays **standalone** (pure lore like
  `Genetics`, `HEADQUARTERS`). Intentionally simple; refine later. The pivot references the entity
  auto-increment `id`, which is safe because the pivot is game↔game and rebuilt atomically each import.

### 4. `#[GameData]` attribute + destructive scope

- Introduce a `#[GameData]` PHP attribute placed on every game-sourced model.
- `GameImportCommand` derives the truncate list by **discovering models carrying `#[GameData]`** instead of
  the current hardcoded array. Truncation order respects FK dependencies (children before parents); where
  needed this is handled by toggling `PRAGMA foreign_keys` as today.
- `codex_entries` and `codexables` carry `#[GameData]` and are thus truncated like the rest.
- This formalises the **game vs user** boundary: only `#[GameData]` tables are destructive. Future
  user/planner tables simply omit the attribute and survive reimport.

### Forward constraint (documented, not built)

> Future user/planner tables MUST reference game entities by their **stable natural key**
> (`building_id`, `element_id`, `codex_id`, …), never by the auto-increment `id`. The destructive reimport
> resets auto-increment ids; only the natural key is stable across imports. A user row keyed on the natural
> key resolves to the freshly imported game row with **no relink step**. Game-internal links may use either
> key since they are rebuilt together in a single import.

The full user-data reconciliation machinery is out of scope for this spec.

### i18n shape (forward-ready, FR not built)

All imported text — entity `name`/`description`/`effect`, codex `title`, and every codex content text block
— is stored as a **locale-keyed map** (`{"en": …}`). The community French translation
([Workshop 928348692](https://steamcommunity.com/sharedfiles/filedetails/?id=928348692)) ships the *same*
string keys translated. Importing it later means: load the FR `.po` as an extra source in `StringResolver`,
have `resolveToJson()` return `{"en": …, "fr": …}`, and re-run the import — every map gains its `fr` entry,
no schema change. The only future work is the multi-source loader on `StringResolver`; the data shape is
settled now.

FR source format (verified, file staged at `game-data/mods/fr/strings.po`, gitignored, personal-use license):

- A single gettext `strings.po`, **same `msgctxt` keys** as the EN `.pot` (~20.1k of 20.3k keys, ~99%).
- The French value lives in **`msgstr`** (the EN `.pot` carries it in `msgid`); empty `msgstr` → fall back
  to `msgid`/en.
- Heavy use of **multi-line continuation** (`msgstr ""` then quoted continuation lines): ~5.8k blocks in FR,
  and ~190 in the EN `.pot` too. The current `StringResolver::parsePot` reads single-line values only and
  silently drops these — see the parser change below.

## Import flow

0. Make `StringResolver` **multi-line-aware**: parse gettext continuation blocks (a `msgid`/`msgstr` of `""`
   followed by quoted continuation lines, concatenated). This fixes ~190 long EN strings currently dropped
   (some are codex bodies) and is the shared prerequisite for the future FR `.po` loader. Single-source / `en`
   behaviour and the existing `resolveToJson` shape are unchanged.
1. New `CodexImporter(StringResolver $resolver)` in `app/Services/GameImport/`:
   - Glob `StreamingAssets/codex/**/*.yaml`.
   - Parse each with custom tags; build the resolved `content`, `title`, `category` (folder), `parent_codex_id`,
     `icon_asset`, `dlc_id`.
   - Upsert `CodexEntry` rows (truncated beforehand, so plain create).
   - Second pass: best-effort entity matching → fill `codexables`.
2. Entity importers extended to set `description` (building, element, critter, plant) and `effect`
   (building): `BuildingImporter` (DESC + EFFECT, suffix-derived keys), `ElementImporter` (DESC via YAML
   localization), `CritterImporter` / `PlantImporter` (DESC + new column). A migration adds the
   `description` column to `critters` and `plants`.
3. `GameImportCommand`:
   - Truncate scope derived from `#[GameData]`.
   - Run `CodexImporter` **after** all entity importers (entities must exist for matching).
   - Add a summary line: `Codex: N entries`.

## Retrieval (enables later formatting)

- `$building->effect`, `$building->description` — translatable accessors already provided by `HasTranslations`.
- `$building->codexEntries` — the articles mentioning the building.
- `$codexEntry->buildings` / `->critters` / … — entities referenced by an article.
- DTO extensions (`BuildingShowData → effect`, codex list) are **deferred** to the presentation work.

## Testing

- `CodexImporter` (feature): fixture YAML with `!CodexText` / `!CodexImage` / `!CodexDividerLine` →
  `CodexEntry` created, `content` blocks resolved as locale maps with correct types/styles,
  `parent_codex_id` tree wired.
- Matching: an entry whose `codex_id` equals a building → pivot row exists; a pure-lore entry → no pivot.
- Importers (feature): `description` populated for building/element/critter/plant; `effect` for buildings,
  via suffix-derived keys; values are `{"en": …}` maps.
- `StringResolver` (unit): a multi-line gettext continuation block resolves to the full concatenated value.
- `HasCodexEntries` relation round-trip: attach an entry to an entity, assert both directions.
- `#[GameData]` discovery: assert the truncate scope includes `codex_entries`/`codexables` and excludes a
  non-`#[GameData]` model (a fixture/user model).

## Open items (decided, noted for the plan)

- `description` enrichment: building, element, critter, plant (critter/plant need a new column). `effect`:
  buildings only.
- Entity types wired for `HasCodexEntries`: Building, Critter, Plant, Element, GeyserType.
- Matching is exact-id best-effort; fuzzy/alias matching deferred.
- All text stored as locale maps; FR community translation deferred (loader-only future work).
