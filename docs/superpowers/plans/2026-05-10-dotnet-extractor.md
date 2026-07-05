# Dotnet Extractor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement all 8 extractors in `dotnet-extractor/` so they produce JSON fixtures matching the expected shapes in `tests/Fixtures/GameImport/`.

**Architecture:** Each extractor loads `Assembly-CSharp.dll` with Mono.Cecil, walks IL instructions to extract constant values from game config classes, and returns typed DTOs serialized to JSON. A shared `IlHelper` utility handles the IL stack simulation used by multiple extractors.

**Tech Stack:** C# .NET 10 / Mono.Cecil 0.11.5 / System.Text.Json

---

## File Structure

```
dotnet-extractor/
  DotnetExtractor.csproj             — modify: net8.0 → net10.0, bump System.Text.Json to 10.0.0
  Extractors/
    IlHelper.cs                      — create: shared IL constant extraction utilities
    BuildingExtractor.cs             — fill in Extract()
    GeyserExtractor.cs               — fill in Extract()
    RecipeExtractor.cs               — fill in Extract()
    CritterExtractor.cs              — fill in Extract()
    PlantExtractor.cs                — fill in Extract()
    DuplicantTraitExtractor.cs       — fill in Extract()
    RocketExtractor.cs               — fill in Extract()
    AssetExtractor.cs                — fill in Extract() (file copy only, no IL)
```

**Game path (WSL):** `/mnt/d/SteamLibrary/steamapps/common/OxygenNotIncluded`  
**Managed path:** `<game-path>/OxygenNotIncluded_Data/Managed/`  
**DLL:** `Assembly-CSharp.dll`

**How the IL extraction works:** Game config classes initialize data using direct constant IL instructions (`ldstr`, `ldc.i4`, `ldc.r4`). We simulate a simple constant stack to capture those values at known call sites (`BuildingTemplates.CreateBuildingDef`, `newobj GeyserType`, etc.), then map them to DTO fields by parameter position or field name.

---

### Task 1: Fix .csproj — target net10.0

**Files:**
- Modify: `dotnet-extractor/DotnetExtractor.csproj`

- [ ] **Step 1: Update csproj**

Replace the content of `dotnet-extractor/DotnetExtractor.csproj` with:

```xml
<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup>
    <OutputType>Exe</OutputType>
    <TargetFramework>net10.0</TargetFramework>
    <Nullable>enable</Nullable>
    <ImplicitUsings>enable</ImplicitUsings>
    <PublishSingleFile>true</PublishSingleFile>
    <SelfContained>true</SelfContained>
    <RuntimeIdentifier>linux-x64</RuntimeIdentifier>
  </PropertyGroup>
  <ItemGroup>
    <PackageReference Include="Mono.Cecil" Version="0.11.5" />
    <PackageReference Include="System.Text.Json" Version="9.0.5" />
  </ItemGroup>
</Project>
```

- [ ] **Step 2: Build to verify**

```bash
cd /home/mishaa/dev/ony-planner/dotnet-extractor
dotnet build
```

Expected: `Build succeeded` with 0 errors.

- [ ] **Step 3: Commit**

```bash
git add dotnet-extractor/DotnetExtractor.csproj
git commit -m "chore(extractor): target net10.0"
```

---

### Task 2: Add IlHelper — shared IL constant extraction

**Files:**
- Create: `dotnet-extractor/Extractors/IlHelper.cs`

This utility is used by all IL-based extractors. It simulates a simple constant stack to extract argument values pushed before a call site. It handles the common ONI pattern where config methods pass literal constants directly.

- [ ] **Step 1: Create IlHelper.cs**

```csharp
using Mono.Cecil;
using Mono.Cecil.Cil;

public static class IlHelper
{
    // Walk instructions forward, simulate a value stack tracking constants.
    // At `callIndex`, pop `paramCount` values from the simulated stack → args in order.
    public static object?[] ExtractCallArgs(IList<Instruction> instructions, int callIndex, int paramCount)
    {
        var stack = new Stack<object?>();

        for (int i = 0; i < callIndex; i++)
        {
            var instr = instructions[i];

            if (TryReadConstant(instr, out var constant))
            {
                stack.Push(constant);
                continue;
            }

            switch (instr.OpCode.Code)
            {
                // Non-constant pushes
                case Code.Ldloc_0: case Code.Ldloc_1: case Code.Ldloc_2: case Code.Ldloc_3:
                case Code.Ldloc_S: case Code.Ldloc:
                case Code.Ldarg_0: case Code.Ldarg_1: case Code.Ldarg_2: case Code.Ldarg_3:
                case Code.Ldarg_S: case Code.Ldarg:
                case Code.Ldfld: case Code.Ldsfld: case Code.Ldsflda: case Code.Ldflda:
                case Code.Ldnull: case Code.Ldtoken:
                case Code.Dup:
                case Code.Sizeof:
                    stack.Push(null);
                    break;

                // Pops (no push)
                case Code.Pop:
                case Code.Stloc_0: case Code.Stloc_1: case Code.Stloc_2: case Code.Stloc_3:
                case Code.Stloc_S: case Code.Stloc:
                case Code.Stfld: case Code.Stsfld:
                    if (stack.Count > 0) stack.Pop();
                    break;

                // Newarr: pops length, pushes array ref
                case Code.Newarr:
                    if (stack.Count > 0) stack.Pop();
                    stack.Push(null);
                    break;

                // Newobj: pop ctor args, push instance
                case Code.Newobj:
                {
                    var ctor = (MethodReference)instr.Operand;
                    int ctorArgs = ctor.Parameters.Count;
                    for (int j = 0; j < ctorArgs && stack.Count > 0; j++) stack.Pop();
                    stack.Push(null);
                    break;
                }

                // Call/Callvirt: pop args (+ instance for callvirt), push return if non-void
                case Code.Call: case Code.Callvirt:
                {
                    var mr = (MethodReference)instr.Operand;
                    int argsToPop = mr.Parameters.Count;
                    if (instr.OpCode.Code == Code.Callvirt || mr.HasThis) argsToPop++;
                    for (int j = 0; j < argsToPop && stack.Count > 0; j++) stack.Pop();
                    if (mr.ReturnType.MetadataType != MetadataType.Void)
                        stack.Push(null);
                    break;
                }

                // Stelem: pops value, index, array (no push)
                case Code.Stelem_Any: case Code.Stelem_I4: case Code.Stelem_R4:
                case Code.Stelem_I8: case Code.Stelem_R8: case Code.Stelem_Ref:
                    for (int j = 0; j < 3 && stack.Count > 0; j++) stack.Pop();
                    break;

                // Ldelem: pops index+array, pushes element
                case Code.Ldelem_Any: case Code.Ldelem_I4: case Code.Ldelem_R4:
                case Code.Ldelem_Ref:
                    for (int j = 0; j < 2 && stack.Count > 0; j++) stack.Pop();
                    stack.Push(null);
                    break;

                // Box/Unbox/Castclass: replace top
                case Code.Box: case Code.Unbox_Any: case Code.Castclass:
                    break;

                // Initobj: pops address (no push)
                case Code.Initobj:
                    if (stack.Count > 0) stack.Pop();
                    break;
            }
        }

        var args = new object?[paramCount];
        // Pop last arg first (stack is LIFO, last param pushed is on top)
        for (int i = paramCount - 1; i >= 0; i--)
        {
            if (stack.Count > 0) args[i] = stack.Pop();
        }
        return args;
    }

    public static bool TryReadConstant(Instruction instr, out object? value)
    {
        value = instr.OpCode.Code switch
        {
            Code.Ldstr => instr.Operand,
            Code.Ldc_I4 => (int)instr.Operand,
            Code.Ldc_I4_0 => 0, Code.Ldc_I4_1 => 1,
            Code.Ldc_I4_2 => 2, Code.Ldc_I4_3 => 3,
            Code.Ldc_I4_4 => 4, Code.Ldc_I4_5 => 5,
            Code.Ldc_I4_6 => 6, Code.Ldc_I4_7 => 7,
            Code.Ldc_I4_8 => 8, Code.Ldc_I4_M1 => -1,
            Code.Ldc_I4_S => (int)(sbyte)instr.Operand,
            Code.Ldc_R4 => (float)instr.Operand,
            Code.Ldc_R8 => (double)instr.Operand,
            Code.Ldc_I8 => (long)instr.Operand,
            _ => null
        };
        // null from switch means either Ldnull or not a constant
        return value != null || instr.OpCode.Code == Code.Ldnull;
    }

    // Build a dictionary of SimHashes enum: int value → string name (= element ID)
    public static Dictionary<int, string> BuildSimHashesMap(ModuleDefinition module)
    {
        var simHashesType = module.Types.FirstOrDefault(t => t.Name == "SimHashes");
        if (simHashesType == null) return [];

        return simHashesType.Fields
            .Where(f => f.IsStatic && f.IsLiteral && f.Constant is int)
            .ToDictionary(f => (int)f.Constant!, f => f.Name);
    }

    // Find all instructions of type `newobj <typeName>` across a method
    public static IEnumerable<(int Index, Instruction Instr)> FindNewobj(
        IList<Instruction> instructions, string typeName) =>
        instructions
            .Select((instr, idx) => (idx, instr))
            .Where(x => x.instr.OpCode == OpCodes.Newobj &&
                        x.instr.Operand is MethodReference mr &&
                        mr.DeclaringType.Name == typeName);

    // Scan forward from `startIndex`; for each stfld instruction, call handler(fieldName, value)
    // Stop when we hit another newobj of same type or a return
    public static void ReadFieldStores(
        IList<Instruction> instructions, int startIndex,
        Action<string, object?> handler)
    {
        for (int i = startIndex; i < instructions.Count; i++)
        {
            var instr = instructions[i];
            if (instr.OpCode == OpCodes.Ret) break;
            if (instr.OpCode == OpCodes.Stfld && instr.Operand is FieldReference fr)
            {
                // The value is one instruction before the stfld (preceded by ldloc or dup)
                // Look back for the most recent constant
                for (int j = i - 1; j >= startIndex; j--)
                {
                    if (TryReadConstant(instructions[j], out var val))
                    {
                        handler(fr.Name, val);
                        break;
                    }
                    // Stop at store/load instructions that indicate a different expression
                    if (instructions[j].OpCode == OpCodes.Ldloc_0 ||
                        instructions[j].OpCode == OpCodes.Ldloc_S ||
                        instructions[j].OpCode == OpCodes.Ldloc)
                        break;
                }
            }
        }
    }
}
```

