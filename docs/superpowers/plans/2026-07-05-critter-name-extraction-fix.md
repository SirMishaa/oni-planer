# Critter Localized Name Extraction Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `dotnet-extractor`'s `CritterExtractor.FindNameKey()` return a correct, resolvable localization key for critter/morph names instead of always returning `null`, so real display names (e.g. "Slickster" instead of "OilFloater") flow through the existing, unmodified `StringResolver` → `CritterImporter` → `CritterPresenter` pipeline.

**Architecture:** One-method fix in `dotnet-extractor/Extractors/CritterExtractor.cs`. Root cause (confirmed by direct IL inspection): the method scans for an `ldstr` literal that never exists; the real localization key is derivable from an `ldsfld` instruction's `FieldReference.DeclaringType.FullName` that always exists in every case checked. No PHP, Vue, or database schema changes.

**Tech Stack:** C# / .NET 10, Mono.Cecil 0.11.5, xUnit (`dotnet-extractor.Tests`).

## Global Constraints

- No `Co-Authored-By` trailers and no GPG signing in commits — use `git -c commit.gpgsign=false commit`.
- No changes to `App\Services\GameImport\StringResolver`, `App\Services\GameImport\CritterImporter`, `App\Services\Critters\CritterPresenter`, or any PHP/Vue file — this plan is extractor-only, per the design spec's explicit non-goal.
- No dependency changes to `dotnet-extractor.csproj` or `dotnet-extractor.Tests.csproj` — `Mono.Cecil` is already referenced in both.
- Follow existing code conventions in `CritterExtractor.cs` (other `Find*` private static methods in the same file use the same `TypeDefinition` → nullable-string-or-value signature style — match it).

---

## Task 1: Fix `FindNameKey()` to scan `ldsfld` instead of `ldstr`

**Files:**
- Modify: `dotnet-extractor/Extractors/CritterExtractor.cs:475-488`
- Test: `dotnet-extractor.Tests/CritterExtractorTests.cs`

**Interfaces:**
- Consumes: `TypeDefinition` (Mono.Cecil), already imported via `using Mono.Cecil;` at the top of `CritterExtractor.cs`.
- Produces: `FindNameKey(TypeDefinition type): string?` — same signature as before, corrected return value. Called identically at the existing call site `CritterExtractor.cs:123` (`var nameKey = FindNameKey(type);`) — no change needed there.

- [ ] **Step 1: Write the failing tests**

