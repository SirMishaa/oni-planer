# Critter Ranching Data Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Populate the database with best-effort ranching data (diet consume→produce, egg/dropping/resource outputs, calories/cycle, lifespan, overcrowding, and a `variant_family` grouping label) for every critter and variant, by extending the .NET IL extractor and wiring the new field through import.

**Architecture:** Extend `dotnet-extractor` (Mono.Cecil static IL analysis of `Assembly-CSharp.dll`). A new `TuningConstantResolver` reads `*Tuning` static-constructor constants to recover values stored in `ldsfld` fields; `CritterExtractor` is extended to trace `Diet.Info` construction (consumed tags are inline `CreateTag(SimHashes)`; produced element / calories-per-kg / conversion-rate are passed as args to a shared `Base*Config` diet method, so the call site is followed), egg outputs from `ExtendEntityToFertileCreature`, and `variant_family` from the shared `Base*Config`. All rates/durations are normalized to **per-cycle** (1 cycle = 600 s). The Laravel `CritterImporter` already ingests `diets`/`outputs`; only `variant_family` is added on the Laravel side. Residual zeros are acceptable and reported, never fatal.

**Tech Stack:** C# / .NET 10 / Mono.Cecil 0.11.5 (extractor); xUnit (extractor tests); Laravel 13 / Eloquent / Pest (import). Python not involved.

**Reference facts (confirmed against the real DLL on 2026-06-10):**
- `Diet.Info..ctor(HashSet<Tag> consumed_tags, Tag produced_element, float calories_per_kg, float produced_conversion_rate, string disease_id, float disease_per_kg_produced, bool produce_solid_tile, Diet.Info.FoodType food_type, bool emmit_disease_on_cell, string[] eat_anims)` — 10 params.
- In `BaseHatchConfig.BasicRockDiet`/`HardRockDiet`/`MetalDiet`, the **consumed** tag is built inline as `ldc.i4 <simHash>` → `GameTagExtensions::CreateTag(SimHashes)` → `HashSet<Tag>.Add`. The **produced element**, **calories_per_kg**, **conversion_rate** arrive as method args (`ldarg.1/2/3`) — i.e. the real values are at the call site in the `IEntityConfig` (`HatchConfig`) that calls the base diet method.
- `EntityTemplates.ExtendEntityToFertileCreature(...)` has several overloads; param **names** are stable: `eggId`/`eggId`, `babyId`/`baby_id`, `fertilityCycles`/`fertility_cycles`, `incubationCycles`/`incubation_cycles`, `eggMass`/`egg_mass`. HatchConfig passes `fertilityCycles=60`, `incubationCycles=20`.
- `HatchTuning` (`.cctor` constants): `STANDARD_CALORIES_PER_CYCLE = 700000`, `STANDARD_STARVE_CYCLES = 10`, `EGG_MASS = 2`, `EGG_CHANCES_* = 0.02/0.67`. `PEN_SIZE_PER_CREATURE` is an `Int32` (set via `ldc.i4`, so the resolver must handle int and float). `STANDARD_STOMACH_SIZE` is computed, not a literal.
- `IlHelper` already provides `ExtractCallArgs`, `TryReadConstant`, `BuildSimHashesMap` (int→element id), `FindNewobj`, `ReadFieldStores`. `CritterExtractor` already detects the shared `Base*Config` call (used for temps) and resolves it via `mr.DeclaringType.Resolve()`.
- Extractor entry point: `dotnet run --project dotnet-extractor -- <game-data-path> <cache-path>` writes `critters.json`. Repo DLL: `game-data/OxygenNotIncluded_Data/Managed/Assembly-CSharp.dll`. The Laravel command `php artisan game:import` reads the JSON cache.

---

## File Structure

- `dotnet-extractor/Extractors/TuningConstantResolver.cs` — **new**: resolve `*Tuning` `.cctor` constants (float/int), with a per-type cache.
- `dotnet-extractor/Extractors/CritterExtractor.cs` — **modify**: add diet, calories, egg outputs, lifespan, overcrowding (via resolver), `variant_family`; add a `CritterId → overrides` table; `CritterDto` gains `VariantFamily`.
- `dotnet-extractor/Extractors/CritterDietResolver.cs` — **new**: given an `IEntityConfig` type, return resolved diet entries (consumed element ids, produced element id, calories_per_kg, conversion_ratio) by tracing the shared base diet method + its call site.
- `dotnet-extractor.Tests/CritterExtractorTests.cs` — **new**: golden-value tests against the repo DLL.
- `dotnet-extractor.Tests/TuningConstantResolverTests.cs` — **new**: resolver unit tests against the repo DLL.
- `database/migrations/2026_06_10_000000_add_variant_family_to_critters_table.php` — **new**.
- `app/Models/Critter.php` — **modify**: add `variant_family` to `#[Fillable]`.
- `app/Services/GameImport/CritterImporter.php` — **modify**: persist `variant_family`.
- `tests/Fixtures/GameImport/critters.json` — **modify**: realistic diet/outputs/variant_family + per-cycle units.
- `tests/Feature/GameImport/CritterImporterTest.php` — **modify/create**: assert diet/output/variant_family persistence.