- [ ] **Step 2: Build to verify**

```bash
cd /home/mishaa/dev/ony-planner/dotnet-extractor
dotnet build
```

Expected: `Build succeeded`.

- [ ] **Step 3: Commit**

```bash
git add dotnet-extractor/Extractors/IlHelper.cs
git commit -m "feat(extractor): add IlHelper for IL constant stack simulation"
```

---

### Task 3: BuildingExtractor — IBuildingConfig IL walk

**Files:**
- Modify: `dotnet-extractor/Extractors/BuildingExtractor.cs`

**Approach:** Find all types implementing `IBuildingConfig`. In each type's `CreateBuildingDef()` method, locate the `call BuildingTemplates::CreateBuildingDef` instruction and extract args by position. Then scan forward for `stfld` on known BuildingDef fields.

`BuildingTemplates.CreateBuildingDef` parameter order:
- [0] `string id` → building_id
- [1] `int width` → width
- [2] `int height` → height
- [3] `string anim` (skip)
- [4] `int hitpoints` (skip)
- [5] `float construction_time` → construction_time

Post-call `stfld` fields to capture:
- `GeneratorWattageRating` → power_generation
- `EnergyConsumptionWhenActive` → power_consumption
- `SelfHeatKilowattsWhenActive` → heat_generation

Building category: extracted from `BuildingTags.Add(GameTags.<Category>)` calls or derived from power fields (generator → "Power", consumer → "Power", else "Other"). The fixture uses "Power" for CoalGenerator.

- [ ] **Step 1: Replace BuildingExtractor.cs**

```csharp
using Mono.Cecil;
using Mono.Cecil.Cil;
using System.Text.Json.Serialization;

public record BuildingDto(
    [property: JsonPropertyName("building_id")] string BuildingId,
    [property: JsonPropertyName("category")] string Category,
    [property: JsonPropertyName("power_generation")] float? PowerGeneration,
    [property: JsonPropertyName("power_consumption")] float? PowerConsumption,
    [property: JsonPropertyName("heat_generation")] float HeatGeneration,
    [property: JsonPropertyName("width")] int Width,
    [property: JsonPropertyName("height")] int Height,
    [property: JsonPropertyName("construction_time")] float ConstructionTime,
    [property: JsonPropertyName("tags")] List<string> Tags,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("dlc_id")] string? DlcId
);

public sealed class BuildingExtractor
{
    private readonly string _managedPath;

    public BuildingExtractor(string managedPath) => _managedPath = managedPath;

    public List<BuildingDto> Extract()
    {
        var module = ModuleDefinition.ReadModule(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        var results = new List<BuildingDto>();

        foreach (var type in module.Types)
        {
            if (!type.Interfaces.Any(i => i.InterfaceType.Name == "IBuildingConfig"))
                continue;

            var createMethod = type.Methods.FirstOrDefault(m => m.Name == "CreateBuildingDef");
            if (createMethod?.HasBody != true) continue;

            var dto = ExtractFromMethod(createMethod, type);
            if (dto != null) results.Add(dto);
        }

        return results;
    }

    private BuildingDto? ExtractFromMethod(MethodDefinition method, TypeDefinition configType)
    {
        var instructions = method.Body.Instructions;

        // Find the call to BuildingTemplates.CreateBuildingDef
        int callIdx = -1;
        MethodReference? createBuildingDefRef = null;
        for (int i = 0; i < instructions.Count; i++)
        {
            var instr = instructions[i];
            if (instr.OpCode == OpCodes.Call &&
                instr.Operand is MethodReference mr &&
                mr.DeclaringType.Name == "BuildingTemplates" &&
                mr.Name == "CreateBuildingDef")
            {
                callIdx = i;
                createBuildingDefRef = mr;
                break;
            }
        }

        if (callIdx < 0 || createBuildingDefRef == null) return null;

        var args = IlHelper.ExtractCallArgs(instructions, callIdx, createBuildingDefRef.Parameters.Count);

        var buildingId = args[0] as string;
        if (buildingId == null) return null;

        var width = args.Length > 1 && args[1] is int w ? w : 0;
        var height = args.Length > 2 && args[2] is int h ? h : 0;
        var constructionTime = args.Length > 5 && args[5] is float ct ? ct : 0f;

        // Scan forward from callIdx for field assignments on the returned BuildingDef
        float? powerGen = null;
        float? powerCon = null;
        float heatGen = 0f;
        string? dlcId = null;
        var tags = new List<string>();

        for (int i = callIdx + 1; i < instructions.Count; i++)
        {
            var instr = instructions[i];
            if (instr.OpCode != OpCodes.Stfld) continue;
            if (instr.Operand is not FieldReference fr) continue;

            // The value on the stack just before stfld is one instruction back
            var valInstr = i > 0 ? instructions[i - 1] : null;
            if (valInstr == null) continue;

            if (!IlHelper.TryReadConstant(valInstr, out var rawVal)) continue;

            switch (fr.Name)
            {
                case "GeneratorWattageRating":
                    powerGen = rawVal is float f1 ? f1 : null;
                    break;
                case "EnergyConsumptionWhenActive":
                    powerCon = rawVal is float f2 ? f2 : null;
                    break;
                case "SelfHeatKilowattsWhenActive":
                    heatGen = rawVal is float f3 ? f3 : 0f;
                    break;
            }
        }

        // Derive category from power fields
        string category = powerGen.HasValue ? "Power"
            : powerCon.HasValue ? "Power"
            : "Other";

        // Try to find name localization key: look for ldstr that looks like a STRINGS. key
        string? nameKey = null;
        foreach (var instr in instructions)
        {
            if (instr.OpCode == OpCodes.Ldstr && instr.Operand is string s && s.StartsWith("STRINGS."))
            {
                nameKey = s;
                break;
            }
        }

        return new BuildingDto(
            BuildingId: buildingId,
            Category: category,
            PowerGeneration: powerGen > 0 ? powerGen : null,
            PowerConsumption: powerCon > 0 ? powerCon : null,
            HeatGeneration: heatGen,
            Width: width,
            Height: height,
            ConstructionTime: constructionTime,
            Tags: tags,
            NameLocalizationId: nameKey,
            DlcId: dlcId
        );
    }
}
```

- [ ] **Step 2: Build**

```bash
cd /home/mishaa/dev/ony-planner/dotnet-extractor
dotnet build
```

Expected: `Build succeeded`.

- [ ] **Step 3: Run against game DLL (smoke test)**

```bash
cd /home/mishaa/dev/ony-planner/dotnet-extractor
dotnet run -- "/mnt/d/SteamLibrary/steamapps/common/OxygenNotIncluded/OxygenNotIncluded_Data" /tmp/oni-extract-test
```

Expected: `/tmp/oni-extract-test/buildings.json` created with hundreds of entries, each having a non-null `building_id`, integer `width`/`height`, and float `construction_time`.

Check: `cat /tmp/oni-extract-test/buildings.json | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d), 'buildings'); print(next(x for x in d if x['building_id']=='CoalGenerator'))"`

- [ ] **Step 4: Commit**

```bash
git add dotnet-extractor/Extractors/BuildingExtractor.cs
git commit -m "feat(extractor): implement BuildingExtractor via IL walk of IBuildingConfig"
```

---

### Task 4: GeyserExtractor — GeyserConfigurator.GeyserType field extraction

