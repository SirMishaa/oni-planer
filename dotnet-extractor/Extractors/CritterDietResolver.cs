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
        // rather than inline in the base method; emit it if nothing else was collected.
        if (consumeElement is not null && !results.Any())
        {
            results.Add(new ResolvedDiet(consumeElement, null, defaultProduced, caloriesPerKg, 0f));
        }

        // Modern configs (PufferFish, LightBug, Pacu, ...) build a Diet from
        // new Diet.Info(HashSet<Tag>, ...) instead of the legacy Base*Config
        // diet methods; recover those when the legacy strategies found nothing.
        if (!results.Any())
        {
            CollectDietsFromDietInfoConstruction(config, results);
        }

        return results;
    }

    /// <summary>One Tag value tracked through the simulation: an element/prefab id, or a GameTags category.</summary>
    private sealed record DietTagValue(string? ElementId, string? CategoryTag);

    /// <summary>A simulated stack slot: a constant, a tag-collection identity, and/or a resolved Tag.</summary>
    private sealed record DietSimEntry(object? Constant = null, string? SetKey = null, DietTagValue? Tag = null)
    {
        public static readonly DietSimEntry Unknown = new();
    }

    /// <summary>One <c>Diet.Info</c> constructor call: which consumed set it received and its own values.</summary>
    private sealed record DietInfoCall(string? SetKey, string? ProducedElementId, float CaloriesPerKg, float ConversionRate);

    /// <summary>
    /// Fallback strategy for configs that construct <c>Diet.Info</c> directly (in the
    /// config itself or in a called <c>Base*</c> helper). A forward stack simulation
    /// tracks each <c>HashSet&lt;Tag&gt;</c>/<c>List&lt;Tag&gt;</c> instance (by its newobj
    /// or the local it is stored in), the tags added to it, and which set each
    /// <c>Diet.Info</c> constructor receives — so every Info keeps its own consumed set,
    /// produced element, calories and conversion rate (SeaTurtle has two Infos with
    /// different calories), and tag collections that never reach a Diet.Info (e.g.
    /// ShakeHarvestMonitor's harvestablePlants, FishFeeder eggTags) are discarded instead
    /// of polluting the diet. Sets passed into a helper as a parameter lose their identity
    /// (ldarg); those tags attach to the first Info whose set is unknown (LightBug's
    /// SetupDiet pattern). Diet helper calls that receive the consumed tag as an argument
    /// (BaseMooConfig.ExpandDiet) are emitted directly from their arguments.
    /// </summary>
    private void CollectDietsFromDietInfoConstruction(TypeDefinition config, List<ResolvedDiet> results)
    {
        var sets = new Dictionary<string, List<DietTagValue>>();
        var infos = new List<DietInfoCall>();
        var helperDiets = new List<ResolvedDiet>();

        foreach (var method in DietMethodsFor(config))
        {
            SimulateDietMethod(method, sets, infos, helperDiets);
        }

        if (infos.Count == 0 && helperDiets.Count == 0) { return; }

        // SetupDiet-style helpers receive caloriesPerKg as a parameter; the constant then
        // lives on the config as a CALORIES_PER_KG* field (e.g. LightBugConfig, MoleConfig).
        // The field may be a const or a static readonly set in the .cctor (MoleConfig).
        float caloriesFallback = 0f;
        var caloriesField = config.Fields.FirstOrDefault(f => f.IsStatic && f.Name.StartsWith("CALORIES_PER_KG"));
        if (caloriesField?.Constant is float literalValue) { caloriesFallback = literalValue; }
        else if (caloriesField is not null && _tuning.ResolveField(caloriesField) is float resolvedValue) { caloriesFallback = resolvedValue; }

        var claimedSets = new HashSet<string>();
        var seen = new HashSet<string>();

        foreach (var info in infos.Where(x => x.SetKey is not null))
        {
            if (!sets.TryGetValue(info.SetKey!, out var tags) || tags.Count == 0) { continue; }

            claimedSets.Add(info.SetKey!);
            EmitDiets(results, seen, tags, info, caloriesFallback);
        }

        // Sets that flowed into a helper as an argument (identity lost through ldarg)
        // pair with the first Info whose consumed set is unknown — or known but empty:
        // BaseMoleConfig.SimpleOreDiet builds one HashSet per element of the list it
        // receives (from enumerator.Current), so its sets never record a tag.
        var orphanInfo = infos.FirstOrDefault(x =>
            x.SetKey is null || !sets.TryGetValue(x.SetKey, out var setTags) || setTags.Count == 0);
        if (orphanInfo is not null)
        {
            foreach (var (key, tags) in sets)
            {
                if (!claimedSets.Contains(key))
                {
                    EmitDiets(results, seen, tags, orphanInfo, caloriesFallback);
                }
            }
        }

        foreach (var diet in helperDiets)
        {
            var id = diet.ConsumedElementId ?? diet.ConsumedTag;
            if (id is not null && seen.Add(id))
            {
                results.Add(diet.CaloriesPerKg == 0f && caloriesFallback != 0f
                    ? diet with { CaloriesPerKg = caloriesFallback }
                    : diet);
            }
        }
    }

    private static void EmitDiets(
        List<ResolvedDiet> results, HashSet<string> seen, List<DietTagValue> tags,
        DietInfoCall info, float caloriesFallback)
    {
        var calories = info.CaloriesPerKg != 0f ? info.CaloriesPerKg : caloriesFallback;

        foreach (var tag in tags)
        {
            var id = tag.ElementId ?? tag.CategoryTag;
            if (id is null || !seen.Add(id)) { continue; }

            results.Add(new ResolvedDiet(
                tag.ElementId, tag.CategoryTag, info.ProducedElementId, calories, info.ConversionRate));
        }
    }

    /// <summary>
    /// Forward stack simulation of one method body. Tracks tag-collection identities
    /// (newobj HashSet&lt;Tag&gt;/List&lt;Tag&gt; → SetKey, stloc/ldloc aliases), Tag value
    /// provenance (CreateTag, string→Tag calls, static Tag fields, GameTags categories),
    /// and numeric constants (literals, resolvable ldsfld, folded arithmetic). Collections
    /// reached through <c>ldfld</c> (monitor Def fields like harvestablePlants/eggTags)
    /// have no tracked identity, so their Adds are ignored by construction. Any branch or
    /// unmodelled instruction clears the stack; sets and locals persist.
    /// </summary>
    private void SimulateDietMethod(
        MethodDefinition method,
        Dictionary<string, List<DietTagValue>> sets,
        List<DietInfoCall> infos,
        List<ResolvedDiet> helperDiets)
    {
        var stack = new Stack<DietSimEntry>();
        var locals = new Dictionary<int, DietSimEntry>();
        var ins = method.Body.Instructions;

        foreach (var instr in ins)
        {
            if (IlHelper.TryReadConstant(instr, out var constant))
            {
                stack.Push(new DietSimEntry(Constant: constant));
                continue;
            }

            switch (instr.OpCode.Code)
            {
                case Code.Ldsfld when instr.Operand is FieldReference fr:
                    stack.Push(SimulateLdsfld(fr));
                    break;

                case Code.Dup:
                    stack.Push(stack.Count > 0 ? stack.Peek() : DietSimEntry.Unknown);
                    break;

                case Code.Pop:
                    PopEntry(stack);
                    break;

                case Code.Stloc_0: locals[0] = PopEntry(stack); break;
                case Code.Stloc_1: locals[1] = PopEntry(stack); break;
                case Code.Stloc_2: locals[2] = PopEntry(stack); break;
                case Code.Stloc_3: locals[3] = PopEntry(stack); break;
                case Code.Stloc_S: case Code.Stloc:
                    locals[((VariableDefinition)instr.Operand).Index] = PopEntry(stack);
                    break;

                case Code.Ldloc_0: stack.Push(locals.GetValueOrDefault(0, DietSimEntry.Unknown)); break;
                case Code.Ldloc_1: stack.Push(locals.GetValueOrDefault(1, DietSimEntry.Unknown)); break;
                case Code.Ldloc_2: stack.Push(locals.GetValueOrDefault(2, DietSimEntry.Unknown)); break;
                case Code.Ldloc_3: stack.Push(locals.GetValueOrDefault(3, DietSimEntry.Unknown)); break;
                case Code.Ldloc_S: case Code.Ldloc:
                    stack.Push(locals.GetValueOrDefault(((VariableDefinition)instr.Operand).Index, DietSimEntry.Unknown));
                    break;

                case Code.Ldarg_0: case Code.Ldarg_1: case Code.Ldarg_2: case Code.Ldarg_3:
                case Code.Ldarg_S: case Code.Ldarg:
                case Code.Ldfld: case Code.Ldflda: case Code.Ldsflda: case Code.Ldtoken:
                    stack.Push(DietSimEntry.Unknown);
                    break;

                case Code.Add: case Code.Sub: case Code.Mul: case Code.Div: case Code.Rem:
                {
                    var b = PopEntry(stack);
                    var a = PopEntry(stack);
                    stack.Push(new DietSimEntry(Constant: ComputeArithmetic(instr.OpCode.Code, a.Constant, b.Constant)));
                    break;
                }

                case Code.Neg:
                    stack.Push(new DietSimEntry(Constant: AsFloat(PopEntry(stack).Constant) is float f ? -f : null));
                    break;

                case Code.Conv_R4: case Code.Conv_R8: case Code.Conv_I4: case Code.Conv_I8:
                case Code.Conv_U4: case Code.Conv_U8: case Code.Conv_I2: case Code.Conv_U2:
                case Code.Conv_I1: case Code.Conv_U1:
                case Code.Box: case Code.Unbox_Any: case Code.Castclass:
                    break;

                case Code.Newarr:
                    PopEntry(stack);
                    stack.Push(DietSimEntry.Unknown);
                    break;

                case Code.Stelem_Any: case Code.Stelem_I4: case Code.Stelem_R4:
                case Code.Stelem_I8: case Code.Stelem_R8: case Code.Stelem_Ref:
                    PopEntries(stack, 3);
                    break;

                case Code.Ldelem_Any: case Code.Ldelem_I4: case Code.Ldelem_R4: case Code.Ldelem_Ref:
                    PopEntries(stack, 2);
                    stack.Push(DietSimEntry.Unknown);
                    break;

                case Code.Stfld:
                    PopEntries(stack, 2);
                    break;

                case Code.Stsfld:
                    PopEntry(stack);
                    break;

                case Code.Newobj when instr.Operand is MethodReference ctor:
                    SimulateNewobj(instr, ctor, stack, sets, infos);
                    break;

                case Code.Call: case Code.Callvirt:
                    SimulateCall((MethodReference)instr.Operand, stack, sets, helperDiets);
                    break;

                case Code.Nop: case Code.Ret:
                    break;

                default:
                    // Branches and anything else the simulator does not model make the
                    // stack contents unknowable — reset it; sets and locals persist.
                    stack.Clear();
                    break;
            }
        }
    }

    private DietSimEntry SimulateLdsfld(FieldReference fr)
    {
        if (fr.DeclaringType.Name == "GameTags")
        {
            return new DietSimEntry(Tag: new DietTagValue(null, fr.Name));
        }

        if (fr.FieldType.Name == "Tag")
        {
            var resolved = ResolveStaticTagField(fr);
            return resolved is not null
                ? new DietSimEntry(Tag: new DietTagValue(resolved, null))
                : DietSimEntry.Unknown;
        }

        // Prefab id fields (DewDripConfig.ID) feed string→Tag conversions such as ToTag.
        if (fr.FieldType.MetadataType == MetadataType.String)
        {
            var text = ResolveStaticStringField(fr);
            return text is not null ? new DietSimEntry(Constant: text) : DietSimEntry.Unknown;
        }

        var numeric = _tuning.ResolveField(fr);
        return numeric is not null ? new DietSimEntry(Constant: numeric) : DietSimEntry.Unknown;
    }

    private void SimulateNewobj(
        Instruction instr, MethodReference ctor, Stack<DietSimEntry> stack,
        Dictionary<string, List<DietTagValue>> sets, List<DietInfoCall> infos)
    {
        var args = PopEntries(stack, ctor.Parameters.Count);

        if (IsTagCollectionType(ctor.DeclaringType))
        {
            var key = $"set@{instr.Offset}";
            sets[key] = new List<DietTagValue>();
            stack.Push(new DietSimEntry(SetKey: key));
            return;
        }

        if (ctor.DeclaringType.FullName == "Diet/Info" &&
            ctor.Parameters.Count > 0 && ctor.Parameters[0].ParameterType.Name.StartsWith("HashSet"))
        {
            infos.Add(new DietInfoCall(
                SetKey: args[0].SetKey,
                ProducedElementId: FindArgEntry(ctor, args, "produced_element")?.Tag?.ElementId,
                CaloriesPerKg: AsFloat(FindArgEntry(ctor, args, "calories_per_kg")?.Constant) ?? 0f,
                ConversionRate: AsFloat(FindArgEntry(ctor, args, "produced_conversion_rate")?.Constant) ?? 0f));
        }

        stack.Push(DietSimEntry.Unknown);
    }

    private void SimulateCall(
        MethodReference mr, Stack<DietSimEntry> stack,
        Dictionary<string, List<DietTagValue>> sets, List<ResolvedDiet> helperDiets)
    {
        // String→Tag conversions keep the string flowing as a Tag value.
        if (IsStringToTagCall(mr))
        {
            var arg = PopEntry(stack);
            stack.Push(arg.Constant is string tagName
                ? new DietSimEntry(Tag: new DietTagValue(tagName, null))
                : DietSimEntry.Unknown);
            return;
        }

        // ldc.i4 <simHash> → CreateTag: an element tag.
        if (mr.DeclaringType.Name == "GameTagExtensions" && mr.Name == "CreateTag" && mr.Parameters.Count >= 1)
        {
            var args = PopEntries(stack, mr.Parameters.Count);
            stack.Push(args[0].Constant is int hash && MapSimHash(hash) is string element
                ? new DietSimEntry(Tag: new DietTagValue(element, null))
                : DietSimEntry.Unknown);
            return;
        }

        // HashSet<Tag>/List<Tag>.Add on a tracked set records the consumed tag.
        if (mr.Name == "Add" && IsTagCollectionType(mr.DeclaringType))
        {
            var value = PopEntry(stack);
            var receiver = PopEntry(stack);
            if (receiver.SetKey is not null && value.Tag is not null && sets.TryGetValue(receiver.SetKey, out var tags))
            {
                tags.Add(value.Tag);
            }

            if (mr.ReturnType.MetadataType != MetadataType.Void) { stack.Push(DietSimEntry.Unknown); }
            return;
        }

        var argCount = mr.Parameters.Count + (mr.HasThis ? 1 : 0);
        var callArgs = PopEntries(stack, argCount);

        // Diet helper calls receiving the consumed tag as an argument, e.g.
        // BaseMooConfig.ExpandDiet(diet, prefab, "GasGrass".ToTag(), producedTag,
        // caloriesPerKg, producedConversionRate, ...): emit the diet from the arguments.
        if (mr.DeclaringType.Name.StartsWith("Base") &&
            mr.Parameters.Any(p => p.ParameterType.Name == "Tag" && p.Name.StartsWith("consumed")))
        {
            var offset = mr.HasThis ? 1 : 0;
            DietTagValue? consumed = null;
            string? produced = null;
            float calories = 0f;
            float conversion = 0f;

            for (int p = 0; p < mr.Parameters.Count && p + offset < callArgs.Length; p++)
            {
                var entry = callArgs[p + offset];
                var name = Normalize(mr.Parameters[p].Name);
                if (mr.Parameters[p].ParameterType.Name == "Tag" && name.StartsWith("consumed")) { consumed = entry.Tag; }
                else if (mr.Parameters[p].ParameterType.Name == "Tag" && name.StartsWith("produced")) { produced = entry.Tag?.ElementId; }
                else if (name.Contains("caloriesperkg")) { calories = AsFloat(entry.Constant) ?? 0f; }
                else if (name.Contains("conversionrate")) { conversion = AsFloat(entry.Constant) ?? 0f; }
            }

            if (consumed?.ElementId is not null || consumed?.CategoryTag is not null)
            {
                helperDiets.Add(new ResolvedDiet(consumed.ElementId, consumed.CategoryTag, produced, calories, conversion));
            }
        }

        if (mr.ReturnType.MetadataType != MetadataType.Void) { stack.Push(DietSimEntry.Unknown); }
    }

    private static bool IsTagCollectionType(TypeReference type) =>
        type is GenericInstanceType git &&
        (git.ElementType.Name == "HashSet`1" || git.ElementType.Name == "List`1") &&
        git.GenericArguments.Count == 1 &&
        git.GenericArguments[0].Name == "Tag";

    /// <summary>A <c>HashSet&lt;Tag&gt;</c>/<c>List&lt;Tag&gt;</c> <c>Add</c> call (consumed-set construction).</summary>
    private static bool IsHashSetTagAdd(Instruction instr) =>
        (instr.OpCode == OpCodes.Call || instr.OpCode == OpCodes.Callvirt) &&
        instr.Operand is MethodReference mr &&
        mr.Name == "Add" &&
        IsTagCollectionType(mr.DeclaringType);

    private static string Normalize(string name) => name.Replace("_", "").ToLowerInvariant();

    private static DietSimEntry? FindArgEntry(MethodReference ctor, DietSimEntry[] args, string paramName)
    {
        for (int p = 0; p < ctor.Parameters.Count && p < args.Length; p++)
        {
            if (ctor.Parameters[p].Name == paramName) { return args[p]; }
        }

        return null;
    }

    private static object? ComputeArithmetic(Code op, object? a, object? b)
    {
        if (AsFloat(a) is not float x || AsFloat(b) is not float y) { return null; }

        return op switch
        {
            Code.Add => x + y,
            Code.Sub => x - y,
            Code.Mul => x * y,
            Code.Div => y != 0f ? x / y : null,
            Code.Rem => y != 0f ? x % y : null,
            _ => null,
        };
    }

    private static float? AsFloat(object? value) => value switch
    {
        float f => f,
        int i => i,
        double d => (float)d,
        long l => l,
        _ => null,
    };

    private static DietSimEntry PopEntry(Stack<DietSimEntry> stack) =>
        stack.Count > 0 ? stack.Pop() : DietSimEntry.Unknown;

    private static DietSimEntry[] PopEntries(Stack<DietSimEntry> stack, int count)
    {
        var entries = new DietSimEntry[count];
        for (int i = count - 1; i >= 0; i--)
        {
            entries[i] = PopEntry(stack);
        }

        return entries;
    }

    /// <summary>
    /// The config's own methods plus every method it calls on a <c>Base*</c> helper type
    /// (BasePufferFish.CreatePrefab, BaseLightBugConfig.SetupDiet, ...), one level deep.
    /// </summary>
    private static List<MethodDefinition> DietMethodsFor(TypeDefinition config)
    {
        var methods = new List<MethodDefinition>();
        var seen = new HashSet<string>();

        foreach (var method in config.Methods.Where(m => m.HasBody))
        {
            if (seen.Add(method.FullName)) { methods.Add(method); }
        }

        foreach (var method in config.Methods.Where(m => m.HasBody))
        {
            foreach (var instr in method.Body.Instructions)
            {
                if (instr.OpCode != OpCodes.Call && instr.OpCode != OpCodes.Callvirt) { continue; }
                if (instr.Operand is not MethodReference mr) { continue; }
                if (!mr.DeclaringType.Name.StartsWith("Base")) { continue; }

                var resolved = TryResolve(mr);
                if (resolved?.HasBody == true && seen.Add(resolved.FullName)) { methods.Add(resolved); }
            }
        }

        return methods;
    }

    /// <summary>
    /// True for any call converting a string into a Tag: <c>Tag::op_Implicit</c>,
    /// <c>TagManager::Create(string)</c>, or the <c>ToTag(this string)</c> extension.
    /// </summary>
    private static bool IsStringToTagCall(MethodReference mr) =>
        (mr.DeclaringType.Name == "Tag" && mr.Name == "op_Implicit") ||
        (mr.DeclaringType.Name == "TagManager" && mr.Name == "Create" && mr.Parameters.Count == 1) ||
        (mr.Name == "ToTag" && mr.Parameters.Count == 1 && mr.Parameters[0].ParameterType.MetadataType == MetadataType.String);

    /// <summary>
    /// Resolve a static string field: a const literal directly from metadata, else the
    /// <c>ldstr; stsfld</c> assignment in the declaring type's .cctor.
    /// </summary>
    private static string? ResolveStaticStringField(FieldReference fr)
    {
        FieldDefinition? field;
        try { field = fr.Resolve(); }
        catch { return null; }

        if (field?.Constant is string literal) { return literal; }

        var cctor = field?.DeclaringType.Methods.FirstOrDefault(m => m.Name == ".cctor" && m.HasBody);
        if (cctor is null) { return null; }

        var ins = cctor.Body.Instructions;
        for (int i = 1; i < ins.Count; i++)
        {
            if (ins[i].OpCode == OpCodes.Stsfld && ins[i].Operand is FieldReference target &&
                target.Name == fr.Name &&
                ins[i - 1].OpCode == OpCodes.Ldstr && ins[i - 1].Operand is string value)
            {
                return value;
            }
        }

        return null;
    }

    /// <summary>
    /// Resolve the <c>produced_element</c> argument of a <c>Diet.Info</c> constructor by
    /// scanning backwards from the newobj: the nearest Tag-typed source before entering the
    /// consumed-set construction (a <c>HashSet::Add</c>) is the produced tag — either an
    /// inline <c>CreateTag</c> or a static Tag field on a <c>*Tuning</c> type (resolved from
    /// that type's static constructor, e.g. <c>PufferFishTuning.POOP_ELEMENT</c>).
    /// </summary>
    private string? ResolveProducedTagBefore(IList<Instruction> ins, int newobjIndex)
    {
        const int maxLookback = 48;
        for (int j = newobjIndex - 1; j >= 0 && newobjIndex - j <= maxLookback; j--)
        {
            var instr = ins[j];

            if (IsHashSetTagAdd(instr)) { return null; }

            if (instr.OpCode == OpCodes.Ldsfld && instr.Operand is FieldReference fr &&
                fr.FieldType.Name == "Tag")
            {
                return ResolveStaticTagField(fr);
            }

            if ((instr.OpCode == OpCodes.Call || instr.OpCode == OpCodes.Callvirt) &&
                instr.Operand is MethodReference mr &&
                mr.DeclaringType.Name == "GameTagExtensions" && mr.Name == "CreateTag" &&
                j >= 1 && IlHelper.TryReadConstant(ins[j - 1], out var c) && c is int hash)
            {
                return MapSimHash(hash);
            }
        }

        return null;
    }

    /// <summary>
    /// Read a static Tag field initialized in its declaring type's .cctor: either
    /// <c>ldc.i4 &lt;simHash&gt;; call CreateTag</c> (an element tag) or
    /// <c>ldstr; op_Implicit / TagManager::Create / newobj Tag(string)</c> (an item tag,
    /// e.g. <c>VineFruitConfig.ID</c>).
    /// </summary>
    private string? ResolveStaticTagField(FieldReference fr)
    {
        var declaring = TryResolveType(fr.DeclaringType);
        var cctor = declaring?.Methods.FirstOrDefault(m => m.Name == ".cctor" && m.HasBody);
        if (cctor is null) { return null; }

        var ins = cctor.Body.Instructions;
        for (int i = 0; i < ins.Count; i++)
        {
            if (ins[i].OpCode != OpCodes.Stsfld || ins[i].Operand is not FieldReference target) { continue; }
            if (target.Name != fr.Name || i < 2) { continue; }

            if (ins[i - 1].Operand is not MethodReference mr) { continue; }

            if (mr.DeclaringType.Name == "GameTagExtensions" && mr.Name == "CreateTag" &&
                IlHelper.TryReadConstant(ins[i - 2], out var c) && c is int hash)
            {
                return MapSimHash(hash);
            }

            bool isStringToTag =
                (mr.DeclaringType.Name == "Tag" && (mr.Name == "op_Implicit" || mr.Name == ".ctor")) ||
                (mr.DeclaringType.Name == "TagManager" && mr.Name == "Create" && mr.Parameters.Count == 1);
            if (isStringToTag && ins[i - 2].OpCode == OpCodes.Ldstr && ins[i - 2].Operand is string tagName)
            {
                return tagName;
            }
        }

        return null;
    }

    private static object? FindArg(MethodReference ctor, object?[] args, string paramName)
    {
        for (int p = 0; p < ctor.Parameters.Count && p < args.Length; p++)
        {
            if (ctor.Parameters[p].Name == paramName) { return args[p]; }
        }

        return null;
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
            m.DeclaringType.Name == "ElementLoader" && m.Name == "FindElementByTag");

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
        const int maxLookback = 24;
        for (int j = callIndex - 1; j >= 0 && callIndex - j <= maxLookback; j--)
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