A "cycle" constant (`600f`) lives as a `const` in `CritterExtractor`; unit normalization happens in the extractor so the JSON and DB are already per-cycle.

---

## Task 1: Reconnaissance spike — record real diet/egg/tuning IL patterns

**Files:**
- Create (throwaway): `dotnet-extractor/Extractors/_probe.cs` (deleted at end of task)

- [ ] **Step 1: Add a temporary probe behind an env flag**

Create `dotnet-extractor/Extractors/_probe.cs` with a `CritterProbe.Run(string managedPath)` that, for `HatchConfig`, `HatchHardSkinConfig` (Stone), `SageHatchConfig`/`HatchVeggieConfig`, `DreckoConfig`, `PacuConfig`, `PuftConfig`, prints:
- every call to a `Base*Config` method (name + the args read via `IlHelper.ExtractCallArgs`, decoding any `ldsfld`/`SimHashes` operands seen in the 30 instructions before the call),
- inside that base method: each `GameTagExtensions::CreateTag(SimHashes)` operand (the consumed simHash int) and any `ldsfld GameTags::X` consumed-category tags,
- the `ExtendEntityToFertileCreature` call's args by param name (`eggId`, `babyId`, `fertilityCycles`, `incubationCycles`, `eggMass`),
- the `*Tuning` type each config references (from `ldsfld` declaring types) and that type's `.cctor` literal constants.

Invoke it from `Program.cs` only when `Environment.GetEnvironmentVariable("CRITTER_PROBE") == "1"`, before the normal extraction, then `return 0;`.

- [ ] **Step 2: Run the probe and RECORD findings**

Run: `CRITTER_PROBE=1 dotnet run --project dotnet-extractor -- game-data/OxygenNotIncluded_Data /tmp/cache`
Record into the task notes / commit message:
- For each consumed simHash int, the element id from `IlHelper.BuildSimHashesMap` (e.g. confirm `-1708952091` → which element/category).
- Which arg index of each base diet method is the produced element, calories_per_kg, conversion_ratio, and whether produced element is an `ldsfld SimHashes`/`GameTags` or a `CreateTag` at the call site.
- Whether consumed "tags" are concrete elements (SimHashes) or category tags (`GameTags.Metal`, `GameTags.RefinedMetal`, etc.) — category tags cannot map to a single `element_id` and must be recorded as the tag name for the OVERRIDES step.
- Confirm `fertilityCycles`/`incubationCycles` units (cycles vs seconds) by cross-checking HatchConfig (`60`/`20`) against the wiki.
- The `*Tuning` constant names per family used for calories/pen-size.

- [ ] **Step 3: Delete the probe and commit the recorded notes**

```bash
rm dotnet-extractor/Extractors/_probe.cs
# revert the Program.cs probe hook
git add docs/superpowers/plans/2026-06-10-critter-ranching-data-extraction.md
git commit -m "docs(critters): record critter IL diet/egg/tuning patterns from spike"
```

(Paste the recorded findings into a `## Spike Findings` section appended to this plan before committing.)

---

## Task 2: TuningConstantResolver

**Files:**
- Create: `dotnet-extractor/Extractors/TuningConstantResolver.cs`
- Test: `dotnet-extractor.Tests/TuningConstantResolverTests.cs`

- [ ] **Step 1: Write the failing test**