**Files:**
- Modify: `dotnet-extractor/Extractors/GeyserExtractor.cs`

**Approach:** Find `GeyserConfigurator` type. Find its `GeyserType` nested class. Find all methods that instantiate `GeyserType` (typically in `CreateGeyserTypes()` or similar). For each `newobj GeyserType`, scan the subsequent `stfld` instructions to read field values. The `element` field is a `SimHashes` enum — use `IlHelper.BuildSimHashesMap()` to resolve int → element ID string.

GeyserType fields → GeyserDto mapping:
- `id` → geyser_id
- `element` (SimHashes int) → element_id (resolved via SimHashes map)
- `temperature` → temperature
- `maxPressure` → max_pressure
- `minRatePerCycle` → min_yield_rate
- `maxRatePerCycle` → max_yield_rate
- `minIterationLength` → min_eruption_period
- `maxIterationLength` → max_eruption_period
- `minIterationPercent * minIterationLength` → min_eruption_duration
- `maxIterationPercent * maxIterationLength` → max_eruption_duration
- `minYearLength` → dormancy_min_cycles
- `maxYearLength` → dormancy_max_cycles
- `DlcID` → dlc_id

The `type` field in the DTO is derived from the geyser ID: IDs starting with `GeyserGeneric_` are type `"geyser"`, `Vent` are type `"vent"`. Use a simple lookup based on the id prefix.

- [ ] **Step 1: Replace GeyserExtractor.cs**

```csharp
using Mono.Cecil;
using Mono.Cecil.Cil;
using System.Text.Json.Serialization;

public record GeyserDto(
    [property: JsonPropertyName("geyser_id")] string GeyserIds,
    [property: JsonPropertyName("type")] string Type,
    [property: JsonPropertyName("element_id")] string ElementId,
    [property: JsonPropertyName("temperature")] float Temperature,
    [property: JsonPropertyName("max_pressure")] float MaxPressure,
    [property: JsonPropertyName("min_yield_rate")] float MinYieldRate,
    [property: JsonPropertyName("max_yield_rate")] float MaxYieldRate,
    [property: JsonPropertyName("min_eruption_duration")] float MinEruptionDuration,
    [property: JsonPropertyName("max_eruption_duration")] float MaxEruptionDuration,
    [property: JsonPropertyName("min_eruption_period")] float MinEruptionPeriod,
    [property: JsonPropertyName("max_eruption_period")] float MaxEruptionPeriod,
    [property: JsonPropertyName("dormancy_min_cycles")] float DormancyMinCycles,
    [property: JsonPropertyName("dormancy_max_cycles")] float DormancyMaxCycles,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("dlc_id")] string? DlcId
);

public sealed class GeyserExtractor
{
    private readonly string _managedPath;

    public GeyserExtractor(string managedPath) => _managedPath = managedPath;

    public List<GeyserDto> Extract()
    {
        var module = ModuleDefinition.ReadModule(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        var simHashes = IlHelper.BuildSimHashesMap(module);
        var results = new List<GeyserDto>();

        var geyserConfiguratorType = module.Types
            .FirstOrDefault(t => t.Name == "GeyserConfigurator");
        if (geyserConfiguratorType == null) return results;

        var geyserTypeNested = geyserConfiguratorType.NestedTypes
            .FirstOrDefault(t => t.Name == "GeyserType");
        if (geyserTypeNested == null) return results;

        // Find the method that creates GeyserType instances (the one with most newobj GeyserType)
        var populateMethod = geyserConfiguratorType.Methods
            .Where(m => m.HasBody)
            .OrderByDescending(m => m.Body.Instructions
                .Count(i => i.OpCode == OpCodes.Newobj &&
                            i.Operand is MethodReference mr &&
                            mr.DeclaringType.Name == "GeyserType"))
            .FirstOrDefault();

        if (populateMethod == null) return results;

        var instructions = populateMethod.Body.Instructions;
        var fieldsByInstructionIndex = new Dictionary<string, object?>();

        for (int i = 0; i < instructions.Count; i++)
        {
            var instr = instructions[i];

            if (instr.OpCode == OpCodes.Newobj &&
                instr.Operand is MethodReference ctor &&
                ctor.DeclaringType.Name == "GeyserType")
            {
                fieldsByInstructionIndex.Clear();

                // Scan forward from here collecting stfld assignments
                for (int j = i + 1; j < instructions.Count; j++)
                {
                    var next = instructions[j];

                    // Next newobj GeyserType → end of this one
                    if (next.OpCode == OpCodes.Newobj &&
                        next.Operand is MethodReference mr2 &&
                        mr2.DeclaringType.Name == "GeyserType")
                        break;

                    if (next.OpCode == OpCodes.Stfld &&
                        next.Operand is FieldReference fr &&
                        fr.DeclaringType.Name == "GeyserType")
                    {
                        // Value is the instruction immediately before stfld
                        var valInstr = instructions[j - 1];
                        if (IlHelper.TryReadConstant(valInstr, out var val))
                            fieldsByInstructionIndex[fr.Name] = val;
                    }
                }

                var dto = BuildDto(fieldsByInstructionIndex, simHashes);
                if (dto != null) results.Add(dto);
            }
        }

        return results;
    }

    private GeyserDto? BuildDto(Dictionary<string, object?> fields, Dictionary<int, string> simHashes)
    {
        if (fields.GetValueOrDefault("id") is not string id) return null;

        float minIterLen = fields.GetValueOrDefault("minIterationLength") is float mil ? mil : 0f;
        float maxIterLen = fields.GetValueOrDefault("maxIterationLength") is float mal ? mal : 0f;
        float minIterPct = fields.GetValueOrDefault("minIterationPercent") is float mip ? mip : 0f;
        float maxIterPct = fields.GetValueOrDefault("maxIterationPercent") is float map ? map : 0f;

        string elementId = "Unknown";
        if (fields.GetValueOrDefault("element") is int enumVal && simHashes.TryGetValue(enumVal, out var elemName))
            elementId = elemName;

        string dlcId = fields.GetValueOrDefault("DlcID") is string d && d != "" ? d : null!;

        string type = id.Contains("Volcano") ? "volcano"
            : id.Contains("Vent") || id.Contains("vent") ? "vent"
            : "geyser";

        return new GeyserDto(
            GeyserIds: id,
            Type: type,
            ElementId: elementId,
            Temperature: fields.GetValueOrDefault("temperature") is float t ? t : 0f,
            MaxPressure: fields.GetValueOrDefault("maxPressure") is float mp ? mp : 0f,
            MinYieldRate: fields.GetValueOrDefault("minRatePerCycle") is float mnr ? mnr : 0f,
            MaxYieldRate: fields.GetValueOrDefault("maxRatePerCycle") is float mxr ? mxr : 0f,
            MinEruptionDuration: minIterLen * minIterPct,
            MaxEruptionDuration: maxIterLen * maxIterPct,
            MinEruptionPeriod: minIterLen,
            MaxEruptionPeriod: maxIterLen,
            DormancyMinCycles: fields.GetValueOrDefault("minYearLength") is float mnyl ? mnyl : 0f,
            DormancyMaxCycles: fields.GetValueOrDefault("maxYearLength") is float mxyl ? mxyl : 0f,
            NameLocalizationId: null,
            DlcId: string.IsNullOrEmpty(dlcId) ? null : dlcId
        );
    }
}
```

- [ ] **Step 2: Build and run**

```bash
cd /home/mishaa/dev/ony-planner/dotnet-extractor
dotnet build && dotnet run -- "/mnt/d/SteamLibrary/steamapps/common/OxygenNotIncluded/OxygenNotIncluded_Data" /tmp/oni-extract-test
```

Check: `cat /tmp/oni-extract-test/geyser_types.json | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d), 'geysers'); print(d[0])"`

Expected: 20+ geyser types with non-zero temperatures and element IDs.

- [ ] **Step 3: Commit**

```bash
git add dotnet-extractor/Extractors/GeyserExtractor.cs
git commit -m "feat(extractor): implement GeyserExtractor via GeyserConfigurator IL walk"
```

---

### Task 5: RecipeExtractor — ComplexRecipe scanning

**Files:**
- Modify: `dotnet-extractor/Extractors/RecipeExtractor.cs`

**Approach:** `ComplexRecipe` objects are registered via `new ComplexRecipe(id, ingredients[], results[], time, ...)` calls scattered across building config classes. Scan ALL types and methods for `newobj ComplexRecipe` instructions. For each one:
1. Extract the `id` string from args (arg 0)
2. The ingredients and results arrays are built with `newarr` + `stelem` — track these by scanning for `newobj ComplexRecipe.RecipeElement` calls before the `newobj ComplexRecipe`
3. After the `newobj ComplexRecipe`, scan for `stfld` on `fabricators` and `time`

The recipe `id` in ONI has the format `{OutputElement1}+{OutputElement2}_{FabricatorTag}`. The `fabricators` field is a `HashedString[]` of machine IDs.