Add to `dotnet-extractor.Tests/CritterExtractorTests.cs` (this file already has a class-level `Module` field loading the real `Assembly-CSharp.dll` via `ModuleDefinition.ReadModule` — reuse it, don't create a new module load):

```csharp
[Fact]
public void FindNameKey_resolves_simple_species_config()
{
    var type = Module.GetType("OilFloaterConfig");
    var key = CritterExtractor.InvokeFindNameKey(type);

    Assert.Equal("STRINGS.CREATURES.SPECIES.OILFLOATER.NAME", key);
}

[Fact]
public void FindNameKey_resolves_variant_whose_species_id_differs_from_class_name()
{
    // GoldBellyConfig's true species id is ICEBELLY with variant suffix
    // VARIANT_GOLD — neither is derivable from the class name or from
    // this extractor's own variant_family output ("Belly"). This is the
    // case that proves the fix works generally, not just for the easy case.
    var type = Module.GetType("GoldBellyConfig");
    var key = CritterExtractor.InvokeFindNameKey(type);

    Assert.Equal("STRINGS.CREATURES.SPECIES.ICEBELLY.VARIANT_GOLD.NAME", key);
}

[Fact]
public void FindNameKey_resolves_baby_variant_config()
{
    var type = Module.GetType("BabyIceBellyConfig");
    var key = CritterExtractor.InvokeFindNameKey(type);

    Assert.Equal("STRINGS.CREATURES.SPECIES.ICEBELLY.BABY.NAME", key);
}

[Fact]
public void FindNameKey_returns_null_when_no_matching_field_read_exists()
{
    // A type with methods but no ldsfld targeting a STRINGS...::NAME field
    // must fall back to null, exactly like today's behavior for every
    // critter — this is the safety net the rest of the pipeline relies on.
    var module = ModuleDefinition.CreateModule("Test", ModuleKind.Dll);
    var type = new TypeDefinition("", "EmptyType", Mono.Cecil.TypeAttributes.Public, module.TypeSystem.Object);
    var method = new MethodDefinition("DoNothing", Mono.Cecil.MethodAttributes.Public, module.TypeSystem.Void);
    method.Body.Instructions.Add(Instruction.Create(OpCodes.Ret));
    type.Methods.Add(method);

    var key = CritterExtractor.InvokeFindNameKey(type);

    Assert.Null(key);
}
```

`FindNameKey` is currently `private static`. Add this one-line internal test
hook to `CritterExtractor.cs` — a thin public wrapper, placed immediately
above the existing `private static string? FindNameKey(TypeDefinition type)`
declaration (do not make `FindNameKey` itself public; keep the class's public
surface unchanged except for this single test-only entry point, matching how
this test file already calls extractor internals directly since it's an
internal test project, not an external consumer):

```csharp
    public static string? InvokeFindNameKey(TypeDefinition type) => FindNameKey(type);

```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd dotnet-extractor.Tests && dotnet test --filter "FindNameKey"`
Expected: FAIL — all 4 new tests fail. The first three fail because
`FindNameKey` currently always returns `null` (assertions expect a non-null
key); the 4th ("returns_null...") actually passes already by coincidence
(current code returns null for everything) — that's fine, it becomes a real
regression guard once the fix lands, not a meaningful RED step on its own.

- [ ] **Step 3: Implement the fix**

In `dotnet-extractor/Extractors/CritterExtractor.cs`, replace lines 475-488:

```csharp
    private static string? FindNameKey(TypeDefinition type)
    {
        if (!type.HasMethods) return null;
        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            foreach (var instr in method.Body.Instructions)
            {
                if (instr.OpCode == OpCodes.Ldstr && instr.Operand is string s &&
                    s.StartsWith("STRINGS.CREATURES"))
                    return s;
            }
        }
        return null;
    }
