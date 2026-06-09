# Critter Ranching Data Extraction — Design

**Date:** 2026-06-10
**Status:** Approved (design); pending implementation plan

## Context

The planner aims to host a **creature page with a ranching calculator**: pick a
creature (and its variants), choose how many, and see what they **consume** and
**produce** per cycle. That calculator depends on per-critter gameplay numbers
that are **not in the database today**.

Current state (confirmed against the DB and the pipeline):

- 49 critters; each has `critter_morphs` rows for **Adult + Baby** only.
- "Variants" in the ONI sense (Glossy Drecko, Sage/Stone/Smooth Hatch, Puft
  morphs, LightBug colours, Pacu types…) are **separate `critters` rows**, each
  with their own Adult/Baby morphs.
- `critter_morph_diets` = **0 rows**, `critter_morph_outputs` = **0 rows**.
- `calories_per_cycle`, `lifespan`, `overcrowding_threshold` = **0 everywhere**.
- Only temperatures and incubation are (partially) populated.

The Laravel `CritterImporter` **already reads** `diets`, `outputs`,
`calories_per_cycle`, `lifespan`, `overcrowding_threshold` from its source JSON.
The gap is upstream: the **.NET extractor** (`dotnet-extractor/`, Mono.Cecil IL
analysis of `Assembly-CSharp.dll`) emits those fields as `0` / `[]`:

```csharp
CaloriesPerCycle: 0f, // Diet extraction deferred to follow-up
Lifespan: 0f,         // Lifespan not reliably encoded as an IL constant
Diets: [], Outputs: []
```

This is the **first of two sub-projects**. This spec covers **only data
extraction + import**. The creature page + calculator UI is a separate spec.

**Feasibility confirmed:** `dotnet` 10 is available; the game's
`Assembly-CSharp.dll` is present at
`game-data/OxygenNotIncluded_Data/Managed/Assembly-CSharp.dll` (and in Steam),
so the extractor runs in this environment.

## Goal

Populate the database with **complete, best-effort ranching data** for every
critter and variant: diet (consume → produce), outputs (eggs / droppings /
resources), calories per cycle, lifespan, and overcrowding threshold. Document
residual zeros where the IL yields nothing reliable.

Non-goals: the creature page, the calculator UI, runtime-accurate simulation,
exhaustive field-by-field validation against the wiki.

## Chosen Approach — Hybrid IL + Tuning-constant resolution

Pure inline-IL extraction (the current pattern) cannot recover values that the
game stores in static `*Tuning` fields (`ldsfld`) — which is most of the
calories and lifespans. A game-runtime/reflection harness would be accurate but
requires Unity APIs and is not viable standalone.

**Hybrid (chosen):** keep inline-IL extraction *and* resolve `*Tuning` static
fields by reading their `.cctor` constant initializers, with a small manual
`OVERRIDES` table for the few configs that resist both.

## Data Model

No change to the Critter (variant) → morphs (Adult/Baby) → diets/outputs
structure. Each variant remains its own `critters` row with its own independently
extracted diet/outputs/calories/temps — their characteristics stay distinct.

**One addition:** a nullable `variant_family` string column on `critters`,
derived from the shared `Base*Config` a config calls (the extractor already
detects this for temperatures); fallback to a name prefix. This is a **grouping
label only** — it merges no stats. The calculator computes per chosen variant.

## Components

### 1. `TuningConstantResolver` (new C# helper)

Given a `*Tuning` type, read its static constructor (`.cctor`) IL and build a map
`static field name → constant value`. Used to resolve `ldsfld` references when
extracting calories, lifespan, and pen size.

- **What it does:** turns `HatchTuning.STANDARD_CALORIES_PER_CYCLE` (an `ldsfld`)
  into its numeric value.
- **Interface:** `TryResolveField(TypeReference tuningType, string fieldName, out float value)`.
- **Depends on:** Mono.Cecil module; `IlHelper` for constant reads.

### 2. `CritterExtractor` extensions

Per config type, in addition to today's temps/incubation/name:

- **Diet:** locate `newobj Diet.Info(consumedTags, producedElement, caloriesPerKg, conversionRatio, …)`; read args via `IlHelper.ExtractCallArgs`. `consumedTags` is a **set** of tags but the schema's `consumed_element_id` is a single string, so emit **one `CritterDietDto` per consumed element in the set**, sharing the same produced element / ratio / amount. `amount_per_cycle = calories_per_cycle / caloriesPerKg`. Map each consumed tag and the produced element to their `element_id`.
- **Calories per cycle:** inline arg if present, else `TuningConstantResolver`.
- **Eggs:** read `ExtendEntityToFertileCreature` args (egg id, fertility/incubation cycles) → `output_type: egg`, `amount_per_cycle = 1 / fertilityCycles`.
- **Droppings / resources:** diet-produced element is already captured on the diet entry; non-diet emissions become `output_type: dropping`/`resource`.
- **Lifespan / overcrowding:** via `TuningConstantResolver` (with the existing inline fallbacks).
- **`variant_family`:** name of the shared `Base*Config`; fallback to name prefix.

### 3. `OVERRIDES` table

A small, documented C# dictionary mapping `critter_id` → hard-coded values for
configs whose IL resists extraction. Kept minimal.

### 4. DTO + import wiring

- `CritterDto` gains `variant_family`.
- Migration: add nullable `variant_family` to `critters`.
- `CritterImporter` sets `variant_family` on `firstOrCreate`/update of the
  Critter. Diet/output ingestion is already implemented.

## Data Flow

```
Assembly-CSharp.dll
  → CritterExtractor (IL + TuningConstantResolver + OVERRIDES)
  → critters JSON (now with calories/diets/outputs/lifespan/variant_family)
  → php artisan game:import
  → critters / critter_morphs / critter_morph_diets / critter_morph_outputs
```

## Testing & Verification

- **C# (`dotnet-extractor.Tests`):** golden-value tests against the repo DLL for
  representative critters (Hatch family, Drecko, Pacu, Puft): calories > 0, diet
  consume→produce correct (e.g. Hatch → Coal/Carbon), egg present, lifespan > 0,
  expected `variant_family`.
- **Laravel (Pest):** enrich `tests/Fixtures/GameImport/critters.json` (diets +
  outputs + `variant_family`); `CritterImporter` test asserts persistence of
  diets, outputs, and `variant_family`.
- **End-to-end:** run extractor → `game:import`; control query asserts
  `diets > 0`, `outputs > 0`, `calories > 0` for the bulk; sanity-compare 3–4
  known critters to in-game/wiki magnitudes.
- **Best-effort policy:** residual zeros are acceptable and surfaced in the
  import summary output, rather than failing the run.

## Risks & Open Questions

- **IL variance across configs:** `Diet.Info` construction and consumed-tag sets
  vary; some critters build tag sets dynamically. Mitigation: per-pattern
  handling + `OVERRIDES`, validated by the golden tests; zeros where unresolved.
- **Egg-rate semantics:** `1 / fertilityCycles` is a first-order model; the
  calculator can refine (maturity delay, lifespan) in sub-project 2. The raw
  fertility/incubation values are preserved so no data is lost.
- **Units (decided):** all rates and durations are stored **per cycle**. The
  extractor normalizes any second-based game constants to cycles (1 cycle =
  600 s) before emitting JSON, so `amount_per_cycle`, `lifespan`,
  `incubation_time`, and egg rate are all in cycle units. The fixture's
  `incubation_time: 9000` (seconds) must therefore be regenerated to cycles.

## Out of Scope (next sub-project)

Creature page (visual), variant-family grouping UI, and the ranching calculator
(population → consumption/production), which consume the data this spec produces.