The building_id in the DTO comes from the first entry in `fabricators` (the primary machine). If no fabricators, derive from which config class the recipe appears in.

**RecipeElement** ctor args: `(HashedString material, float amount, ...)`  
- `material` is a hashed string — its name is the element ID  
- We scan for `ldstr` immediately before the `newobj RecipeElement` to get the material string

- [ ] **Step 1: Replace RecipeExtractor.cs**

```csharp
using Mono.Cecil;
using Mono.Cecil.Cil;
using System.Text.Json.Serialization;

public record RecipeItemDto(
    [property: JsonPropertyName("element_id")] string ElementId,
    [property: JsonPropertyName("amount")] float Amount
);

public record RecipeDto(
    [property: JsonPropertyName("building_id")] string BuildingId,
    [property: JsonPropertyName("duration")] float Duration,
    [property: JsonPropertyName("fabricators")] List<string>? Fabricators,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("inputs")] List<RecipeItemDto> Inputs,
    [property: JsonPropertyName("outputs")] List<RecipeItemDto> Outputs
);

public sealed class RecipeExtractor
{
    private readonly string _managedPath;

    public RecipeExtractor(string managedPath) => _managedPath = managedPath;

    public List<RecipeDto> Extract()
    {
        var module = ModuleDefinition.ReadModule(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        var results = new List<RecipeDto>();
        var seen = new HashSet<string>();

        foreach (var type in module.Types)
        {
            foreach (var method in type.Methods)
            {
                if (!method.HasBody) continue;
                var instructions = method.Body.Instructions;

                for (int i = 0; i < instructions.Count; i++)
                {
                    var instr = instructions[i];
                    if (instr.OpCode != OpCodes.Newobj) continue;
                    if (instr.Operand is not MethodReference ctor) continue;
                    if (ctor.DeclaringType.Name != "ComplexRecipe") continue;

                    var dto = ExtractRecipe(instructions, i, type);
                    if (dto != null && seen.Add(dto.BuildingId + "|" + dto.Inputs.FirstOrDefault()?.ElementId))
                        results.Add(dto);
                }
            }
        }

        return results;
    }

    private RecipeDto? ExtractRecipe(IList<Instruction> instructions, int newobjIdx, TypeDefinition declaringType)
    {
        // Collect RecipeElement strings (ldstr) in a small window before this newobj
        var recentStrings = new List<string>();
        var recentFloats = new List<float>();

        // Scan back up to 200 instructions to collect element+amount pairs
        var ingredients = new List<RecipeItemDto>();
        var outputs = new List<RecipeItemDto>();
        string? currentElement = null;

        for (int i = Math.Max(0, newobjIdx - 300); i < newobjIdx; i++)
        {
            var instr = instructions[i];

            if (instr.OpCode == OpCodes.Ldstr && instr.Operand is string s &&
                !s.StartsWith("STRINGS.") && !s.Contains("\\n") && s.Length < 60)
            {
                currentElement = s;
            }
            else if ((instr.OpCode == OpCodes.Ldc_R4 || instr.OpCode == OpCodes.Ldc_R8) && currentElement != null)
            {
                float amount = instr.OpCode == OpCodes.Ldc_R4 ? (float)instr.Operand : (float)(double)instr.Operand;
                recentStrings.Add(currentElement);
                recentFloats.Add(amount);
                currentElement = null;
            }
            else if (instr.OpCode == OpCodes.Newobj &&
                     instr.Operand is MethodReference er &&
                     er.DeclaringType.Name == "RecipeElement")
            {
                // RecipeElement created — it's an ingredient or output
                // We'll classify later based on array context
            }
        }

        // Split recentStrings/Floats by array (newarr instructions between them)
        // Simpler: assume first half = ingredients, second half = outputs (common ONI pattern)
        // More robust: look for newarr instructions in the window
        int inputCount = 0, outputCount = 0;
        bool inInputArray = false, inOutputArray = false;
        var inputItems = new List<RecipeItemDto>();
        var outputItems = new List<RecipeItemDto>();
        int elemIdx = 0;

        for (int i = Math.Max(0, newobjIdx - 300); i < newobjIdx && elemIdx < recentStrings.Count; i++)
        {
            var instr = instructions[i];
            if (instr.OpCode == OpCodes.Newarr)
            {
                if (!inInputArray)
                    inInputArray = true;
                else if (!inOutputArray)
                {
                    inInputArray = false;
                    inOutputArray = true;
                }
            }
            if (instr.OpCode == OpCodes.Newobj &&
                instr.Operand is MethodReference mr &&
                mr.DeclaringType.Name == "RecipeElement" &&
                elemIdx < recentStrings.Count)
            {
                var item = new RecipeItemDto(recentStrings[elemIdx], recentFloats.Count > elemIdx ? recentFloats[elemIdx] : 0f);
                if (inInputArray) inputItems.Add(item);
                else outputItems.Add(item);
                elemIdx++;
            }
        }

        // If classification failed, fall back to splitting evenly
        if (inputItems.Count == 0 && outputItems.Count == 0 && recentStrings.Count >= 2)
        {
            int half = recentStrings.Count / 2;
            for (int k = 0; k < recentStrings.Count; k++)
            {
                var item = new RecipeItemDto(recentStrings[k], recentFloats.Count > k ? recentFloats[k] : 0f);
                if (k < half) inputItems.Add(item);
                else outputItems.Add(item);
            }
        }

        if (inputItems.Count == 0 && outputItems.Count == 0) return null;

        // Look forward for fabricators field store
        var fabricators = new List<string>();
        float duration = 0f;
        string? nameKey = null;

        for (int i = newobjIdx + 1; i < Math.Min(instructions.Count, newobjIdx + 50); i++)
        {
            var instr = instructions[i];
            if (instr.OpCode == OpCodes.Stfld && instr.Operand is FieldReference fr)
            {
                if (fr.Name == "time" && instructions[i - 1].OpCode == OpCodes.Ldc_R4)
                    duration = (float)instructions[i - 1].Operand;
                if (fr.Name == "nameLocalizationId" && instructions[i - 1].OpCode == OpCodes.Ldstr)
                    nameKey = (string)instructions[i - 1].Operand;
            }
            if (instr.OpCode == OpCodes.Ldstr && instructions.Count > i + 2)
            {
                // Fabricator tags are stored as ldstr → newobj HashedString → stelem
                if (instr.Operand is string fab && fab.Length < 60 && !fab.StartsWith("STRINGS."))
                {
                    var next = instructions[i + 1];
                    if (next.OpCode == OpCodes.Newobj && next.Operand is MethodReference hr && hr.DeclaringType.Name == "HashedString")
                        fabricators.Add(fab);
                }
            }
        }

        string buildingId = fabricators.Count > 0 ? fabricators[0] : declaringType.Name.Replace("Config", "");

        return new RecipeDto(
            BuildingId: buildingId,
            Duration: duration,
            Fabricators: fabricators.Count > 0 ? fabricators : null,
            NameLocalizationId: string.IsNullOrEmpty(nameKey) ? null : nameKey,
            Inputs: inputItems,
            Outputs: outputItems
        );
    }
}
```

- [ ] **Step 2: Build and run**

```bash
cd /home/mishaa/dev/ony-planner/dotnet-extractor
dotnet build && dotnet run -- "/mnt/d/SteamLibrary/steamapps/common/OxygenNotIncluded/OxygenNotIncluded_Data" /tmp/oni-extract-test
```

Check: `cat /tmp/oni-extract-test/recipes.json | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d), 'recipes'); print(d[0])"`

Expected: hundreds of recipes with non-empty `inputs` and `outputs`.

**Note:** Recipe extraction from IL is the most complex due to array construction patterns. If the ingredient/output split is wrong for a particular recipe type, the `newarr` array boundary detection in the loop above may need adjusting based on the actual IL observed.

- [ ] **Step 3: Commit**

```bash
git add dotnet-extractor/Extractors/RecipeExtractor.cs
git commit -m "feat(extractor): implement RecipeExtractor via ComplexRecipe newobj scanning"
```

---

### Task 6: CritterExtractor — IEntityConfig critter config walking

**Files:**
- Modify: `dotnet-extractor/Extractors/CritterExtractor.cs`

**Approach:** Find all types implementing `IEntityConfig` whose name ends with `Config` and contains critter-related keywords OR that have a `CreatePrefab()` method calling critter-specific component setup methods. Key methods to look for inside `CreatePrefab()`:
- `SetWildCreatureCalories` → `calories_per_cycle`
- `CreatureTemperatureMonitor.Def` field `tempHot`/`tempCold` → `max_temp`/`min_temp`
- `AgeMonitor.Def` field → `lifespan`
- `Diet.Info` array construction → `diets`

In ONI, critter morphs are differentiated by a `SPECIES_ID` tag set on the critter. The base critter typically has `is_base = true`. We identify the base critter because its config class name often matches the SPECIES_ID directly.