```

with:

```csharp
    public static string? InvokeFindNameKey(TypeDefinition type) => FindNameKey(type);

    /// <summary>
    /// Find the localization key for a critter's display name.
    ///
    /// The game does NOT embed the full key (e.g.
    /// "STRINGS.CREATURES.SPECIES.OILFLOATER.NAME") as a single string
    /// literal anywhere — that string never exists in the assembly for
    /// creatures. Instead, the name is a static field on a nested class
    /// (e.g. STRINGS.CREATURES.SPECIES.OILFLOATER.NAME), and the critter's
    /// own IEntityConfig type reads that field via a plain `ldsfld` in its
    /// CreatePrefab method. The declaring type's IL FullName (Mono.Cecil
    /// represents nested types with '/' separators) converted to dots IS
    /// the correct dotted key StringResolver already expects.
    ///
    /// This also correctly handles variants whose true species id differs
    /// from both the critter_id and this extractor's own variant_family
    /// value (e.g. GoldBellyConfig's real species id is ICEBELLY with
    /// suffix VARIANT_GOLD — not derivable from any string this extractor
    /// otherwise produces).
    /// </summary>
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

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd dotnet-extractor.Tests && dotnet test --filter "FindNameKey"`
Expected: PASS — all 4 tests green.

- [ ] **Step 5: Run the full extractor test suite to check for regressions**

Run: `cd dotnet-extractor.Tests && dotnet test`
Expected: all existing tests still pass (this change only affects
`FindNameKey`, called only from the `nameKey` assignment at
`CritterExtractor.cs:123` — no other extractor behavior touched).

- [ ] **Step 6: Commit**

```bash
git add dotnet-extractor/Extractors/CritterExtractor.cs dotnet-extractor.Tests/CritterExtractorTests.cs
git -c commit.gpgsign=false commit -m "fix(extractor): resolve critter name keys via ldsfld instead of unreachable ldstr literal"
```

---

## Task 2: Re-extract and verify real names land in the database

**Files:** none (extraction + import + verification only, no code changes)

This task is destructive to the local database (full critter re-import) —
confirm with the user before running Step 3.

- [ ] **Step 1: Rebuild the extractor**

```bash
cd dotnet-extractor && dotnet build -c Release
```

Expected: build succeeds, 0 errors (2 pre-existing `NU1510` warnings about
`System.Text.Json` are expected and unrelated).

- [ ] **Step 2: Re-run extraction**

```bash
cd dotnet-extractor && dotnet run -c Release --no-build -- ../game-data/OxygenNotIncluded_Data ../json-cache
```

Expected: extraction completes. Verify the fix landed in the JSON before
touching the database:

```bash
python3 -c "
import json
data = json.load(open('json-cache/critters.json'))
total = len(data)
resolved = sum(1 for c in data if c.get('name_localization_id'))
print(f'{resolved}/{total} entries now have a name_localization_id')
oil = next(c for c in data if c['critter_id'] == 'OilFloater')
print('OilFloater key:', oil['name_localization_id'])
gold = next(c for c in data if c['critter_id'] == 'GoldBelly')
print('GoldBelly key:', gold['name_localization_id'])
"
```

Expected: a large majority of the 97 entries now show a non-null key (not
necessarily 100% — some critter types may not follow the `ldsfld` pattern,
which is fine, see the spec's Out of Scope); `OilFloater key:
STRINGS.CREATURES.SPECIES.OILFLOATER.NAME`; `GoldBelly key:
STRINGS.CREATURES.SPECIES.ICEBELLY.VARIANT_GOLD.NAME`.

- [ ] **Step 3: Confirm with the user, then re-import**

Ask the user to confirm before running this — it truncates and reseeds the
`critters`, `critter_morphs`, `critter_morph_diets`, `critter_morph_outputs`
tables (same command already used earlier in this project for the `dlc_id`
fix, so the destructive-confirmation precedent is established):

```bash
php artisan game:import --skip-extractor --no-interaction -v
```

Expected: import completes without errors.

- [ ] **Step 4: Verify real names in the database**

Using `mcp__laravel-boost__database-query` (read-only):

```sql
select critter_id, name from critter_morphs where morph_id in ('OilFloater', 'GoldBelly', 'HatchHard', 'HatchVeggie', 'ParrotFish') and is_base = 1
```

Expected: `name` now shows real display names, e.g.
`{"en":"<link=\"OILFLOATER\">Slickster</link>"}` for `OilFloater` (the
`<link>` markup is pre-existing `StringResolver` behavior for other entity
types too — not something this plan changes; if the raw markup renders
visibly in the UI rather than as a clickable link or plain text, note it as
a follow-up, but do not fix it as part of this plan since it's a
pre-existing `StringResolver`/presentation concern, not part of the name-key
extraction bug this plan targets).

- [ ] **Step 5: Re-run the design audit and confirm visually**

```bash
./scripts/design-audit/capture.sh
```

Read `scripts/design-audit/screenshots/critters-index--desktop--light.png`
and `critters-show--desktop--light.png`. Expected: family tile names and
variant tabs show real names (e.g. "Slickster" family tile, "Stone Hatch" /
"Sage Hatch" tabs on the Hatch page) instead of raw ids like `OilFloater` /
`HatchHard`.

If the `<link="...">Name</link>` markup from Step 4 shows up literally in
the UI (e.g. tile reads `<link="OILFLOATER">Slickster</link>` instead of
just `Slickster`), stop and report this as a new, separate finding — do not
attempt to strip the markup as part of this plan, since that's a
`StringResolver`/presenter display concern outside this plan's scope (fixing
the name *key extraction* bug), and the right fix location needs its own
short investigation.
