using Mono.Cecil;
using Mono.Cecil.Cil;
using Mono.Cecil.Rocks;
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
    [property: JsonPropertyName("dlc_id")] string? DlcId,
    [property: JsonPropertyName("diets")] List<CritterDietDto> Diets,
    [property: JsonPropertyName("outputs")] List<CritterOutputDto> Outputs
);

public sealed class CritterExtractor
{
    private readonly string _managedPath;

    public CritterExtractor(string managedPath) => _managedPath = managedPath;

    public List<CritterDto> Extract()
    {
        var module = ModuleDefinition.ReadModule(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        var results = new List<CritterDto>();

        // Adult critter configs: IEntityConfig implementors that call ExtendEntityToWildCreature
        var adultConfigs = module.GetAllTypes()
            .Where(t => t.Interfaces.Any(i => i.InterfaceType.Name == "IEntityConfig")
                     && !t.Name.StartsWith("Baby")
                     && IsAdultCritterConfig(t))
            .ToList();

        // Baby critter configs: IEntityConfig implementors whose name starts with "Baby"
        var babyConfigs = module.GetAllTypes()
            .Where(t => t.Name.StartsWith("Baby") && t.Interfaces.Any(i => i.InterfaceType.Name == "IEntityConfig"))
            .ToList();

        foreach (var type in adultConfigs)
        {
            var dto = ExtractCritter(type, isBaby: false);
            if (dto != null) results.Add(dto);
        }

        foreach (var type in babyConfigs)
        {
            var dto = ExtractCritter(type, isBaby: true);
            if (dto != null) results.Add(dto);
        }

        return results;
    }

    private static bool IsAdultCritterConfig(TypeDefinition type)
    {
        if (!type.HasMethods) return false;
        return type.Methods.Any(m => m.HasBody && m.Body.Instructions.Any(i =>
            (i.OpCode == OpCodes.Call || i.OpCode == OpCodes.Callvirt) &&
            i.Operand is MethodReference mr &&
            mr.DeclaringType.Name == "EntityTemplates" &&
            mr.Name == "ExtendEntityToWildCreature"));
    }

    private static CritterDto? ExtractCritter(TypeDefinition type, bool isBaby)
    {
        var critterId = DeriveCritterId(type.Name);
        var parentId = isBaby ? DeriveParentId(type.Name) : null;
        var isBase = !isBaby;

        var (minTemp, maxTemp) = FindTemperatures(type);
        var incubation = isBaby ? 0f : FindIncubationTime(type);
        var overcrowding = isBaby ? 0 : FindOvercrowdingThreshold(type);
        var nameKey = FindNameKey(type);

        return new CritterDto(
            CritterId: critterId,
            ParentCritterId: parentId,
            IsBase: isBase,
            MinTemp: minTemp,
            MaxTemp: maxTemp,
            CaloriesPerCycle: 0f, // Diet extraction deferred to follow-up
            IncubationTime: incubation,
            Lifespan: 0f,         // Lifespan not reliably encoded as an IL constant
            OvercrowdingThreshold: overcrowding,
            NameLocalizationId: nameKey,
            DlcId: IlHelper.ExtractDlcId(type),
            Diets: [],
            Outputs: []
        );
    }

    private static string DeriveCritterId(string typeName)
    {
        if (typeName.StartsWith("Baby") && typeName.EndsWith("Config"))
            return typeName[4..^6] + "Baby"; // "BabyHatchConfig" → "HatchBaby"
        if (typeName.EndsWith("Config"))
            return typeName[..^6]; // "HatchConfig" → "Hatch"
        return typeName;
    }

    private static string DeriveParentId(string typeName)
    {
        if (typeName.StartsWith("Baby") && typeName.EndsWith("Config"))
            return typeName[4..^6]; // "BabyHatchConfig" → "Hatch"
        return typeName;
    }

    /// <summary>
    /// Find temperature range for a critter config type.
    ///
    /// There are two patterns in the game's IL:
    ///
    /// Pattern A (e.g. HatchConfig, LightBugConfig):
    ///   The IEntityConfig implementor calls Base*Config.Base*() which itself has inline
    ///   ldc.r4 constants for temps immediately before its own call to
    ///   EntityTemplates.ExtendEntityToBasicCreature.
    ///   (The base method signature does NOT include temp params.)
    ///
    /// Pattern B (e.g. PuftConfig, DreckoConfig, PacuConfig):
    ///   The IEntityConfig implementor calls Base*Config.Base*() with temps as method
    ///   arguments (the base method signature HAS float temp params).
    ///   The constants appear as 4 consecutive ldc.r4 values immediately before the
    ///   base call in the IEntityConfig method.
    ///
    /// Strategy:
    ///   1. Check if the base call has float params containing "temp"/"Temp" — if so,
    ///      collect the last 4 ldc.r4 values immediately before the call (Pattern B).
    ///   2. Otherwise, follow into the base method and look for the 4 temps before
    ///      ExtendEntityToBasicCreature (Pattern A).
    ///   3. Also handle the rare case where ExtendEntityToBasicCreature is called directly.
    /// </summary>
    private static (float MinTemp, float MaxTemp) FindTemperatures(TypeDefinition type)
    {
        if (!type.HasMethods) return (0, 0);

        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            var instrs = method.Body.Instructions;
            for (int i = 0; i < instrs.Count; i++)
            {
                if (instrs[i].OpCode != OpCodes.Call && instrs[i].OpCode != OpCodes.Callvirt) continue;
                if (instrs[i].Operand is not MethodReference mr) continue;

                var typeName = mr.DeclaringType.Name;
                bool isBaseCritterCall = typeName.StartsWith("Base") && typeName.EndsWith("Config");
                bool isDirectCall = typeName == "EntityTemplates" && mr.Name == "ExtendEntityToBasicCreature";

                if (!isBaseCritterCall && !isDirectCall) continue;

                // Pattern B / Direct: check if the called method has float temp params
                bool calledHasTempParams = mr.Parameters.Any(p =>
                    p.ParameterType.Name == "Single" &&
                    (p.Name.ToLower().Contains("temp") || p.Name.ToLower().Contains("low") || p.Name.ToLower().Contains("high")));

                if (calledHasTempParams || isDirectCall)
                {
                    const float MinPlausibleTempKelvin = 50f; // valid critter temps in Kelvin are always higher
                    // The 4 temperature floats are the last ldc.r4 values immediately
                    // before the call (no other call between them and the temps)
                    var floats = CollectLastFourFloatsStrict(instrs, i);
                    if (floats.Count == 4 && floats[0] > MinPlausibleTempKelvin && floats[1] > MinPlausibleTempKelvin)
                        return (floats[0], floats[1]);
                }

                if (isBaseCritterCall)
                {
                    // Pattern A: follow into the base method
                    try
                    {
                        var calledTypeDef = mr.DeclaringType.Resolve();
                        var calledMethod = calledTypeDef?.Methods
                            .FirstOrDefault(m2 => m2.Name == mr.Name && m2.HasBody);
                        if (calledMethod == null) continue;

                        var instrs2 = calledMethod.Body.Instructions;
                        for (int j = 0; j < instrs2.Count; j++)
                        {
                            if (instrs2[j].Operand is not MethodReference mr2 ||
                                mr2.Name != "ExtendEntityToBasicCreature") continue;

                            // In pattern A, temps may be args passed in (ldarg.s) OR inline constants
                            // For inline constants, collect last 4 floats before ExtendEntityToBasicCreature
                            const float MinPlausibleTempKelvin = 50f; // valid critter temps in Kelvin are always higher
                            var floats2 = CollectLastFourFloatsStrict(instrs2, j);
                            if (floats2.Count == 4 && floats2[0] > MinPlausibleTempKelvin && floats2[1] > MinPlausibleTempKelvin)
                                return (floats2[0], floats2[1]);
                        }
                    }
                    catch (AssemblyResolutionException) { /* base type is in another assembly */ }
                }
            }
        }

        return (0, 0);
    }

