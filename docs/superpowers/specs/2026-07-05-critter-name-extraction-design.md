# Critter Localized Name Extraction Fix — Design

**Date:** 2026-07-05
**Status:** Approved (design); pending implementation plan

## Context

The [Critters Index/Show visual redesign](2026-07-05-critters-redesign-design.md)
assumed `CritterMorph.name` was already correctly localized and only the
*presenter* read from the wrong field. A post-implementation visual audit
disproved that assumption: **every** critter/morph name in the database is
still the raw technical id (`OilFloater`, `HatchHard`, `GoldBelly`...), because
`dotnet-extractor/Extractors/CritterExtractor.cs`'s `FindNameKey()` never
successfully resolves a localization key — `97/97` entries in
`json-cache/critters.json` have `name_localization_id: null`.

## Root Cause (confirmed via direct IL inspection)

`FindNameKey()` scans a critter's `IEntityConfig` type for an `ldstr`
instruction whose *entire value* starts with `"STRINGS.CREATURES"` — e.g. it
looks for the literal string `"STRINGS.CREATURES.SPECIES.OILFLOATER.NAME"`
somewhere in the IL. That literal never exists as a single string anywhere in
`Assembly-CSharp.dll` for creatures (confirmed: only 27 `STRINGS.CREATURES`-
prefixed literals exist in the whole assembly, and none match a real species
id like `OILFLOATER` or `PARROTFISH`).

The real mechanism, confirmed by direct Mono.Cecil inspection of
`Assembly-CSharp.dll`:

1. The game defines the display name as a **static field** on a nested class
   hierarchy, e.g. `STRINGS.CREATURES.SPECIES.OILFLOATER.NAME` (C#:
   `class STRINGS { class CREATURES { class SPECIES { class OILFLOATER {
   public static LocString NAME; } } } }`), assigned in that nested class's
   static constructor via
   `NAME = FormatAsLink("Slickster", "OILFLOATER")` — the *English display
   name* (`"Slickster"`) and the *species id* (`"OILFLOATER"`) are both
   plain `ldstr` literals sitting right next to each other in that `.cctor`,
   but that's a different type than the critter's `IEntityConfig` type, so
   `FindNameKey()` (which only scans the config type) never sees it.
2. Each critter's own `IEntityConfig` type (e.g. `OilFloaterConfig`) *reads*
   that field via a plain `ldsfld` instruction inside its `CreatePrefab`
   method — confirmed for every case tested, including the tricky
   non-obvious ones:
   - `OilFloaterConfig` → `ldsfld STRINGS.CREATURES/SPECIES/OILFLOATER::NAME`
   - `GoldBellyConfig` → `ldsfld STRINGS.CREATURES/SPECIES/ICEBELLY/VARIANT_GOLD::NAME`
     (the variant's true species id, `ICEBELLY`, and suffix, `VARIANT_GOLD`,
     are **not** derivable from the critter_id `GoldBelly` or the extractor's
     own `variant_family` value `Belly` by any string transform — this
     `ldsfld` is the only reliable source)
   - `HatchHardConfig` → `ldsfld STRINGS.CREATURES/SPECIES/HATCH/VARIANT_HARD::NAME`
   - `BabyIceBellyConfig` → `ldsfld STRINGS.CREATURES/SPECIES/ICEBELLY/BABY::NAME`
3. That field's declaring type's full name, with `/` replaced by `.` and
   prefixed appropriately, is **exactly** the dotted key format
   `StringResolver` already expects and already successfully resolves against
   `strings_template.pot` / `strings.po` — confirmed present:
   `STRINGS.CREATURES.SPECIES.OILFLOATER.NAME` → `"<link=\"OILFLOATER\">Slickster</link>"`,
   `STRINGS.CREATURES.SPECIES.HATCH.VARIANT_HARD.NAME` → `"Stone Hatch"`.

**Conclusion:** the extractor's *IL scanning target* is wrong (config type's
`ldstr` literals instead of the same type's `ldsfld` reads), not the
resolution pipeline. `StringResolver`, `CritterImporter`, and everything in
`App\Services\Critters\CritterPresenter` (including the Task 2 fix from the
redesign plan) are correct and unaffected — they will start producing real
names the moment the extractor emits a correct `name_localization_id`.

## Goal

`FindNameKey()` returns a correct, dot-separated `STRINGS.CREATURES...NAME`
key for every critter/morph whose config type reads its name from a static
`STRINGS` field this way (expected: the large majority — every case checked
during investigation matched), instead of always returning `null`.

Non-goals: changing `StringResolver`, `CritterImporter`, or any PHP/Vue code
(none of it needs to change); guaranteeing 100% coverage — a critter whose
config doesn't follow this pattern falls back to the existing `critter_id`
behavior, unchanged from today.

## Chosen Approach