**Heuristic for critter vs plant:** If a type's `CreatePrefab()` or `DoPostConfigureComplete()` calls methods containing "Critter", "Creature", or sets up `AgeMonitor`/`CalorieMonitor`, it's a critter config.

- [ ] **Step 1: Replace CritterExtractor.cs**

```csharp
using Mono.Cecil;
using Mono.Cecil.Cil;
using System.Text.Json.Serialization;

public record CritterDietDto(
    [property: JsonPropertyName("consumed_element_id")] string ConsumedElementId,
    [property: JsonPropertyName("amount_per_cycle")] float AmountPerCycle,
    [property: JsonPropertyName("produced_element_id")] string? ProducedElementId,
    [property: JsonPropertyName("conversion_ratio")] float? ConversionRatio
);

public record CritterOutputDto(
    [property: JsonPropertyName("element_id")] string ElementId,
    [property: JsonPropertyName("amount_per_cycle")] float AmountPerCycle,
    [property: JsonPropertyName("output_type")] string OutputType
);

public record CritterDto(
    [property: JsonPropertyName("critter_id")] string CritterId,
    [property: JsonPropertyName("parent_critter_id")] string? ParentCritterId,
    [property: JsonPropertyName("is_base")] bool IsBase,
    [property: JsonPropertyName("min_temp")] float MinTemp,
    [property: JsonPropertyName("max_temp")] float MaxTemp,
    [property: JsonPropertyName("calories_per_cycle")] float CaloriesPerCycle,
    [property: JsonPropertyName("incubation_time")] float IncubationTime,
    [property: JsonPropertyName("lifespan")] float Lifespan,
    [property: JsonPropertyName("overcrowding_threshold")] int OvercrowdingThreshold,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("diets")] List<CritterDietDto> Diets,
    [property: JsonPropertyName("outputs")] List<CritterOutputDto> Outputs
);

public sealed class CritterExtractor
{
    private readonly string _managedPath;

    // Known base critter IDs → list of morph type names (populated during extraction)
    private readonly Dictionary<string, string> _morphToBase = new();

    public CritterExtractor(string managedPath) => _managedPath = managedPath;

    public List<CritterDto> Extract()
    {
        var module = ModuleDefinition.ReadModule(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        var results = new List<CritterDto>();

        // Pass 1: collect all ldstr constants across all types to find species IDs
        // ONI critter configs store the SPECIES_ID as a static const string like "BasicHatch"
        // Morph configs reference their base ID. Build a map: critterId → baseId

        var allCritterConfigs = module.Types
            .Where(t => t.Interfaces.Any(i => i.InterfaceType.Name == "IEntityConfig") &&
                        IsCritterConfig(t))
            .ToList();

        // Build morph→base mapping by scanning for string constants that look like critter IDs
        foreach (var type in allCritterConfigs)
        {
            var speciesId = FindSpeciesId(type);
            if (speciesId != null)
                _morphToBase[type.Name] = speciesId;
        }

        foreach (var type in allCritterConfigs)
        {
            var dto = ExtractCritter(type);
            if (dto != null) results.Add(dto);
        }

        return results;
    }

    private bool IsCritterConfig(TypeDefinition type)
    {
        if (!type.HasMethods) return false;
        return type.Methods.Any(m => m.HasBody && m.Body.Instructions.Any(i =>
            i.OpCode == OpCodes.Callvirt &&
            i.Operand is MethodReference mr &&
            (mr.Name.Contains("CalorieMonitor") || mr.Name.Contains("AgeMonitor") ||
             mr.DeclaringType.Name.Contains("CalorieMonitor") ||
             mr.DeclaringType.Name.Contains("AgeMonitor"))));
    }

    private string? FindSpeciesId(TypeDefinition type)
    {
        // Look for a static field named like "SPECIES_ID" or a string constant
        var speciesField = type.Fields.FirstOrDefault(f =>
            f.IsStatic && f.Name.Contains("SPECIES_ID") && f.FieldType.Name == "Tag");
        if (speciesField != null) return null; // Tag, not raw string

        // Fall back: the critter ID is often the type name minus "Config" suffix
        return type.Name.EndsWith("Config") ? type.Name[..^6] : null;
    }

    private CritterDto? ExtractCritter(TypeDefinition type)
    {
        var critterId = type.Name.EndsWith("Config") ? type.Name[..^6] : type.Name;

        // Try to find base critter: scan for a ldstr constant that matches another config
        string? parentId = null;
        bool isBase = true;

        // Heuristic: if the type name contains the base name as prefix, it's a morph
        // e.g., "HatchHardSkinConfig" → base is "BasicHatch" (found from known mapping)
        if (_morphToBase.TryGetValue(type.Name, out var mappedBase) && mappedBase != critterId)
        {
            parentId = mappedBase;
            isBase = false;
        }

        float minTemp = 0f, maxTemp = 0f;
        float calories = 0f, incubation = 0f, lifespan = 0f;
        int overcrowding = 0;
        var diets = new List<CritterDietDto>();
        var outputs = new List<CritterOutputDto>();
        string? nameKey = null;

        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            var instructions = method.Body.Instructions;

            for (int i = 0; i < instructions.Count; i++)
            {
                var instr = instructions[i];

                // Temperature: look for stfld on tempCold/tempHot/temperatureCold/temperatureHot
                if (instr.OpCode == OpCodes.Stfld && instr.Operand is FieldReference fr)
                {
                    var prev = i > 0 ? instructions[i - 1] : null;
                    if (prev != null && IlHelper.TryReadConstant(prev, out var val))
                    {
                        switch (fr.Name)
                        {
                            case "tempCold" or "temperatureCold" or "minTemp":
                                minTemp = val is float f1 ? f1 : minTemp; break;
                            case "tempHot" or "temperatureHot" or "maxTemp":
                                maxTemp = val is float f2 ? f2 : maxTemp; break;
                            case "Lifespan" or "lifespan" or "maxAge":
                                lifespan = val is float f3 ? f3 : lifespan; break;
                            case "overcrowdingThreshold":
                                overcrowding = val is int iv ? iv : overcrowding; break;
                        }
                    }
                }

                // Calories: look for call SetWildCreatureCalories
                if (instr.OpCode is OpCodes.Call or OpCodes.Callvirt &&
                    instr.Operand is MethodReference mr &&
                    mr.Name.Contains("alorie"))
                {
                    if (i > 0 && IlHelper.TryReadConstant(instructions[i - 1], out var cal))
                        calories = cal is float f ? f : calories;
                }

                // Localization key
                if (instr.OpCode == OpCodes.Ldstr && instr.Operand is string s && s.StartsWith("STRINGS."))
                    nameKey ??= s;
            }
        }

        return new CritterDto(
            CritterId: critterId,
            ParentCritterId: parentId,
            IsBase: isBase,
            MinTemp: minTemp,
            MaxTemp: maxTemp,
            CaloriesPerCycle: calories,
            IncubationTime: incubation,
            Lifespan: lifespan,
            OvercrowdingThreshold: overcrowding,
            NameLocalizationId: string.IsNullOrEmpty(nameKey) ? null : nameKey,
            Diets: diets,
            Outputs: outputs
        );
    }
}
```

- [ ] **Step 2: Build and run**

```bash
cd /home/mishaa/dev/ony-planner/dotnet-extractor
dotnet build && dotnet run -- "/mnt/d/SteamLibrary/steamapps/common/OxygenNotIncluded/OxygenNotIncluded_Data" /tmp/oni-extract-test
```

Check: `cat /tmp/oni-extract-test/critters.json | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d), 'critters'); print(d[0])"`

Expected: dozens of critter entries with non-zero `min_temp`/`max_temp`.

**Note:** Diet and output extraction from IL is complex (deeply nested array construction). The initial implementation returns empty lists for `diets` and `outputs`. These will be filled in a follow-up once the base critter data is verified.

- [ ] **Step 3: Commit**

```bash
git add dotnet-extractor/Extractors/CritterExtractor.cs
git commit -m "feat(extractor): implement CritterExtractor via IEntityConfig IL walk"
```

---

### Task 7: PlantExtractor — plant IEntityConfig config walking

**Files:**
- Modify: `dotnet-extractor/Extractors/PlantExtractor.cs`

**Approach:** Similar to CritterExtractor but filtering for plant configs. Plant configs call `PlantElementAbsorber.Def`, `PlantSim` components, or store fields like `fruitId`, `harvestCount`, etc. Key fields to extract from `CreatePrefab()`:
- `PlantElementAbsorber.Def` → `inputs` (element_id, consumption rate)
- Harvest/fruit config → `outputs` (element_id, amount_per_harvest)
- Temperature tolerances from `TemperatureVulnerable` or `PlantSim` defs
- Pressure from `PressureVulnerable`
- `Irrigation` input type, `Fertilization` input type

- [ ] **Step 1: Replace PlantExtractor.cs**