```csharp
using Mono.Cecil;

public class TuningConstantResolverTests
{
    private const string ManagedPath = "../../../../game-data/OxygenNotIncluded_Data/Managed";

    [Fact]
    public void Resolves_float_and_int_tuning_constants()
    {
        var module = ModuleDefinition.ReadModule(System.IO.Path.Combine(ManagedPath, "Assembly-CSharp.dll"));
        var resolver = new TuningConstantResolver(module);
        var tuning = module.GetType("HatchTuning");

        Assert.True(resolver.TryResolve(tuning, "STANDARD_CALORIES_PER_CYCLE", out var calories));
        Assert.Equal(700000f, calories);

        Assert.True(resolver.TryResolve(tuning, "EGG_MASS", out var eggMass));
        Assert.Equal(2f, eggMass);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `dotnet test dotnet-extractor.Tests --filter TuningConstantResolverTests`
Expected: FAIL — `TuningConstantResolver` does not exist.

- [ ] **Step 3: Implement the resolver**

```csharp
using Mono.Cecil;
using Mono.Cecil.Cil;

/// <summary>
/// Resolves constant values of static `*Tuning` fields by reading the type's
/// static constructor (.cctor). Handles both float (ldc.r4) and int (ldc.i4*)
/// literals assigned directly via stsfld. Computed/non-literal fields are left
/// unresolved (TryResolve returns false).
/// </summary>
public sealed class TuningConstantResolver
{
    private readonly Dictionary<string, Dictionary<string, float>> _cache = new();

    public TuningConstantResolver(ModuleDefinition module) => _module = module;
    private readonly ModuleDefinition _module;

    public bool TryResolve(TypeDefinition? tuningType, string fieldName, out float value)
    {
        value = 0f;
        if (tuningType == null) return false;
        var map = MapFor(tuningType);
        return map.TryGetValue(fieldName, out value);
    }