Replace `FindNameKey(TypeDefinition type)`'s body: instead of scanning for an
`ldstr` literal starting with `"STRINGS.CREATURES"`, scan the same type's
method bodies for an `ldsfld` instruction whose operand is a `FieldReference`
named `"NAME"` whose `DeclaringType.FullName` starts with `"STRINGS"`. On the
first match, return `fieldRef.DeclaringType.FullName.Replace('/', '.')` (Mono
Cecil represents nested types with `/` in `FullName`; the dotted form is what
`StringResolver` consumes). Return `null` if no such instruction is found —
preserving today's fallback-to-`critter_id` behavior in `CritterImporter`
exactly as-is.

Rejected: keeping the `ldstr`-scanning approach and trying to reconstruct the
key by string manipulation of `critter_id`/`variant_family` — proven
unreliable by the `GoldBelly` → `ICEBELLY`/`VARIANT_GOLD` case, where no
string transform of data already in our own extractor output derives the
correct species id or variant suffix. Rejected: hardcoding the resolved
English name directly in the extractor (bypassing `StringResolver` entirely)
— would work but throws away multi-locale support that `StringResolver`
already provides for other entity types, for no benefit.

## Implementation Detail

`FindNameKey()` currently only looks at `ldstr`. The new version:

```csharp
private static string? FindNameKey(TypeDefinition type)
{
    if (!type.HasMethods) return null;
    foreach (var method in type.Methods.Where(m => m.HasBody))
    {
        foreach (var instr in method.Body.Instructions)
        {
            if (instr.OpCode == OpCodes.Ldsfld &&
                instr.Operand is FieldReference fr &&
                fr.Name == "NAME" &&
                fr.DeclaringType.FullName.StartsWith("STRINGS"))
                return fr.DeclaringType.FullName.Replace('/', '.') + "." + fr.Name;
        }
    }
    return null;
}
```

This is called identically for both adult and baby configs (the existing
call sites in `ExtractCritter()` don't change), since baby config types
follow the same `ldsfld .../BABY::NAME` pattern (confirmed for
`BabyIceBellyConfig`, `BabyHatchHardConfig`).

## Error Handling & Edge Cases

- No matching `ldsfld` found (e.g. a critter whose name is set some other
  way) → `null`, identical to today's universal-null state for that one
  critter — no regression, just no improvement for that specific case.
- A `DeclaringType.FullName` that doesn't start with `"STRINGS"` (e.g. some
  unrelated field named `NAME` on an unrelated type) is explicitly excluded
  by the `StartsWith("STRINGS")` guard, avoiding false positives.
- `DESC` resolution in `CritterImporter::resolveSibling()` already derives
  the description key by swapping the `.NAME` suffix for `.DESC` on whatever
  key `name_localization_id` holds — this continues to work unchanged once
  `name_localization_id` is correct, no changes needed there.

## Testing & Verification

**dotnet-extractor (xUnit, existing test project `dotnet-extractor.Tests`):**
Add a test using a small fixture module or the real
`Assembly-CSharp.dll` (existing tests in this project already load the real
DLL per project convention — check `dotnet-extractor.Tests` for the pattern)
asserting `FindNameKey` on `OilFloaterConfig` returns
`"STRINGS.CREATURES.SPECIES.OILFLOATER.NAME"`, and on `GoldBellyConfig`
returns `"STRINGS.CREATURES.SPECIES.ICEBELLY.VARIANT_GOLD.NAME"` (the
non-obvious case). A third case asserts the `null` fallback still works for
a type with no matching `ldsfld` (e.g. a hand-built `TypeDefinition` fixture
with an unrelated method body, not a real game type — keeps the test fast
and independent of the DLL for the negative case).

**End-to-end (manual, after implementation):**
1. `dotnet build -c Release` in `dotnet-extractor/`.
2. `dotnet run -c Release --no-build -- ../game-data/OxygenNotIncluded_Data ../json-cache`.
3. Spot-check `json-cache/critters.json`: `OilFloater`'s
   `name_localization_id` should now be
   `"STRINGS.CREATURES.SPECIES.OILFLOATER.NAME"` (not `null`).
4. `php artisan game:import --skip-extractor --no-interaction` (destructive —
   confirm with the user before running).
5. Query `critter_morphs` for `OilFloater`/`GoldBelly`/`HatchHard`: names
   should now read `"Slickster"`/`"Golden Bammoth"`(or similar)/`"Stone
   Hatch"` instead of raw ids.
6. Re-run `scripts/design-audit/capture.sh` and visually confirm the
   `/critters` grid and `/critters/hatch` tabs show real names.

**Gate:** `dotnet test` in `dotnet-extractor.Tests/` must pass; no PHP/Vue
gates are affected since no PHP/Vue file changes.

## Out of Scope

Achieving 100% name coverage (some critters may genuinely not follow this
pattern — acceptable to leave as `critter_id` fallback, matching today's
"best-effort" extraction philosophy documented elsewhere in this codebase);
any change to how buildings/plants/elements resolve names (already working);
description (`DESC`) key correctness beyond what naturally follows from a
correct `NAME` key (not separately investigated, but no reason to expect a
regression since the suffix-swap logic is unchanged).
