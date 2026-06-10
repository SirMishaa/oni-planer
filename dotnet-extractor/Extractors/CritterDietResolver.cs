using Mono.Cecil;
using Mono.Cecil.Cil;

/// <summary>
/// One resolved diet relationship for a critter: a single consumed element (or
/// consumed category tag) mapped to the produced element, with the energy/conversion
/// constants recovered from the config and its tuning type.
/// </summary>
/// <remarks>
/// <para><c>ConsumedTag</c> is non-null (and <c>ConsumedElementId</c> null) when the
/// consumed input is a GameTags category (e.g. "Metal", "BasicMetalOres") rather than a
/// single SimHashes element, so a later step can skip or expand it.</para>
/// </remarks>
public sealed record ResolvedDiet(
    string? ConsumedElementId,
    string? ConsumedTag,
    string? ProducedElementId,
    float CaloriesPerKg,
    float ConversionRatio);

/// <summary>
/// Static-IL resolver for critter diets. Reads a critter's <c>*Config</c> type, finds the
/// calls into the shared <c>Base*Config</c> diet methods (or the config's own diet fields),
/// and recovers, per consumed element:
/// <list type="bullet">
///   <item>consumed element id — inline <c>ldc.i4 &lt;simHash&gt; → GameTagExtensions::CreateTag</c>
///   inside the base diet method, or the config's <c>CONSUME_ELEMENT</c> literal field;</item>
///   <item>produced element id — the config's <c>EMIT_ELEMENT</c> literal field, overridden
///   per-pair by an inline metal <c>CreateTag</c> in metal diets;</item>
///   <item>caloriesPerKg — <c>STANDARD_CALORIES_PER_CYCLE / KG_ORE_EATEN_PER_CYCLE</c>;</item>
///   <item>conversionRatio — the <c>CONVERSION_EFFICIENCY::&lt;field&gt;</c> loaded at the diet call site.</item>
/// </list>
/// Configs that build their diets dynamically (e.g. Drecko via <c>ToTag</c> on plant ids,
/// Pacu's non-const base) are handled best-effort and may return an empty list.
/// </summary>
public sealed class CritterDietResolver
{
    private readonly ModuleDefinition _module;
    private readonly Dictionary<int, string> _simHashes;
    private readonly TuningConstantResolver _tuning;

    public CritterDietResolver(ModuleDefinition module, Dictionary<int, string> simHashes, TuningConstantResolver tuning)
    {
        _module = module;
        _simHashes = simHashes;
        _tuning = tuning;
    }

    /// <summary>
    /// Resolve every diet relationship declared by <paramref name="config"/>. Returns one
    /// <see cref="ResolvedDiet"/> per consumed element (or category tag). Never throws on a
    /// config it cannot statically interpret; returns an empty list instead.
    /// </summary>
    public List<ResolvedDiet> Resolve(TypeDefinition? config)
    {
        var results = new List<ResolvedDiet>();
        if (config is null || !config.HasMethods) { return results; }

        var caloriesPerKg = ResolveCaloriesPerKg(config);
        var defaultProduced = ReadLiteralSimHashField(config, "EMIT_ELEMENT");
        var consumeElement = ReadLiteralSimHashField(config, "CONSUME_ELEMENT");

        foreach (var method in config.Methods.Where(m => m.HasBody))
        {
            var instructions = method.Body.Instructions;
            for (int i = 0; i < instructions.Count; i++)
            {
                if (instructions[i].OpCode != OpCodes.Call && instructions[i].OpCode != OpCodes.Callvirt) { continue; }
                if (instructions[i].Operand is not MethodReference mr) { continue; }
                if (!IsBaseDietCall(mr)) { continue; }

                var conversionRatio = ResolveConversionRatioAtCallSite(instructions, i);
                var baseMethod = TryResolve(mr);
                if (baseMethod?.HasBody != true) { continue; }

                CollectDietsFromBaseMethod(baseMethod, defaultProduced, caloriesPerKg, conversionRatio, results);
            }
        }

        // Puft-style configs declare the consumed element on the config itself (CONSUME_ELEMENT)
        // rather than inline in the base method; emit it if nothing else picked it up.
        if (consumeElement is not null && !results.Any(d => d.ConsumedElementId is not null))
        {
            results.Add(new ResolvedDiet(consumeElement, null, defaultProduced, caloriesPerKg, 0f));
        }

        return results;
    }