    private Dictionary<string, float> MapFor(TypeDefinition tuningType)
    {
        if (_cache.TryGetValue(tuningType.FullName, out var cached)) return cached;

        var map = new Dictionary<string, float>();
        var cctor = tuningType.Methods.FirstOrDefault(m => m.Name == ".cctor" && m.HasBody);
        if (cctor != null)
        {
            var ins = cctor.Body.Instructions;
            for (int i = 0; i < ins.Count; i++)
            {
                if (ins[i].OpCode != OpCodes.Stsfld || ins[i].Operand is not FieldReference fr) continue;
                // Walk back to the nearest constant feeding this stsfld, stopping at
                // a call/newobj/another stsfld (different sub-expression).
                for (int j = i - 1; j >= 0; j--)
                {
                    var op = ins[j].OpCode.Code;
                    if (IlHelper.TryReadConstant(ins[j], out var c) && c is not null)
                    {
                        if (c is float f) { map[fr.Name] = f; }
                        else if (c is int iv) { map[fr.Name] = iv; }
                        else if (c is double d) { map[fr.Name] = (float)d; }
                        break;
                    }
                    if (op == Code.Call || op == Code.Callvirt || op == Code.Newobj || op == Code.Stsfld) break;
                }
            }
        }
        _cache[tuningType.FullName] = map;
        return map;
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `dotnet test dotnet-extractor.Tests --filter TuningConstantResolverTests`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add dotnet-extractor/Extractors/TuningConstantResolver.cs dotnet-extractor.Tests/TuningConstantResolverTests.cs
git commit -m "feat(extractor): resolve *Tuning .cctor constants"
```

---

## Task 3: Diet extraction (CritterDietResolver)

**Files:**
- Create: `dotnet-extractor/Extractors/CritterDietResolver.cs`
- Test: extend `dotnet-extractor.Tests/CritterExtractorTests.cs` (created in Task 6; if running out of order, create the file with the test below)

> Implement against the **Spike Findings** recorded in Task 1. The structure below matches the confirmed pattern: consumed elements are inline `CreateTag(SimHashes)` in the shared `Base*Config` diet method; produced element + calories_per_kg + conversion_ratio are the args the `IEntityConfig` passes into that base method.

- [ ] **Step 1: Write the failing test**

```csharp
[Fact]
public void Hatch_diet_consumes_minerals_and_produces_coal()
{
    var diets = new CritterDietResolver(_module, _simHashes)
        .Resolve(_module.GetType("HatchConfig"));

    Assert.NotEmpty(diets);
    Assert.All(diets, d => Assert.Equal("Carbon", d.ProducedElementId)); // Hatch → Coal (Carbon)
    Assert.Contains(diets, d => d.ConsumedElementId is not null);
    Assert.All(diets, d => Assert.True(d.CaloriesPerKg > 0));
}
```

(`_module` and `_simHashes = IlHelper.BuildSimHashesMap(_module)` are test fixtures set in a constructor; see Task 6.)

- [ ] **Step 2: Run it to verify it fails**

Run: `dotnet test dotnet-extractor.Tests --filter Hatch_diet`
Expected: FAIL — `CritterDietResolver` does not exist.

- [ ] **Step 3: Implement the diet resolver**

```csharp
using Mono.Cecil;
using Mono.Cecil.Cil;

public sealed record ResolvedDiet(
    string? ConsumedElementId,
    string? ConsumedTag,      // non-null when consumed is a category tag (e.g. "Metal"), not a single element
    string? ProducedElementId,
    float CaloriesPerKg,
    float ConversionRatio);

/// <summary>
/// Resolves a critter's diet by locating Diet.Info construction. Consumed
/// elements are inline CreateTag(SimHashes) calls in the shared Base*Config diet
/// method; the produced element, calories_per_kg and conversion_ratio are passed
/// by the IEntityConfig into that base diet method, so we read them at the call
/// site. One ResolvedDiet is emitted per consumed element in the set.
/// </summary>
public sealed class CritterDietResolver
{
    private readonly ModuleDefinition _module;
    private readonly Dictionary<int, string> _simHashes;

    public CritterDietResolver(ModuleDefinition module, Dictionary<int, string> simHashes)
    { _module = module; _simHashes = simHashes; }

    public List<ResolvedDiet> Resolve(TypeDefinition? config)
    {
        var results = new List<ResolvedDiet>();
        if (config?.HasMethods != true) return results;

        foreach (var method in config.Methods.Where(m => m.HasBody))
        {
            var ins = method.Body.Instructions;
            for (int i = 0; i < ins.Count; i++)
            {
                if (ins[i].OpCode != OpCodes.Call && ins[i].OpCode != OpCodes.Callvirt) continue;
                if (ins[i].Operand is not MethodReference mr) continue;
                var declName = mr.DeclaringType.Name;
                if (!(declName.StartsWith("Base") && declName.EndsWith("Config"))) continue;

                var baseMethod = TryResolveBaseDietMethod(mr);
                if (baseMethod == null) continue;

                // Consumed elements: inline CreateTag(SimHashes) in the base method.
                var consumed = CollectConsumedElements(baseMethod);
                if (consumed.Count == 0) continue;

                // produced/calories/conversion: args passed at the call site (this method).
                var args = IlHelper.ExtractCallArgs(ins, i, mr.Parameters.Count);
                var (produced, calories, conversion) = ReadDietArgs(mr, args, ins, i);

                foreach (var c in consumed)
                {
                    results.Add(new ResolvedDiet(
                        ConsumedElementId: c.ElementId,
                        ConsumedTag: c.TagName,
                        ProducedElementId: produced,
                        CaloriesPerKg: calories,
                        ConversionRatio: conversion));
                }
            }
        }
        return results;
    }

    private static MethodDefinition? TryResolveBaseDietMethod(MethodReference mr)
    {
        try { return mr.DeclaringType.Resolve()?.Methods.FirstOrDefault(m => m.Name == mr.Name && m.HasBody); }
        catch (AssemblyResolutionException) { return null; }
    }

    private List<(string? ElementId, string? TagName)> CollectConsumedElements(MethodDefinition baseMethod)
    {
        var consumed = new List<(string?, string?)>();
        var ins = baseMethod.Body.Instructions;
        for (int i = 0; i < ins.Count; i++)
        {
            // ldc.i4 <hash> ; call CreateTag(SimHashes)
            if (ins[i].Operand is MethodReference cr && cr.Name == "CreateTag"
                && i > 0 && IlHelper.TryReadConstant(ins[i - 1], out var hv) && hv is int hash
                && _simHashes.TryGetValue(hash, out var elementId))
            {
                consumed.Add((elementId, null));
            }
            // ldsfld GameTags::X  → category tag (no single element)
            else if (ins[i].OpCode == OpCodes.Ldsfld && ins[i].Operand is FieldReference fr
                     && fr.DeclaringType.Name == "GameTags")
            {
                consumed.Add((null, fr.Name));
            }
        }
        return consumed;
    }

    private (string? Produced, float Calories, float Conversion) ReadDietArgs(
        MethodReference mr, object?[] args, IList<Instruction> ins, int callIndex)
    {
        // Map by parameter name where possible (names confirmed in spike).
        float Calories() => FindFloatArg(mr, args, "calories");
        float Conversion() => FindFloatArg(mr, args, "conversion");
        // produced element is a Tag/SimHashes arg: scan the call-site window for
        // an ldsfld SimHashes/GameTags or ldc.i4<hash>+CreateTag feeding the call.
        string? produced = FindProducedElement(ins, callIndex);
        return (produced, Calories(), Conversion());
    }

    private static float FindFloatArg(MethodReference mr, object?[] args, string nameContains)
    {
        for (int p = 0; p < mr.Parameters.Count; p++)
            if (mr.Parameters[p].ParameterType.Name == "Single"
                && mr.Parameters[p].Name.ToLower().Contains(nameContains)
                && p < args.Length && args[p] is float f) return f;
        return 0f;
    }

    private string? FindProducedElement(IList<Instruction> ins, int callIndex)
    {
        for (int j = callIndex - 1; j >= 0 && j > callIndex - 40; j--)
        {
            if (ins[j].Operand is MethodReference cr && cr.Name == "CreateTag"
                && j > 0 && IlHelper.TryReadConstant(ins[j - 1], out var hv) && hv is int hash
                && _simHashes.TryGetValue(hash, out var id)) return id;
            if (ins[j].OpCode == OpCodes.Ldsfld && ins[j].Operand is FieldReference fr
                && fr.DeclaringType.Name == "SimHashes") return fr.Name;
        }
        return null;
    }
}
```

> Adjust `FindProducedElement` / `ReadDietArgs` to match the exact arg order recorded in the spike if param-name matching is insufficient for a given base method.

- [ ] **Step 4: Run it to verify it passes**

Run: `dotnet test dotnet-extractor.Tests --filter Hatch_diet`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add dotnet-extractor/Extractors/CritterDietResolver.cs dotnet-extractor.Tests/CritterExtractorTests.cs
git commit -m "feat(extractor): resolve critter diets (consume/produce/calories)"
```

---

## Task 4: Calories, lifespan, overcrowding via resolver + OVERRIDES

**Files:**
- Modify: `dotnet-extractor/Extractors/CritterExtractor.cs`

- [ ] **Step 1: Write the failing test (in CritterExtractorTests)**

```csharp
[Fact]
public void Hatch_has_calories_and_pen_size()
{
    var hatch = new CritterExtractor(ManagedPath).Extract()
        .First(c => c.CritterId == "Hatch");

    Assert.Equal(700000f, hatch.CaloriesPerCycle);
    Assert.True(hatch.OvercrowdingThreshold > 0); // PEN_SIZE_PER_CREATURE
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `dotnet test dotnet-extractor.Tests --filter Hatch_has_calories`
Expected: FAIL — `CaloriesPerCycle` is `0f`.

- [ ] **Step 3: Implement calories/overcrowding/lifespan resolution**

In `CritterExtractor`, add a `TuningConstantResolver` field (constructed once in `Extract`), a `FindTuningTypeFor(TypeDefinition config)` that returns the `*Tuning` type referenced by the config's `ldsfld` instructions (e.g. `HatchTuning`), and an `OVERRIDES` table:

```csharp
// critter_id -> (calories_per_cycle, lifespan_cycles, overcrowding) for configs whose
// IL resists extraction. Values sourced from the game/wiki and documented inline.
private static readonly Dictionary<string, (float Calories, float Lifespan, int Pen)> Overrides = new()
{
    // e.g. { "SomeCritter", (700000f, 100f, 8) },
};
```

Replace the `CaloriesPerCycle: 0f` / `Lifespan: 0f` construction in `ExtractCritter` with:

```csharp
var tuning = FindTuningTypeFor(type);
float calories = ResolveCaloriesPerCycle(type, tuning); // inline arg → tuning STANDARD_CALORIES_PER_CYCLE → override → 0
float lifespan = ResolveLifespan(type, tuning);         // tuning → override → 0
int overcrowding = FindOvercrowdingThreshold(type);
if (overcrowding == 0 && tuning != null && _tuning.TryResolve(tuning, "PEN_SIZE_PER_CREATURE", out var pen))
    overcrowding = (int)pen;
if (Overrides.TryGetValue(critterId, out var ov))
{
    if (calories == 0) calories = ov.Calories;
    if (lifespan == 0) lifespan = ov.Lifespan;
    if (overcrowding == 0) overcrowding = ov.Pen;
}
```

Where `ResolveCaloriesPerCycle` first tries an inline float on the relevant call, then `_tuning.TryResolve(tuning, "STANDARD_CALORIES_PER_CYCLE", out ...)`. `ResolveLifespan` tries common tuning field names recorded in the spike (e.g. a `*_LIFESPAN`/age field) and falls back to 0.

- [ ] **Step 4: Run it to verify it passes**

Run: `dotnet test dotnet-extractor.Tests --filter Hatch_has_calories`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add dotnet-extractor/Extractors/CritterExtractor.cs
git commit -m "feat(extractor): resolve calories/lifespan/overcrowding via tuning + overrides"
```

---

## Task 5: Egg + dropping outputs and diet→DTO wiring

**Files:**
- Modify: `dotnet-extractor/Extractors/CritterExtractor.cs`

- [ ] **Step 1: Write the failing test**

```csharp
[Fact]
public void Hatch_lays_an_egg_output_and_has_diet_outputs()
{
    var hatch = new CritterExtractor(ManagedPath).Extract()
        .First(c => c.CritterId == "Hatch");

    Assert.NotEmpty(hatch.Diets);
    Assert.Contains(hatch.Outputs, o => o.OutputType == "egg");
    var egg = hatch.Outputs.First(o => o.OutputType == "egg");
    Assert.True(egg.AmountPerCycle > 0); // 1 / fertilityCycles
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `dotnet test dotnet-extractor.Tests --filter Hatch_lays_an_egg`
Expected: FAIL — `Diets`/`Outputs` are empty.

- [ ] **Step 3: Implement egg output + diet wiring**

In `ExtractCritter` (adult only): call `new CritterDietResolver(module, simHashes).Resolve(type)`, mapping each `ResolvedDiet` with a non-null `ConsumedElementId` to a `CritterDietDto(ConsumedElementId, AmountPerCycle: calories / CaloriesPerKg, ProducedElementId, ConversionRatio)`. Entries whose consumed is a category tag (`ConsumedTag != null`, `ConsumedElementId == null`) are skipped from `diets` (no single element) but recorded for a possible override.

Add `FindEgg(TypeDefinition type)` that locates `ExtendEntityToFertileCreature` and reads, **by parameter name**, `eggId` and `fertilityCycles`:

```csharp
private CritterOutputDto? FindEgg(TypeDefinition type)
{
    foreach (var method in type.Methods.Where(m => m.HasBody))
    {
        var ins = method.Body.Instructions;
        for (int i = 0; i < ins.Count; i++)
        {
            if (ins[i].Operand is not MethodReference mr || mr.Name != "ExtendEntityToFertileCreature") continue;
            var args = IlHelper.ExtractCallArgs(ins, i, mr.Parameters.Count);
            string? eggId = ArgByName(mr, args, "eggId") as string;
            float fertility = ArgByName(mr, args, "fertilityCycles") is float f ? f : 0f;
            if (eggId != null && fertility > 0)
                return new CritterOutputDto(eggId, 1f / fertility, "egg");
        }
    }
    return null;
}

private static object? ArgByName(MethodReference mr, object?[] args, string nameContains)
{
    for (int p = 0; p < mr.Parameters.Count && p < args.Length; p++)
        if (mr.Parameters[p].Name.ToLower().Replace("_", "").Contains(nameContains.ToLower().Replace("_", "")))
            return args[p];
    return null;
}
```

Assemble `Outputs`: the egg (if found) plus any non-diet dropping/resource emissions recorded in the spike (added to `Overrides`-style data if not IL-derivable). `eggId` (e.g. `"HatchEgg"`) is stored as the output `element_id`; the importer treats it as an output element string.

- [ ] **Step 4: Run it to verify it passes**

Run: `dotnet test dotnet-extractor.Tests --filter Hatch_lays_an_egg`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add dotnet-extractor/Extractors/CritterExtractor.cs
git commit -m "feat(extractor): egg outputs and diet DTO wiring"
```

---

## Task 6: variant_family + DTO field + extractor test fixture

**Files:**
- Modify: `dotnet-extractor/Extractors/CritterExtractor.cs` (add `VariantFamily` to `CritterDto`, derive it)
- Create: `dotnet-extractor.Tests/CritterExtractorTests.cs` (shared fixture + the assertions referenced above)

- [ ] **Step 1: Write the failing test**

```csharp
using Mono.Cecil;

public class CritterExtractorTests
{
    public const string ManagedPath = "../../../../game-data/OxygenNotIncluded_Data/Managed";
    private readonly ModuleDefinition _module =
        ModuleDefinition.ReadModule(System.IO.Path.Combine(ManagedPath, "Assembly-CSharp.dll"));
    private readonly Dictionary<int, string> _simHashes;
    public CritterExtractorTests() => _simHashes = IlHelper.BuildSimHashesMap(_module);

    [Fact]
    public void Hatch_variants_share_a_variant_family()
    {
        var critters = new CritterExtractor(ManagedPath).Extract();
        var hatch = critters.First(c => c.CritterId == "Hatch");
        var stone = critters.First(c => c.CritterId == "HatchHardSkin" || c.CritterId == "HatchHard");

        Assert.False(string.IsNullOrEmpty(hatch.VariantFamily));
        Assert.Equal(hatch.VariantFamily, stone.VariantFamily);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `dotnet test dotnet-extractor.Tests --filter Hatch_variants_share`
Expected: FAIL — `CritterDto` has no `VariantFamily`.

- [ ] **Step 3: Add `VariantFamily` to the DTO and derive it**

Add to `CritterDto`: `[property: JsonPropertyName("variant_family")] string? VariantFamily`. In `ExtractCritter`, derive it from the shared `Base*Config` name the config calls (strip `Base`/`Config`: `BaseHatchConfig` → `Hatch`); fallback to a name-prefix heuristic; pass it into the DTO.

```csharp
private static string? FindVariantFamily(TypeDefinition type)
{
    foreach (var method in type.Methods.Where(m => m.HasBody))
        foreach (var instr in method.Body.Instructions)
            if ((instr.OpCode == OpCodes.Call || instr.OpCode == OpCodes.Callvirt)
                && instr.Operand is MethodReference mr)
            {
                var n = mr.DeclaringType.Name;
                if (n.StartsWith("Base") && n.EndsWith("Config")) return n[4..^6]; // BaseHatchConfig → Hatch
            }
    return null;
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `dotnet test dotnet-extractor.Tests --filter Hatch_variants_share`
Expected: PASS. Then run the whole extractor suite: `dotnet test dotnet-extractor.Tests` — all green.

- [ ] **Step 5: Commit**

```bash
git add dotnet-extractor/Extractors/CritterExtractor.cs dotnet-extractor.Tests/CritterExtractorTests.cs
git commit -m "feat(extractor): derive variant_family from shared base config"
```

---

## Task 7: Laravel migration + model + importer for variant_family

**Files:**
- Create: `database/migrations/2026_06_10_000000_add_variant_family_to_critters_table.php`
- Modify: `app/Models/Critter.php`
- Modify: `app/Services/GameImport/CritterImporter.php`

- [ ] **Step 1: Write the failing test (in CritterImporterTest)**

Create/extend `tests/Feature/GameImport/CritterImporterTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Critter;
use App\Services\GameImport\CritterImporter;

it('imports diets, outputs and variant_family', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'crit').'.json';
    file_put_contents($path, json_encode([[
        'critter_id' => 'Hatch', 'is_base' => true,
        'min_temp' => 283.15, 'max_temp' => 313.15,
        'calories_per_cycle' => 700000, 'incubation_time' => 20, 'lifespan' => 100,
        'overcrowding_threshold' => 8, 'name_localization_id' => null,
        'variant_family' => 'Hatch',
        'diets' => [['consumed_element_id' => 'Carbon', 'amount_per_cycle' => 140, 'produced_element_id' => 'Carbon', 'conversion_ratio' => 1.0]],
        'outputs' => [['element_id' => 'HatchEgg', 'amount_per_cycle' => 0.0166, 'output_type' => 'egg']],
    ]]));

    // 'Carbon' element must exist for the FK; create it if the suite doesn't seed it.
    \App\Models\Element::factory()->create(['element_id' => 'Carbon']);

    app(CritterImporter::class)->import($path);

    $critter = Critter::where('critter_id', 'Hatch')->firstOrFail();
    expect($critter->variant_family)->toBe('Hatch');
    $morph = $critter->baseMorph;
    expect($morph->diets)->toHaveCount(1);
    expect($morph->outputs->first()->output_type)->toBe('egg');
});
```

(If `Element::factory()` or `morph->diets`/`morph->outputs` relations are missing, add them — diet/output relations on `CritterMorph` and a minimal `Element` factory — as part of this step.)

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=CritterImporterTest`
Expected: FAIL — `variant_family` column/attribute missing.

- [ ] **Step 3: Create the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('critters', function (Blueprint $table): void {
            $table->string('variant_family')->nullable()->after('critter_id');
        });
    }

    public function down(): void
    {
        Schema::table('critters', function (Blueprint $table): void {
            $table->dropColumn('variant_family');
        });
    }
};
```

- [ ] **Step 4: Wire model + importer**

In `app/Models/Critter.php`, add `variant_family` to the `#[Fillable([...])]` list. In `app/Services/GameImport/CritterImporter.php`, set it on the critter:

```php
$critter = Critter::query()->firstOrCreate(
    ['critter_id' => $parentId],
    ['name' => ['en' => $parentId]],
);

if (is_string($raw['variant_family'] ?? null) && $critter->variant_family !== $raw['variant_family']) {
    $critter->update(['variant_family' => $raw['variant_family']]);
}
```

- [ ] **Step 5: Run migration + tests**

Run: `php artisan migrate --no-interaction && php artisan test --compact --filter=CritterImporterTest`
Expected: PASS.

- [ ] **Step 6: Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_10_000000_add_variant_family_to_critters_table.php app/Models/Critter.php app/Services/GameImport/CritterImporter.php tests/Feature/GameImport/CritterImporterTest.php
git commit -m "feat(import): persist critter variant_family"
```

---

## Task 8: End-to-end extraction + import + verification

**Files:**
- Modify: `tests/Fixtures/GameImport/critters.json` (regenerate units to per-cycle)

- [ ] **Step 1: Run the full extractor against the repo DLL**

Run: `dotnet run --project dotnet-extractor -- game-data/OxygenNotIncluded_Data storage/app/game-cache`
Expected: `critters.json` written; spot-check that `Hatch`, `Drecko`, `Pacu`, `Puft` entries now contain non-empty `diets`, an `egg` output, `calories_per_cycle > 0`, and a `variant_family`.

- [ ] **Step 2: Import into the database**

Run: `php artisan game:import --no-interaction` (or the project's documented import invocation, e.g. `php artisan game:import critters`)
Expected: prints OK counts; any residual-zero criters are reported, not fatal.

- [ ] **Step 3: Verify via query**

Run:
```bash
php artisan tinker --execute '
echo "diets=".\App\Models\CritterMorphDiet::count()." outputs=".\App\Models\CritterMorphOutput::count();
echo " calories>0=".\App\Models\CritterMorph::where("calories_per_cycle", ">", 0)->count();
echo " families=".\App\Models\Critter::whereNotNull("variant_family")->distinct("variant_family")->count("variant_family");
'
```
Expected: `diets`, `outputs`, `calories>0`, and `families` all > 0. Sanity-compare Hatch (≈140 kg minerals/cycle, Coal product) to the wiki magnitude.

- [ ] **Step 4: Align the test fixture to per-cycle units**

Update `tests/Fixtures/GameImport/critters.json` so its values are per-cycle (e.g. `incubation_time` in cycles, not seconds) and include a `variant_family`, matching what the extractor now emits. Run `php artisan test --compact --filter=CritterImporter` — PASS.

- [ ] **Step 5: Final gates**

Run: `dotnet test dotnet-extractor.Tests && php artisan test --compact --filter=CritterImporter && vendor/bin/pint --dirty --format agent`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add tests/Fixtures/GameImport/critters.json
git commit -m "test(import): per-cycle critter fixture matching extractor output"
```

> Rendered/extracted JSON caches under `storage/app/` follow the repo's existing convention (not committed unless the repo already tracks them); committing the DB-affecting code + fixture is sufficient.

---

## Self-Review Notes

- **Spec coverage:** calories/diet/outputs/lifespan/overcrowding → Tasks 2–5; `variant_family` (derived from `Base*Config`, grouping-only) → Task 6; data-model unchanged except the added column → Task 7; per-cycle units → normalized in extractor + Task 8 fixture; best-effort zeros reported not fatal → Tasks 4/8; testing (golden C# + Pest + e2e) → Tasks 2–8.
- **Format-uncertainty handling:** Task 1 is a recorded spike pinning the real diet/egg/tuning IL across 6 representative critters before any extraction code ships; diet/calorie tasks implement against those findings, mirroring the proven approach used by the kanim plan.
- **Type consistency:** `TuningConstantResolver.TryResolve(TypeDefinition?, string, out float)`, `CritterDietResolver.Resolve(TypeDefinition?) → List<ResolvedDiet>`, `CritterDto.VariantFamily` (JSON `variant_family`), Laravel `critters.variant_family` — names consistent across tasks. `CritterOutputDto`/`CritterDietDto` already exist with the field names used here.
- **Known residual risk:** category-tag diets (e.g. `GameTags.Metal`) cannot map to a single `element_id` and are deliberately skipped from `diets` (recorded for OVERRIDES); lifespan may stay 0 where no tuning constant exists, filled via `Overrides` where known — consistent with the approved best-effort policy.