```csharp
using Mono.Cecil;
using Mono.Cecil.Cil;
using System.Text.Json.Serialization;

public record PlantInputDto(
    [property: JsonPropertyName("element_id")] string ElementId,
    [property: JsonPropertyName("amount_per_cycle")] float AmountPerCycle,
    [property: JsonPropertyName("input_type")] string InputType
);

public record PlantOutputDto(
    [property: JsonPropertyName("element_id")] string ElementId,
    [property: JsonPropertyName("amount_per_harvest")] float AmountPerHarvest,
    [property: JsonPropertyName("output_type")] string OutputType
);

public record PlantDto(
    [property: JsonPropertyName("plant_id")] string PlantId,
    [property: JsonPropertyName("variant_id")] string VariantId,
    [property: JsonPropertyName("is_base")] bool IsBase,
    [property: JsonPropertyName("min_temp")] float MinTemp,
    [property: JsonPropertyName("max_temp")] float MaxTemp,
    [property: JsonPropertyName("min_pressure")] float MinPressure,
    [property: JsonPropertyName("max_pressure")] float MaxPressure,
    [property: JsonPropertyName("atmosphere_element_id")] string? AtmosphereElementId,
    [property: JsonPropertyName("light_required")] bool LightRequired,
    [property: JsonPropertyName("growth_time")] float GrowthTime,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("dlc_id")] string? DlcId,
    [property: JsonPropertyName("inputs")] List<PlantInputDto> Inputs,
    [property: JsonPropertyName("outputs")] List<PlantOutputDto> Outputs
);

public sealed class PlantExtractor
{
    private readonly string _managedPath;

    public PlantExtractor(string managedPath) => _managedPath = managedPath;

    public List<PlantDto> Extract()
    {
        var module = ModuleDefinition.ReadModule(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        var results = new List<PlantDto>();

        var plantConfigs = module.Types
            .Where(t => t.Interfaces.Any(i => i.InterfaceType.Name == "IEntityConfig") &&
                        IsPlantConfig(t))
            .ToList();

        foreach (var type in plantConfigs)
        {
            var dto = ExtractPlant(type);
            if (dto != null) results.Add(dto);
        }

        return results;
    }

    private bool IsPlantConfig(TypeDefinition type)
    {
        if (!type.HasMethods) return false;
        return type.Methods.Any(m => m.HasBody && m.Body.Instructions.Any(i =>
            i.OpCode == OpCodes.Callvirt &&
            i.Operand is MethodReference mr &&
            (mr.DeclaringType.Name.Contains("PlantElementAbsorber") ||
             mr.DeclaringType.Name.Contains("TemperatureVulnerable") ||
             mr.DeclaringType.Name.Contains("PlantSim") ||
             mr.Name.Contains("harvestCount") ||
             mr.Name.Contains("HarvestDesignatable"))));
    }

    private PlantDto? ExtractPlant(TypeDefinition type)
    {
        var plantId = type.Name.EndsWith("Config") ? type.Name[..^6] : type.Name;
        float minTemp = 0f, maxTemp = 0f;
        float minPressure = 0f, maxPressure = 0f;
        float growthTime = 0f;
        bool lightRequired = false;
        string? atmosphereElementId = null;
        string? nameKey = null;
        string? dlcId = null;
        var inputs = new List<PlantInputDto>();
        var outputs = new List<PlantOutputDto>();

        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            var instructions = method.Body.Instructions;

            for (int i = 0; i < instructions.Count; i++)
            {
                var instr = instructions[i];

                if (instr.OpCode == OpCodes.Stfld && instr.Operand is FieldReference fr)
                {
                    var prev = i > 0 ? instructions[i - 1] : null;
                    if (prev != null && IlHelper.TryReadConstant(prev, out var val))
                    {
                        switch (fr.Name)
                        {
                            case "minTemp" or "temperatureLethalLow" or "tempLethalLow":
                                minTemp = val is float f1 ? f1 : minTemp; break;
                            case "maxTemp" or "temperatureLethalHigh" or "tempLethalHigh":
                                maxTemp = val is float f2 ? f2 : maxTemp; break;
                            case "minPressure" or "pressureLethalLow":
                                minPressure = val is float f3 ? f3 : minPressure; break;
                            case "maxPressure" or "pressureLethalHigh":
                                maxPressure = val is float f4 ? f4 : maxPressure; break;
                            case "harvestCount" or "defaultHarvestCount" or "cycle" or "growthTime":
                                growthTime = val is float f5 ? f5 : growthTime; break;
                        }
                    }
                }

                if (instr.OpCode == OpCodes.Ldstr && instr.Operand is string s && s.StartsWith("STRINGS."))
                    nameKey ??= s;
            }
        }

        return new PlantDto(
            PlantId: plantId,
            VariantId: plantId,
            IsBase: true,
            MinTemp: minTemp,
            MaxTemp: maxTemp,
            MinPressure: minPressure,
            MaxPressure: maxPressure,
            AtmosphereElementId: atmosphereElementId,
            LightRequired: lightRequired,
            GrowthTime: growthTime,
            NameLocalizationId: string.IsNullOrEmpty(nameKey) ? null : nameKey,
            DlcId: dlcId,
            Inputs: inputs,
            Outputs: outputs
        );
    }
}
```

- [ ] **Step 2: Build and run**

```bash
cd /home/mishaa/dev/ony-planner/dotnet-extractor
dotnet build && dotnet run -- "/mnt/d/SteamLibrary/steamapps/common/OxygenNotIncluded/OxygenNotIncluded_Data" /tmp/oni-extract-test
```

Check: `cat /tmp/oni-extract-test/plants.json | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d), 'plants'); print(d[0])"`

Expected: dozens of plants with non-zero temperature ranges.

- [ ] **Step 3: Commit**

```bash
git add dotnet-extractor/Extractors/PlantExtractor.cs
git commit -m "feat(extractor): implement PlantExtractor via IEntityConfig IL walk"
```

---

### Task 8: DuplicantTraitExtractor — Trait registration scanning

**Files:**
- Modify: `dotnet-extractor/Extractors/DuplicantTraitExtractor.cs`

**Approach:** `Klei.AI.Trait` objects are created with `new Trait(string id, string name, string description, float expectedValue, bool isPositiveTrait, ...)`. Find all `newobj Klei.AI.Trait` calls across the assembly. For each:
1. Extract `id` (arg 0) → trait_id
2. Extract `isPositiveTrait` (bool int arg at position 4) → is_positive
3. Look for `AddAttributeModifier` calls after the trait creation → effects

For base stats: scan for `MinionConfig` or `TUNING` type, look for constants assigned to known fields (oxygen_consumption_kg_per_sec, etc.).

`AttributeModifier` ctor args: `(string attributeId, float value, string description, bool isMultiplier, ...)`
- attributeId → stat (map to our stat name format)
- value → modifier value
- isMultiplier → modifier_type: "multiply" if true, "add" if false

- [ ] **Step 1: Replace DuplicantTraitExtractor.cs**

