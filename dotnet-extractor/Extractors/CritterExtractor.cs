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
    [property: JsonPropertyName("variant_family")] string? VariantFamily,
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

    // critter_id -> (calories_per_cycle, lifespan_cycles, overcrowding_threshold) for configs
    // whose IL resists extraction. Values sourced from the game source / wiki and documented inline.
    // Leave empty until a value is confirmed; best-effort zeros are acceptable per the extraction plan.
    private static readonly Dictionary<string, (float Calories, float Lifespan, int Pen)> Overrides = new()
    {
        // No entries currently needed: HatchTuning.STANDARD_CALORIES_PER_CYCLE and
        // PEN_SIZE_PER_CREATURE both resolve via TuningConstantResolver. Add entries here
        // only when a critter family's tuning type cannot be resolved from the DLL.
    };

    public CritterExtractor(string managedPath) => _managedPath = managedPath;

    public List<CritterDto> Extract()
    {
        var module = ModuleDefinition.ReadModule(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        var tuning = new TuningConstantResolver(module);
        var simHashes = IlHelper.BuildSimHashesMap(module);
        var dietResolver = new CritterDietResolver(module, simHashes, tuning);
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
            var dto = ExtractCritter(type, isBaby: false, tuning, dietResolver);
            if (dto != null) results.Add(dto);
        }

        foreach (var type in babyConfigs)
        {
            var dto = ExtractCritter(type, isBaby: true, tuning, dietResolver);
            if (dto != null) results.Add(dto);
        }

        // variant_family is a parent-level grouping label. Most baby configs do not
        // call a Base*Config, so they resolve to null above; inherit it from the
        // adult so every morph of a critter carries the same family regardless of
        // import order.
        var familyByCritterId = results
            .Where(c => c.VariantFamily != null)
            .ToDictionary(c => c.CritterId, c => c.VariantFamily);

        for (int i = 0; i < results.Count; i++)
        {
            if (results[i].VariantFamily == null
                && results[i].ParentCritterId is string parent
                && familyByCritterId.TryGetValue(parent, out var family))
            {
                results[i] = results[i] with { VariantFamily = family };
            }
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

    private static CritterDto? ExtractCritter(TypeDefinition type, bool isBaby, TuningConstantResolver tuning, CritterDietResolver dietResolver)
    {
        var critterId = DeriveCritterId(type.Name);
        var parentId = isBaby ? DeriveParentId(type.Name) : null;
        var isBase = !isBaby;

        var (minTemp, maxTemp) = FindTemperatures(type);
        var incubation = isBaby ? 0f : FindIncubationTime(type);
        var nameKey = FindNameKey(type);

        // region calories / lifespan / overcrowding
        float calories = 0f;
        float lifespan = 0f;
        int overcrowding = isBaby ? 0 : FindOvercrowdingThreshold(type);

        if (!isBaby)
        {
            var tuningType = FindTuningTypeFor(type);
            calories = ResolveCaloriesPerCycle(tuning, tuningType);
            lifespan = ResolveLifespan(tuning, tuningType);

            if (overcrowding == 0 && tuning.TryResolve(tuningType, "PEN_SIZE_PER_CREATURE", out var pen))
                overcrowding = (int)Math.Round(pen);
        }

        if (Overrides.TryGetValue(critterId, out var ov))
        {
            if (calories == 0) { calories = ov.Calories; }
            if (lifespan == 0) { lifespan = ov.Lifespan; }
            if (overcrowding == 0) { overcrowding = ov.Pen; }
        }
        // endregion

        // region diets / outputs (adult only)
        var diets = new List<CritterDietDto>();
        var outputs = new List<CritterOutputDto>();

        if (!isBaby)
        {
            // Resolve diets: skip category-tag entries (ConsumedElementId == null)
            var resolved = dietResolver.Resolve(type);
            foreach (var d in resolved)
            {
                if (d.ConsumedElementId == null) { continue; }

                var amountPerCycle = (d.CaloriesPerKg > 0f && calories > 0f)
                    ? calories / d.CaloriesPerKg
                    : 0f;

                diets.Add(new CritterDietDto(
                    ConsumedElementId: d.ConsumedElementId,
                    AmountPerCycle: amountPerCycle,
                    ProducedElementId: d.ProducedElementId,
                    ConversionRatio: d.ConversionRatio
                ));
            }

            // Egg output
            var egg = FindEgg(type);
            if (egg != null) { outputs.Add(egg); }
        }
        // endregion

        return new CritterDto(
            CritterId: critterId,
            ParentCritterId: parentId,
            IsBase: isBase,
            VariantFamily: FindVariantFamily(type),
            MinTemp: minTemp,
            MaxTemp: maxTemp,
            CaloriesPerCycle: calories,
            IncubationTime: incubation,
            Lifespan: lifespan,
            OvercrowdingThreshold: overcrowding,
            NameLocalizationId: nameKey,
            DlcId: IlHelper.ExtractDlcId(type),
            Diets: diets,
            Outputs: outputs
        );
    }

    private static CritterOutputDto? FindEgg(TypeDefinition type)
    {
        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            var ins = method.Body.Instructions;
            for (int i = 0; i < ins.Count; i++)
            {
                if (ins[i].Operand is not MethodReference mr || mr.Name != "ExtendEntityToFertileCreature") { continue; }

                var args = IlHelper.ExtractCallArgs(ins, i, mr.Parameters.Count);
                string? eggId = ArgByName(mr, args, "eggId") as string;
                float fertility = ArgByName(mr, args, "fertilityCycles") is float f ? f : 0f;

                if (eggId != null && fertility > 0)
                {
                    return new CritterOutputDto(eggId, 1f / fertility, "egg");
                }
            }
        }

        return null;
    }

    private static object? ArgByName(MethodReference mr, object?[] args, string nameContains)
    {
        var needle = nameContains.ToLower().Replace("_", "");
        for (int p = 0; p < mr.Parameters.Count && p < args.Length; p++)
        {
            if (mr.Parameters[p].Name.ToLower().Replace("_", "").Contains(needle))
            {
                return args[p];
            }
        }

        return null;
    }

    /// <summary>
    /// Find the *Tuning type referenced by a critter config's method bodies.
    /// Scans all ldsfld and call instructions looking for a declaring type whose
    /// name ends with "Tuning". Returns the first such TypeDefinition found, or null.
    /// </summary>
    private static TypeDefinition? FindTuningTypeFor(TypeDefinition config)
    {
        if (!config.HasMethods) { return null; }

        foreach (var method in config.Methods.Where(m => m.HasBody))
        {
            foreach (var instr in method.Body.Instructions)
            {
                TypeReference? declaringType = instr.OpCode.Code switch
                {
                    Code.Ldsfld or Code.Ldsflda when instr.Operand is FieldReference fr => fr.DeclaringType,
                    Code.Call or Code.Callvirt when instr.Operand is MethodReference mr => mr.DeclaringType,
                    _ => null
                };

                if (declaringType == null || !declaringType.Name.EndsWith("Tuning")) { continue; }

                try
                {
                    var resolved = declaringType.Resolve();
                    if (resolved != null) { return resolved; }
                }
                catch (AssemblyResolutionException) { /* skip */ }
            }
        }

        return null;
    }

    /// <summary>
    /// Resolve STANDARD_CALORIES_PER_CYCLE from the critter's tuning type.
    /// Returns 0 if the tuning type is null or the field is not a literal.
    /// </summary>
    private static float ResolveCaloriesPerCycle(TuningConstantResolver tuning, TypeDefinition? tuningType)
    {
        if (tuning.TryResolve(tuningType, "STANDARD_CALORIES_PER_CYCLE", out var value)) { return value; }

        return 0f;
    }

    /// <summary>
    /// Resolve a lifespan constant from the critter's tuning type.
    /// Most critter families (Hatch, Drecko, Pacu, Puft) do not encode lifespan as
    /// a tuning constant — returns 0 for those. MooTuning and a few others may have
    /// a lifespan-like field that the resolver can extract.
    /// </summary>
    private static float ResolveLifespan(TuningConstantResolver tuning, TypeDefinition? tuningType)
    {
        if (tuning.TryResolve(tuningType, "STANDARD_LIFESPAN", out var value)) { return value; }

        return 0f;
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

    /// <summary>
    /// Derive a variant-family grouping label from the shared Base*Config the critter calls.
    /// Strips "Base" prefix and "Config" suffix: BaseHatchConfig → "Hatch".
    /// Returns the first match found (multiple Base*Config refs are rare for critters).
    /// Returns null for critters with no shared base config.
    /// </summary>
    private static string? FindVariantFamily(TypeDefinition type)
    {
        if (!type.HasMethods) { return null; }

        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            foreach (var instr in method.Body.Instructions)
            {
                if (instr.OpCode != OpCodes.Call && instr.OpCode != OpCodes.Callvirt) { continue; }
                if (instr.Operand is not MethodReference mr) { continue; }

                var n = mr.DeclaringType.Name;
                // Length > 10 ensures a non-empty middle (excludes a bare "BaseConfig").
                if (n.Length > 10 && n.StartsWith("Base") && n.EndsWith("Config")) { return n[4..^6]; } // BaseHatchConfig → "Hatch"
            }
        }

        return null;
    }
}