    /// <summary>
    /// Scan backwards from callIndex collecting ldc.r4 values, stopping at any call
    /// instruction (to avoid crossing method/expression boundaries).
    /// Returns collected values in original order (earliest first).
    /// </summary>
    private static List<float> CollectLastFourFloatsStrict(IList<Instruction> instructions, int callIndex)
    {
        var floats = new List<float>();
        for (int j = callIndex - 1; j >= 0 && floats.Count < 4; j--)
        {
            var op = instructions[j].OpCode;
            if (op == OpCodes.Ldc_R4)
            {
                floats.Insert(0, (float)instructions[j].Operand);
            }
            else if (op == OpCodes.Call || op == OpCodes.Callvirt || op == OpCodes.Newobj)
            {
                // Stop scanning — crossed a sub-expression boundary
                break;
            }
        }
        return floats;
    }

    /// <summary>
    /// Extract incubation cycles from ExtendEntityToFertileCreature.
    /// The method signature has incubationCycles at index 9.
    /// We use IlHelper.ExtractCallArgs which simulates the stack.
    /// </summary>
    private static float FindIncubationTime(TypeDefinition type)
    {
        if (!type.HasMethods) return 0;
        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            var instructions = method.Body.Instructions;
            for (int i = 0; i < instructions.Count; i++)
            {
                if ((instructions[i].OpCode != OpCodes.Call && instructions[i].OpCode != OpCodes.Callvirt) ||
                    instructions[i].Operand is not MethodReference mr ||
                    mr.Name != "ExtendEntityToFertileCreature") continue;

                var args = IlHelper.ExtractCallArgs(instructions, i, mr.Parameters.Count);
                if (args.Length > 9 && args[9] is float incubation) return incubation;
            }
        }
        return 0;
    }

    /// <summary>
    /// Extract space_required_per_creature from ExtendEntityToWildCreature param[1].
    /// The arg is often a static field load (ldsfld from HatchTuning.PEN_SIZE_PER_CREATURE),
    /// so we can't reliably get it as an IL constant. Return 0 for now.
    /// </summary>
    private static int FindOvercrowdingThreshold(TypeDefinition type)
    {
        if (!type.HasMethods) return 0;
        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            var instructions = method.Body.Instructions;
            for (int i = 0; i < instructions.Count; i++)
            {
                if ((instructions[i].OpCode != OpCodes.Call && instructions[i].OpCode != OpCodes.Callvirt) ||
                    instructions[i].Operand is not MethodReference mr ||
                    mr.Name != "ExtendEntityToWildCreature") continue;

                // Scan back for an integer constant (ldc.i4 variant)
                for (int j = i - 1; j >= 0; j--)
                {
                    if (IlHelper.TryReadConstant(instructions[j], out var val) && val is int iv && iv > 0)
                        return iv;
                    var code = instructions[j].OpCode.Code;
                    // Stop at calls/loads that aren't simple constants
                    if (code == Code.Call || code == Code.Callvirt) break;
                }
            }
        }
        return 0;
    }

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
}