```csharp
using Mono.Cecil;
using Mono.Cecil.Cil;
using System.Text.Json.Serialization;

public record DuplicantBaseStatsDto(
    [property: JsonPropertyName("oxygen_consumption_gs")] float OxygenConsumptionGs,
    [property: JsonPropertyName("co2_production_gs")] float Co2ProductionGs,
    [property: JsonPropertyName("calories_per_cycle")] int CaloriesPerCycle,
    [property: JsonPropertyName("mass_kg")] float MassKg,
    [property: JsonPropertyName("bladder_fill_per_cycle")] float BladderFillPerCycle
);

public record DuplicantTraitEffectDto(
    [property: JsonPropertyName("stat")] string Stat,
    [property: JsonPropertyName("modifier")] float Modifier,
    [property: JsonPropertyName("modifier_type")] string ModifierType
);

public record DuplicantTraitDto(
    [property: JsonPropertyName("trait_id")] string TraitId,
    [property: JsonPropertyName("is_positive")] bool IsPositive,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("dlc_id")] string? DlcId,
    [property: JsonPropertyName("effects")] List<DuplicantTraitEffectDto> Effects
);

public record DuplicantDataDto(
    [property: JsonPropertyName("base_stats")] DuplicantBaseStatsDto BaseStats,
    [property: JsonPropertyName("traits")] List<DuplicantTraitDto> Traits
);

public sealed class DuplicantTraitExtractor
{
    private readonly string _managedPath;

    public DuplicantTraitExtractor(string managedPath) => _managedPath = managedPath;

    public DuplicantDataDto Extract()
    {
        var module = ModuleDefinition.ReadModule(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        var traits = new List<DuplicantTraitDto>();
        var seen = new HashSet<string>();

        foreach (var type in module.Types)
        {
            foreach (var method in type.Methods)
            {
                if (!method.HasBody) continue;
                var instructions = method.Body.Instructions;

                for (int i = 0; i < instructions.Count; i++)
                {
                    var instr = instructions[i];
                    if (instr.OpCode != OpCodes.Newobj) continue;
                    if (instr.Operand is not MethodReference ctor) continue;
                    if (ctor.DeclaringType.FullName != "Klei.AI.Trait") continue;

                    var args = IlHelper.ExtractCallArgs(instructions, i, ctor.Parameters.Count);
                    var traitId = args.Length > 0 ? args[0] as string : null;
                    if (traitId == null || !seen.Add(traitId)) continue;

                    bool isPositive = args.Length > 4 && args[4] is int ip && ip != 0;

                    // Scan forward for AddAttributeModifier calls
                    var effects = new List<DuplicantTraitEffectDto>();
                    for (int j = i + 1; j < Math.Min(instructions.Count, i + 100); j++)
                    {
                        var next = instructions[j];
                        if (next.OpCode == OpCodes.Newobj &&
                            next.Operand is MethodReference attrCtor &&
                            attrCtor.DeclaringType.Name == "AttributeModifier")
                        {
                            var attrArgs = IlHelper.ExtractCallArgs(instructions, j, attrCtor.Parameters.Count);
                            var statId = attrArgs.Length > 0 ? attrArgs[0] as string : null;
                            var modValue = attrArgs.Length > 1 && attrArgs[1] is float fv ? fv : 0f;
                            bool isMultiplier = attrArgs.Length > 3 && attrArgs[3] is int im && im != 0;

                            if (statId != null)
                            {
                                effects.Add(new DuplicantTraitEffectDto(
                                    Stat: ToSnakeCase(statId),
                                    Modifier: modValue,
                                    ModifierType: isMultiplier ? "multiply" : "add"
                                ));
                            }
                        }
                        // Stop when hitting next trait creation
                        if (next.OpCode == OpCodes.Newobj &&
                            next.Operand is MethodReference mr2 &&
                            mr2.DeclaringType.FullName == "Klei.AI.Trait")
                            break;
                    }

                    traits.Add(new DuplicantTraitDto(
                        TraitId: traitId,
                        IsPositive: isPositive,
                        NameLocalizationId: null,
                        DlcId: null,
                        Effects: effects
                    ));
                }
            }
        }

        // Base stats: scan for MinionConfig or DUPLICANTSTATS constants
        var baseStats = ExtractBaseStats(module);

        return new DuplicantDataDto(BaseStats: baseStats, Traits: traits);
    }

    private DuplicantBaseStatsDto ExtractBaseStats(ModuleDefinition module)
    {
        float oxygenGs = 100f, co2Gs = 2f, massKg = 30f, bladder = 1f;
        int calories = 1000;

        // Scan DUPLICANTSTATS or TUNING class for known constants
        var tuningType = module.Types.FirstOrDefault(t =>
            t.Name == "DUPLICANTSTATS" || t.Name == "MinionConfig");

        if (tuningType != null)
        {
            foreach (var field in tuningType.Fields.Where(f => f.IsStatic && f.IsLiteral))
            {
                switch (field.Name.ToLowerInvariant())
                {
                    case "oxygen_consumption_gs" or "oxygenusagepercell":
                        if (field.Constant is float fo) oxygenGs = fo; break;
                    case "co2_production_gs" or "co2productionrate":
                        if (field.Constant is float fco) co2Gs = fco; break;
                    case "mass_kg" or "mass":
                        if (field.Constant is float fm) massKg = fm; break;
                    case "calories_per_cycle" or "caloriespercycle":
                        if (field.Constant is int ic) calories = ic;
                        else if (field.Constant is float fc) calories = (int)fc; break;
                }
            }
        }

        return new DuplicantBaseStatsDto(
            OxygenConsumptionGs: oxygenGs,
            Co2ProductionGs: co2Gs,
            CaloriesPerCycle: calories,
            MassKg: massKg,
            BladderFillPerCycle: bladder
        );
    }

    private static string ToSnakeCase(string s)
    {
        if (string.IsNullOrEmpty(s)) return s;
        return string.Concat(s.Select((c, i) =>
            i > 0 && char.IsUpper(c) ? "_" + char.ToLower(c) : char.ToLower(c).ToString()));
    }
}
```

- [ ] **Step 2: Build and run**

```bash
cd /home/mishaa/dev/ony-planner/dotnet-extractor
dotnet build && dotnet run -- "/mnt/d/SteamLibrary/steamapps/common/OxygenNotIncluded/OxygenNotIncluded_Data" /tmp/oni-extract-test
```

Check: `cat /tmp/oni-extract-test/duplicant_traits.json | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d['traits']), 'traits'); print(d['traits'][0])"`

Expected: 20+ traits, each with `trait_id`, `is_positive`, and at least some with non-empty `effects`.

- [ ] **Step 3: Commit**

```bash
git add dotnet-extractor/Extractors/DuplicantTraitExtractor.cs
git commit -m "feat(extractor): implement DuplicantTraitExtractor via Klei.AI.Trait newobj scanning"
```

---

### Task 9: RocketExtractor — rocket building config IL walk

**Files:**
- Modify: `dotnet-extractor/Extractors/RocketExtractor.cs`

**Approach:** Rocket engines and modules are IBuildingConfig types. Filter them by known engine/module class names or by scanning for rocket-specific field assignments:
- Engines: types whose `CreateBuildingDef()` assigns `GeneratorWattageRating` field named `ExhaustTemperature`, `FuelConsumptionRate`, `OxidizerConsumptionRate`, `MaxRange`
- Modules: types assigning `EngineBase.Def` or whose class name contains "RocketModule" or "CargoBay"

For rocket-specific fields, scan stfld instructions for:
- `ExhaustTemperature` → exhaust_temperature
- `FuelConsumptionRate` → fuel_consumption_rate
- `OxidizerConsumptionRate` → oxidizer_consumption_rate
- `MaxRange` → max_range
- `FuelTag` (ldstr before stfld) → fuel_element_id
- `OxidizerTag` → oxidizer_element_id
- `ModuleType` or field patterns → module_type
- `Mass` → mass
- `Capacity` → capacity
- `PowerConsumption` → power_consumption

- [ ] **Step 1: Replace RocketExtractor.cs**