    /// <summary>
    /// Walk a resolved base diet method body, emitting one <see cref="ResolvedDiet"/> per
    /// consumed element/tag. Inline <c>ldc.i4 → CreateTag</c> values feeding a consumed set
    /// are consumed elements; <c>ldsfld GameTags::X</c> are category tags. Metal diets pair a
    /// consumed ore <c>CreateTag</c> with a following produced metal <c>CreateTag</c>.
    /// </summary>
    private void CollectDietsFromBaseMethod(
        MethodDefinition baseMethod, string? defaultProduced, float caloriesPerKg, float conversionRatio,
        List<ResolvedDiet> results)
    {
        var ins = baseMethod.Body.Instructions;

        // A metal diet derives the produced element per ore via an inline metal CreateTag
        // (and reads the ore set generically through ElementLoader). Detect it so the pairing
        // and category-tag handling kick in.
        bool isMetalStyle = ins.Any(x =>
            x.Operand is MethodReference m &&
            (m.Name == "FindElementByTag" || m.Name == "op_Equality"));

        var recordedTags = new HashSet<string>();
        var createTagHashes = new List<int>();

        for (int i = 0; i < ins.Count; i++)
        {
            var instr = ins[i];

            // ldsfld GameTags::X — a category tag input. Record it once, no element id.
            if (instr.OpCode == OpCodes.Ldsfld && instr.Operand is FieldReference fr &&
                fr.DeclaringType.Name == "GameTags")
            {
                if (recordedTags.Add(fr.Name))
                {
                    results.Add(new ResolvedDiet(null, fr.Name, null, caloriesPerKg, conversionRatio));
                }

                continue;
            }

            // ldc.i4 <hash> immediately before call GameTagExtensions::CreateTag
            if (instr.OpCode == OpCodes.Call && instr.Operand is MethodReference cm &&
                cm.DeclaringType.Name == "GameTagExtensions" && cm.Name == "CreateTag" &&
                i > 0 && IlHelper.TryReadConstant(ins[i - 1], out var c) && c is int hash)
            {
                createTagHashes.Add(hash);
            }
        }

        if (!isMetalStyle)
        {
            // Every CreateTag in a simple diet is a consumed element; produced is EMIT_ELEMENT.
            foreach (var hash in createTagHashes)
            {
                results.Add(new ResolvedDiet(MapSimHash(hash), null, defaultProduced, caloriesPerKg, conversionRatio));
            }

            return;
        }

        // Metal-style: the inline CreateTags come in (consumed ore, produced metal) pairs.
        // Anything left unpaired is treated as a consumed element with the default produced.
        for (int p = 0; p + 1 < createTagHashes.Count; p += 2)
        {
            results.Add(new ResolvedDiet(
                MapSimHash(createTagHashes[p]), null, MapSimHash(createTagHashes[p + 1]), caloriesPerKg, conversionRatio));
        }

        if (createTagHashes.Count % 2 == 1)
        {
            results.Add(new ResolvedDiet(
                MapSimHash(createTagHashes[^1]), null, defaultProduced, caloriesPerKg, conversionRatio));
        }
    }

    /// <summary>
    /// True when <paramref name="mr"/> targets a shared <c>Base*Config</c> diet method with the
    /// canonical signature <c>(Tag, Single, Single, String, Single)</c>. Excludes <c>SetupDiet</c>.
    /// </summary>
    private static bool IsBaseDietCall(MethodReference mr)
    {
        var declaring = mr.DeclaringType.Name;
        if (!declaring.StartsWith("Base") || !declaring.EndsWith("Config")) { return false; }

        var p = mr.Parameters;
        if (p.Count != 5) { return false; }

        return p[0].ParameterType.Name == "Tag" &&
               p[1].ParameterType.Name == "Single" &&
               p[2].ParameterType.Name == "Single" &&
               p[3].ParameterType.Name == "String" &&
               p[4].ParameterType.Name == "Single";
    }

