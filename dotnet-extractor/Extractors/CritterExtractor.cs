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
    [property: JsonPropertyName("outputs")] List<CritterOutputDto> Outputs,
    [property: JsonPropertyName("species_tag")] string? SpeciesTag
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

        // Baby configs call EntityTemplates.ExtendEntityToBeingABaby(prefab, "<adultId>", ...);
        // their names vary (BabyHatchConfig, LightBugBabyConfig, MosquitoLarvaConfig).
        var entityConfigs = module.GetAllTypes()
            .Where(t => t.Interfaces.Any(i => i.InterfaceType.Name == "IEntityConfig"))
            .ToList();
        var babyConfigs = entityConfigs
            .Select(t => (Type: t, AdultId: FindBabyAdultId(t)))
            .Where(b => b.AdultId != null)
            .ToList();
        var babyTypes = babyConfigs.Select(b => b.Type).ToHashSet();

        // Adult configs build a creature body; cut content without a species tag
        // (e.g. ShockwormConfig) is not a real creature.
        var adultConfigs = entityConfigs
            .Where(t => !babyTypes.Contains(t) && IsAdultCritterConfig(t) && FindSpeciesTag(t) != null)
            .ToList();

        foreach (var type in adultConfigs)
        {
            var dto = ExtractCritter(type, isBaby: false, tuning, dietResolver);
            if (dto != null) results.Add(dto);
        }

        // The game's adult id may differ in case from the config-derived id (Oilfloater).
        var adultIds = results.ToDictionary(c => c.CritterId.ToLowerInvariant(), c => c.CritterId);
        foreach (var (type, adultId) in babyConfigs)
        {
            var dto = ExtractCritter(type, isBaby: true, tuning, dietResolver);
            if (dto == null) continue;
            var parentId = adultIds.GetValueOrDefault(adultId!.ToLowerInvariant(), adultId!);
            results.Add(dto with { ParentCritterId = parentId });
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

    /// <summary>
    /// A creature config sets up a creature body through <c>EntityTemplates.ExtendEntityToBasicCreature</c>
    /// (directly or via a helper: BeeConfig, ButterflyConfig, SeaFairyConfig...). Requiring
    /// <c>ExtendEntityToWildCreature</c> missed non-tameable species (Beeta, Gnit, Morb).
    /// </summary>
    private static bool IsAdultCritterConfig(TypeDefinition type)
    {
        if (!type.HasMethods) return false;
        return ReachableMethods(type, maxDepth: 3).Any(m => m.Body.Instructions.Any(i =>
            (i.OpCode == OpCodes.Call || i.OpCode == OpCodes.Callvirt) &&
            i.Operand is MethodReference mr &&
            mr.DeclaringType.Name == "EntityTemplates" &&
            (mr.Name == "ExtendEntityToBasicCreature" || mr.Name == "ExtendEntityToWildCreature")));
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

        lifespan = FindAgeMaxLifespan(type, tuning);

        if (!isBaby)
        {
            var tuningType = FindTuningTypeFor(type);
            calories = ResolveCaloriesPerCycle(tuning, tuningType);
            if (lifespan == 0) { lifespan = ResolveLifespan(tuning, tuningType); }

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
            Outputs: outputs,
            SpeciesTag: FindSpeciesTag(type)
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
    /// Lifespan in cycles from the trait modifier every creature config adds:
    /// <c>new AttributeModifier(Db.Get().Amounts.Age.maxAttribute.Id, value, ...)</c>.
    /// The value is an inline constant or a static field (e.g. <c>MooTuning.STANDARD_LIFESPAN</c>,
    /// <c>ChameleonConfig.LIFESPAN</c>). The modifier lives either in the config itself or in a
    /// helper reached within a few calls (<c>BasePacuConfig.CreatePrefab</c>; a baby goes
    /// through its adult config first).
    /// </summary>
    private static float FindAgeMaxLifespan(TypeDefinition config, TuningConstantResolver tuning)
    {
        foreach (var method in ReachableMethods(config))
        {
            var lifespan = ReadAgeMaxModifier(method, tuning);
            if (lifespan > 0) { return lifespan; }
        }

        return 0f;
    }

    /// <summary>
    /// The creature's species tag (e.g. <c>HatchSpecies</c>), read as
    /// <c>ldsfld GameTags.Creatures.Species::XSpecies</c> in the config or its helpers. The game
    /// names a family with <c>STRINGS.CREATURES.FAMILY_PLURAL.{tag.ToUpper()}</c>.
    /// </summary>
    private static string? FindSpeciesTag(TypeDefinition config)
    {
        foreach (var method in ReachableMethods(config))
        {
            var tag = method.Body.Instructions
                .Where(i => i.OpCode == OpCodes.Ldsfld && i.Operand is FieldReference)
                .Select(i => (FieldReference)i.Operand)
                .FirstOrDefault(f => f.DeclaringType.Name == "Species" && f.Name.EndsWith("Species"));
            if (tag != null) { return tag.Name; }
        }

        return null;
    }

    /// <summary>
    /// The config's own methods first, then methods they call, breadth-first up to a few
    /// levels: creature data often lives in a shared Base*Config helper, and a baby config
    /// goes through its adult config first.
    /// </summary>
    private static IEnumerable<MethodDefinition> ReachableMethods(TypeDefinition config, int maxDepth = 4)
    {
        var visited = new HashSet<MethodDefinition>();
        var frontier = config.Methods.Where(m => m.HasBody).ToList();

        for (int depth = 0; depth < maxDepth && frontier.Count > 0; depth++)
        {
            var next = new List<MethodDefinition>();
            foreach (var method in frontier.Where(visited.Add))
            {
                yield return method;

                next.AddRange(method.Body.Instructions
                    .Where(i => (i.OpCode == OpCodes.Call || i.OpCode == OpCodes.Callvirt) && i.Operand is MethodReference)
                    .Select(i => TryResolveMethod((MethodReference)i.Operand))
                    .OfType<MethodDefinition>()
                    .Where(m => m.HasBody));
            }

            frontier = next;
        }
    }

    private static float ReadAgeMaxModifier(MethodDefinition method, TuningConstantResolver tuning)
    {
        var ins = method.Body.Instructions;
        for (int i = 0; i + 3 < ins.Count; i++)
        {
            if (!IsFieldLoad(ins[i], "Amounts", "Age") ||
                !IsFieldLoad(ins[i + 1], "Amount", "maxAttribute") ||
                !IsFieldLoad(ins[i + 2], "Resource", "Id")) { continue; }

            var valueInstr = ins[i + 3];
            if (IlHelper.TryReadConstant(valueInstr, out var constant) && IlHelper.AsFloat(constant) is float f && f > 0)
            {
                return f;
            }

            if (valueInstr.OpCode == OpCodes.Ldsfld && valueInstr.Operand is FieldReference field &&
                tuning.ResolveField(field) is float resolved && resolved > 0)
            {
                return resolved;
            }
        }

        return 0f;
    }

    private static bool IsFieldLoad(Instruction instr, string declaringType, string fieldName) =>
        instr.OpCode == OpCodes.Ldfld && instr.Operand is FieldReference f &&
        f.Name == fieldName && f.DeclaringType.Name == declaringType;

    private static MethodDefinition? TryResolveMethod(MethodReference reference)
    {
        try { return reference.Resolve(); }
        catch (AssemblyResolutionException) { return null; }
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

    /// <summary>
    /// The adult prefab id a baby config grows into: <c>ldstr "Hatch"; call Tag::op_Implicit</c>
    /// shortly before <c>EntityTemplates.ExtendEntityToBeingABaby</c>. Null for non-baby configs.
    /// </summary>
    private static string? FindBabyAdultId(TypeDefinition type)
    {
        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            var ins = method.Body.Instructions;
            for (int i = 0; i < ins.Count; i++)
            {
                if (ins[i].Operand is not MethodReference mr || mr.Name != "ExtendEntityToBeingABaby") continue;

                // The adult tag is the ldstr converted by Tag::op_Implicit; a later ldstr can be
                // the item dropped on growing up (Pokeshell babies drop "CrabShell").
                for (int j = i - 1; j >= Math.Max(1, i - 10); j--)
                {
                    if (ins[j].Operand is MethodReference conv && conv.Name == "op_Implicit" &&
                        conv.DeclaringType.Name == "Tag" &&
                        ins[j - 1].OpCode == OpCodes.Ldstr && ins[j - 1].Operand is string adultId)
                    {
                        return adultId;
                    }
                }
            }
        }

        return null;
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

            // Pattern C: the new ExtendEntityToBasicCreatureData object-initializer API
            // (e.g. BaseCrabConfig) stores temps via stfld on named fields.
            if (FindTempsFromDataInitializer(instrs) is (float lo, float hi)) return (lo, hi);

            for (int i = 0; i < instrs.Count; i++)
            {
                if (instrs[i].OpCode != OpCodes.Call && instrs[i].OpCode != OpCodes.Callvirt) continue;
                if (instrs[i].Operand is not MethodReference mr) continue;

                var typeName = mr.DeclaringType.Name;
                // Some shared critter helpers drop the Config suffix (BasePufferFish,
                // BaseCrab); the temp-param check below keeps false matches out.
                bool isBaseCritterCall = typeName.StartsWith("Base");
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
                        // Match the exact overload: BaseCrabConfig.BaseCrab has a
                        // delegating overload (same arity, string vs string[]) that
                        // holds no temperature data.
                        var calledMethod = calledTypeDef?.Methods
                            .FirstOrDefault(m2 => m2.Name == mr.Name && m2.HasBody &&
                                m2.Parameters.Count == mr.Parameters.Count &&
                                m2.Parameters.Select(p => p.ParameterType.FullName)
                                    .SequenceEqual(mr.Parameters.Select(p => p.ParameterType.FullName)))
                            ?? calledTypeDef?.Methods.FirstOrDefault(m2 => m2.Name == mr.Name && m2.HasBody);
                        if (calledMethod == null) continue;

                        var instrs2 = calledMethod.Body.Instructions;

                        if (FindTempsFromDataInitializer(instrs2) is (float lo2, float hi2)) return (lo2, hi2);

                        // Delegating overload (e.g. CrabFreshWater's BaseCrab(string, int)):
                        // the data initializer lives one call deeper on the same type.
                        foreach (var inner in instrs2)
                        {
                            if ((inner.OpCode != OpCodes.Call && inner.OpCode != OpCodes.Callvirt) ||
                                inner.Operand is not MethodReference innerRef ||
                                innerRef.DeclaringType.FullName != mr.DeclaringType.FullName) continue;

                            var innerMethod = calledTypeDef?.Methods.FirstOrDefault(m3 =>
                                m3.Name == innerRef.Name && m3.HasBody && m3 != calledMethod &&
                                m3.Parameters.Count == innerRef.Parameters.Count);
                            if (innerMethod == null) continue;

                            if (FindTempsFromDataInitializer(innerMethod.Body.Instructions) is (float lo3, float hi3))
                                return (lo3, hi3);
                        }

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
    /// Temps stored through the ExtendEntityToBasicCreatureData object initializer:
    /// <c>ldc.r4 &lt;temp&gt;; stfld warningLowTemperature/warningHighTemperature</c>.
    /// </summary>
    private static (float MinTemp, float MaxTemp)? FindTempsFromDataInitializer(IList<Instruction> instructions)
    {
        float? low = null;
        float? high = null;
        for (int i = 1; i < instructions.Count; i++)
        {
            if (instructions[i].OpCode != OpCodes.Stfld || instructions[i].Operand is not FieldReference fr) continue;
            if (instructions[i - 1].OpCode != OpCodes.Ldc_R4) continue;

            if (fr.Name == "warningLowTemperature") low = (float)instructions[i - 1].Operand;
            else if (fr.Name == "warningHighTemperature") high = (float)instructions[i - 1].Operand;
        }

        return (low is float lo && high is float hi) ? (lo, hi) : null;
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