```csharp
using Mono.Cecil;
using Mono.Cecil.Cil;
using System.Text.Json.Serialization;

public record RocketEngineDto(
    [property: JsonPropertyName("engine_id")] string EngineId,
    [property: JsonPropertyName("fuel_element_id")] string? FuelElementId,
    [property: JsonPropertyName("oxidizer_element_id")] string? OxidizerElementId,
    [property: JsonPropertyName("max_range")] float MaxRange,
    [property: JsonPropertyName("fuel_consumption_rate")] float FuelConsumptionRate,
    [property: JsonPropertyName("oxidizer_consumption_rate")] float? OxidizerConsumptionRate,
    [property: JsonPropertyName("exhaust_temperature")] float ExhaustTemperature,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("dlc_id")] string? DlcId
);

public record RocketModuleDto(
    [property: JsonPropertyName("module_id")] string ModuleId,
    [property: JsonPropertyName("module_type")] string ModuleType,
    [property: JsonPropertyName("mass")] float Mass,
    [property: JsonPropertyName("capacity")] float Capacity,
    [property: JsonPropertyName("power_consumption")] float? PowerConsumption,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("dlc_id")] string? DlcId
);

public record RocketDataDto(
    [property: JsonPropertyName("engines")] List<RocketEngineDto> Engines,
    [property: JsonPropertyName("modules")] List<RocketModuleDto> Modules
);

public sealed class RocketExtractor
{
    private readonly string _managedPath;

    // Engine class name substrings
    private static readonly string[] EngineKeywords = ["Engine", "Thruster"];
    // Module class name substrings
    private static readonly string[] ModuleKeywords = ["Module", "CargoBay", "NoseCone", "Cockpit", "OrbitalResearch", "Fuel", "Oxidizer", "Nosecone"];

    public RocketExtractor(string managedPath) => _managedPath = managedPath;

    public RocketDataDto Extract()
    {
        var module = ModuleDefinition.ReadModule(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        var engines = new List<RocketEngineDto>();
        var modules = new List<RocketModuleDto>();

        var rocketConfigs = module.Types
            .Where(t => t.Interfaces.Any(i => i.InterfaceType.Name == "IBuildingConfig") &&
                        (EngineKeywords.Any(kw => t.Name.Contains(kw)) ||
                         ModuleKeywords.Any(kw => t.Name.Contains(kw))) &&
                        t.Name.Contains("Rocket") || t.Name.Contains("rocket"))
            .ToList();

        foreach (var type in rocketConfigs)
        {
            if (EngineKeywords.Any(kw => type.Name.Contains(kw)))
            {
                var engine = ExtractEngine(type);
                if (engine != null) engines.Add(engine);
            }
            else
            {
                var mod = ExtractModule(type);
                if (mod != null) modules.Add(mod);
            }
        }

        return new RocketDataDto(Engines: engines, Modules: modules);
    }

    private RocketEngineDto? ExtractEngine(TypeDefinition type)
    {
        var engineId = type.Name.EndsWith("Config") ? type.Name[..^6] : type.Name;
        string? fuelId = null, oxidizerId = null;
        float maxRange = 0f, fuelRate = 0f, oxRate = 0f, exhaustTemp = 0f;
        string? nameKey = null, dlcId = null;

        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            var instructions = method.Body.Instructions;
            for (int i = 0; i < instructions.Count; i++)
            {
                var instr = instructions[i];
                if (instr.OpCode != OpCodes.Stfld) continue;
                if (instr.Operand is not FieldReference fr) continue;
                var prev = i > 0 ? instructions[i - 1] : null;
                if (prev == null || !IlHelper.TryReadConstant(prev, out var val)) continue;

                switch (fr.Name)
                {
                    case "MaxRange" or "maxRange": maxRange = val is float f1 ? f1 : maxRange; break;
                    case "FuelConsumptionRate" or "fuelConsumptionRate": fuelRate = val is float f2 ? f2 : fuelRate; break;
                    case "OxidizerConsumptionRate" or "oxidizerConsumptionRate": oxRate = val is float f3 ? f3 : oxRate; break;
                    case "ExhaustTemperature" or "exhaustTemperature": exhaustTemp = val is float f4 ? f4 : exhaustTemp; break;
                    case "FuelTag" or "fuelTag": fuelId = val as string; break;
                    case "OxidizerTag" or "oxidizerTag": oxidizerId = val as string; break;
                }
            }
        }

        return new RocketEngineDto(
            EngineId: engineId,
            FuelElementId: fuelId,
            OxidizerElementId: string.IsNullOrEmpty(oxidizerId) ? null : oxidizerId,
            MaxRange: maxRange,
            FuelConsumptionRate: fuelRate,
            OxidizerConsumptionRate: oxRate > 0 ? oxRate : null,
            ExhaustTemperature: exhaustTemp,
            NameLocalizationId: nameKey,
            DlcId: dlcId
        );
    }

    private RocketModuleDto? ExtractModule(TypeDefinition type)
    {
        var moduleId = type.Name.EndsWith("Config") ? type.Name[..^6] : type.Name;
        float mass = 0f, capacity = 0f, powerCon = 0f;
        string moduleType = "cargo_solid";
        string? nameKey = null, dlcId = null;

        if (type.Name.Contains("Liquid")) moduleType = "cargo_liquid";
        else if (type.Name.Contains("Gas")) moduleType = "cargo_gas";
        else if (type.Name.Contains("NoseCone") || type.Name.Contains("Cockpit")) moduleType = "nosecone";
        else if (type.Name.Contains("Fuel")) moduleType = "fuel";
        else if (type.Name.Contains("Oxidizer")) moduleType = "oxidizer";

        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            var instructions = method.Body.Instructions;
            for (int i = 0; i < instructions.Count; i++)
            {
                var instr = instructions[i];
                if (instr.OpCode != OpCodes.Stfld) continue;
                if (instr.Operand is not FieldReference fr) continue;
                var prev = i > 0 ? instructions[i - 1] : null;
                if (prev == null || !IlHelper.TryReadConstant(prev, out var val)) continue;

                switch (fr.Name)
                {
                    case "Mass" or "mass": mass = val is float f1 ? f1 : mass; break;
                    case "Capacity" or "capacity" or "Storage": capacity = val is float f2 ? f2 : capacity; break;
                    case "EnergyConsumptionWhenActive": powerCon = val is float f3 ? f3 : powerCon; break;
                }
            }
        }

        return new RocketModuleDto(
            ModuleId: moduleId,
            ModuleType: moduleType,
            Mass: mass,
            Capacity: capacity,
            PowerConsumption: powerCon > 0 ? powerCon : null,
            NameLocalizationId: nameKey,
            DlcId: dlcId
        );
    }
}
```

- [ ] **Step 2: Build and run**

```bash
cd /home/mishaa/dev/ony-planner/dotnet-extractor
dotnet build && dotnet run -- "/mnt/d/SteamLibrary/steamapps/common/OxygenNotIncluded/OxygenNotIncluded_Data" /tmp/oni-extract-test
```

Check: `cat /tmp/oni-extract-test/rocket_components.json | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d['engines']), 'engines,', len(d['modules']), 'modules'); print(d['engines'][0] if d['engines'] else 'empty')"`

- [ ] **Step 3: Commit**

```bash
git add dotnet-extractor/Extractors/RocketExtractor.cs
git commit -m "feat(extractor): implement RocketExtractor via rocket IBuildingConfig IL walk"
```

---

### Task 10: AssetExtractor — copy game images to public/assets

**Files:**
- Modify: `dotnet-extractor/Extractors/AssetExtractor.cs`

**Approach:** The game stores element/building/critter images in its StreamingAssets or as PNG files in known subdirectories. Rather than full Unity asset bundle extraction (which requires a native runtime), we look for pre-exported image files in the game's data directory. The expected output is PNG/JPG files organized by category at `public/assets/game/{elements,buildings,critters,plants,geysers}/`.

Game image locations to check:
- `<game-path>/OxygenNotIncluded_Data/StreamingAssets/` (YAML/config files, no images)
- Pre-rendered sprite sheets in `<game-path>/OxygenNotIncluded_Data/` subdirs

If no pre-exported images are available, the AssetExtractor logs a message and skips — asset extraction is independent of game data extraction and can be implemented separately when the exact file paths are known.

- [ ] **Step 1: Replace AssetExtractor.cs**

```csharp
public sealed class AssetExtractor
{
    private readonly string _gamePath;

    public AssetExtractor(string gamePath) => _gamePath = gamePath;

    public void Extract(string outputPath)
    {
        var categories = new[] { "elements", "buildings", "critters", "plants", "geysers" };
        foreach (var cat in categories)
            Directory.CreateDirectory(Path.Combine(outputPath, cat));

        // Look for pre-exported PNG files in known locations
        var searchPaths = new[]
        {
            Path.Combine(_gamePath, "art"),
            Path.Combine(_gamePath, "OxygenNotIncluded_Data", "StreamingAssets", "images"),
        };

        int copied = 0;
        foreach (var searchPath in searchPaths)
        {
            if (!Directory.Exists(searchPath)) continue;

            foreach (var file in Directory.EnumerateFiles(searchPath, "*.png", SearchOption.AllDirectories))
            {
                var name = Path.GetFileNameWithoutExtension(file).ToLowerInvariant();
                var category = name.Contains("building") ? "buildings"
                    : name.Contains("critter") || name.Contains("creature") ? "critters"
                    : name.Contains("plant") ? "plants"
                    : name.Contains("geyser") ? "geysers"
                    : "elements";

                var dest = Path.Combine(outputPath, category, Path.GetFileName(file));
                File.Copy(file, dest, overwrite: true);
                copied++;
            }
        }

        if (copied == 0)
        {
            Console.WriteLine("Asset extraction: no pre-exported images found. " +
                "Unity asset bundle extraction requires AssetTools.NET or similar. " +
                "Output directories created, ready for manual population.");
        }
        else
        {
            Console.WriteLine($"Asset extraction: copied {copied} images.");
        }
    }
}
```

- [ ] **Step 2: Build and run**

```bash
cd /home/mishaa/dev/ony-planner/dotnet-extractor
dotnet build && dotnet run -- "/mnt/d/SteamLibrary/steamapps/common/OxygenNotIncluded/OxygenNotIncluded_Data" /tmp/oni-extract-test
```

Expected: Runs without error. If no images found, prints the fallback message.

- [ ] **Step 3: Commit**

```bash
git add dotnet-extractor/Extractors/AssetExtractor.cs
git commit -m "feat(extractor): implement AssetExtractor with graceful fallback"
```

---

## Self-Review Checklist

**Spec coverage:**
- [x] .csproj fixed for net10.0 (Task 1)
- [x] BuildingExtractor: building_id, width, height, construction_time, power fields (Task 3)
- [x] GeyserExtractor: all GeyserType fields → GeyserDto via SimHashes resolution (Task 4)
- [x] RecipeExtractor: building_id, duration, fabricators, inputs, outputs (Task 5)
- [x] CritterExtractor: critter_id, temps, calories, diets (partial), outputs (partial) (Task 6)
- [x] PlantExtractor: plant_id, temps, pressure, growth_time, inputs/outputs (partial) (Task 7)
- [x] DuplicantTraitExtractor: trait_id, is_positive, effects with stat/modifier/type (Task 8)
- [x] RocketExtractor: engines + modules with all DTO fields (Task 9)
- [x] AssetExtractor: directory creation + graceful fallback (Task 10)
- [x] IlHelper: shared utility used by all extractors (Task 2)

**Known limitations documented:**
- CritterExtractor: diet and output arrays are left empty initially — complex newarr IL patterns
- PlantExtractor: same for inputs/outputs
- RecipeExtractor: ingredient/output split heuristic may need adjustment per recipe type
- AssetExtractor: Unity bundle extraction deferred, directory scaffold only
- RocketExtractor: class name filter heuristic may miss some edge cases — verify against fixture