    /// <summary>
    /// caloriesPerKg = <c>STANDARD_CALORIES_PER_CYCLE</c> (from the config's referenced
    /// <c>*Tuning</c> type) / <c>KG_ORE_EATEN_PER_CYCLE</c> (the config's own .cctor literal).
    /// Returns 0 if either part is unresolvable or the divisor is zero.
    /// </summary>
    private float ResolveCaloriesPerKg(TypeDefinition config)
    {
        if (!_tuning.TryResolve(config, "KG_ORE_EATEN_PER_CYCLE", out var kgPerCycle) || kgPerCycle == 0f)
        {
            return 0f;
        }

        var tuningType = FindReferencedTuningType(config);
        if (tuningType is null || !_tuning.TryResolve(tuningType, "STANDARD_CALORIES_PER_CYCLE", out var calories))
        {
            return 0f;
        }

        return calories / kgPerCycle;
    }

    /// <summary>
    /// Find the <c>*Tuning</c> type referenced by ldsfld anywhere in the config (e.g.
    /// <c>HatchTuning</c> for <c>HatchConfig</c>), resolving the field reference to a definition.
    /// </summary>
    private static TypeDefinition? FindReferencedTuningType(TypeDefinition config)
    {
        foreach (var method in config.Methods.Where(m => m.HasBody))
        {
            foreach (var instr in method.Body.Instructions)
            {
                if (instr.OpCode != OpCodes.Ldsfld || instr.Operand is not FieldReference fr) { continue; }
                if (!fr.DeclaringType.Name.EndsWith("Tuning")) { continue; }

                var resolved = TryResolveType(fr.DeclaringType);
                if (resolved is not null) { return resolved; }
            }
        }

        return null;
    }

    /// <summary>
    /// Scan backwards from a diet call site for the <c>ldsfld CONVERSION_EFFICIENCY::&lt;field&gt;</c>
    /// argument and resolve its literal value (NORMAL=0.5, GOOD_1=0.75, ...). Returns 0 if absent.
    /// </summary>
    private float ResolveConversionRatioAtCallSite(IList<Instruction> instructions, int callIndex)
    {
        for (int j = callIndex - 1; j >= 0; j--)
        {
            var instr = instructions[j];
            if (instr.OpCode == OpCodes.Ldsfld && instr.Operand is FieldReference fr &&
                fr.DeclaringType.Name == "CONVERSION_EFFICIENCY")
            {
                var declaring = TryResolveType(fr.DeclaringType);
                if (declaring is not null && _tuning.TryResolve(declaring, fr.Name, out var value))
                {
                    return value;
                }

                return 0f;
            }

            // Stop at the previous call (a prior argument expression's boundary).
            if (instr.OpCode == OpCodes.Call || instr.OpCode == OpCodes.Callvirt)
            {
                if (instr.Operand is MethodReference mr && mr.DeclaringType.Name == "GameTagExtensions")
                {
                    // CreateTag for the poop arg of this same call — keep scanning past it.
                    continue;
                }

                break;
            }
        }

        return 0f;
    }

    /// <summary>
    /// Read a SimHashes static literal field (e.g. <c>EMIT_ELEMENT</c>, <c>CONSUME_ELEMENT</c>) as a
    /// 32-bit int (no float rounding) and map it to its element id. Returns null if absent/non-literal.
    /// </summary>
    private string? ReadLiteralSimHashField(TypeDefinition config, string fieldName)
    {
        var field = config.Fields.FirstOrDefault(f => f.IsStatic && f.IsLiteral && f.Name == fieldName);
        if (field?.Constant is int hash)
        {
            return MapSimHash(hash);
        }

        return null;
    }

    private string? MapSimHash(int hash) => _simHashes.TryGetValue(hash, out var id) ? id : null;

    private static MethodDefinition? TryResolve(MethodReference mr)
    {
        try { return mr.Resolve(); }
        catch { return null; }
    }

    private static TypeDefinition? TryResolveType(TypeReference tr)
    {
        try { return tr.Resolve(); }
        catch { return null; }
    }
}
