using Mono.Cecil;
using Mono.Cecil.Cil;
using Mono.Cecil.Rocks;
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

    private static readonly HashSet<string> TraitCreateMethods =
    [
        "CreateTrait",
        "CreateAttributeEffectTrait",
        "CreateComponentTrait",
        "CreateNamedTrait",
        "CreateDisabledTaskTrait",
        "CreateEffectModifierTrait",
        "CreateSkillGrantingTrait",
    ];

    public DuplicantTraitExtractor(string managedPath) => _managedPath = managedPath;

    public DuplicantDataDto Extract()
    {
        var module = ModuleDefinition.ReadModule(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        var traits = new List<DuplicantTraitDto>();
        var seen = new HashSet<string>();

        // Duplicant congenital traits are registered in TUNING.TRAITS.cctor via TraitUtil.Create*
        // and in BaseMinionConfig.AddMinionTraits via ModifierSet.CreateTrait.
        // We scope to these specific types to avoid picking up critter/plant traits.
        var duplicantTraitTypes = module.GetAllTypes()
            .Where(t => t.FullName == "TUNING.TRAITS" || t.Name == "BaseMinionConfig")
            .ToList();

        foreach (var type in duplicantTraitTypes)
        {
            foreach (var method in type.Methods)
            {
                if (!method.HasBody) continue;
                var instructions = method.Body.Instructions;

                for (int i = 0; i < instructions.Count; i++)
                {
                    var instr = instructions[i];
                    if (instr.OpCode != OpCodes.Call && instr.OpCode != OpCodes.Callvirt) continue;
                    if (instr.Operand is not MethodReference mr) continue;

                    bool isTraitUtilCreate = mr.DeclaringType.Name == "TraitUtil"
                        && TraitCreateMethods.Contains(mr.Name);
                    bool isModifierSetCreate = mr.DeclaringType.Name == "ModifierSet"
                        && mr.Name == "CreateTrait";

                    if (!isTraitUtilCreate && !isModifierSetCreate) continue;

                    // Collect all constants pushed in the call-site window
                    var consts = CollectLocalConstants(instructions, i);

                    // First string constant is always the trait ID
                    var traitId = consts.OfType<string>().FirstOrDefault();
                    if (traitId == null || !seen.Add(traitId)) continue;

                    bool isPositive = DeterminePositiveTrait(mr, consts);
                    var effects = ExtractEffects(mr, consts);

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

        var baseStats = ExtractBaseStats(module);
        return new DuplicantDataDto(BaseStats: baseStats, Traits: traits);
    }

    /// <summary>
    /// Scan backwards from callIndex to find the boundary (previous List.Add call), then
    /// collect all constant pushes (ldstr, ldc.i4.*, ldc.r4, ldnull) in order.
    /// </summary>
    private static List<object?> CollectLocalConstants(IList<Instruction> instructions, int callIndex)
    {
        int boundary = 0;
        for (int j = callIndex - 1; j >= 0; j--)
        {
            var prev = instructions[j];
            if (prev.OpCode == OpCodes.Callvirt
                && prev.Operand is MethodReference mr2 && mr2.Name == "Add")
            {
                boundary = j + 1;
                break;
            }
        }

        var consts = new List<object?>();
        for (int j = boundary; j < callIndex; j++)
        {
            var prev = instructions[j];
            switch (prev.OpCode.Code)
            {
                case Code.Ldstr:
                    consts.Add(prev.Operand as string);
                    break;
                case Code.Ldc_I4:
                    consts.Add((int)prev.Operand);
                    break;
                case Code.Ldc_I4_0:
                    consts.Add(0);
                    break;
                case Code.Ldc_I4_1:
                    consts.Add(1);
                    break;
                case Code.Ldc_I4_S:
                    consts.Add((int)(sbyte)prev.Operand);
                    break;
                case Code.Ldc_R4:
                    consts.Add((float)prev.Operand);
                    break;
                case Code.Ldnull:
                    consts.Add(null);
                    break;
            }
        }
        return consts;
    }

    /// <summary>
    /// Extract the positiveTrait flag from the collected constants.
    /// Pattern: the FIRST int constant in the window is positiveTrait.
    /// The trait ID string always comes before any bool, and name/desc come from LocString (non-constant).
    /// Special cases: CreateSkillGrantingTrait = always positive, CreateDisabledTaskTrait = always negative.
    /// </summary>
    private static bool DeterminePositiveTrait(MethodReference mr, List<object?> consts)
    {
        if (mr.Name == "CreateSkillGrantingTrait") return true;
        if (mr.Name == "CreateDisabledTaskTrait") return false;

        // For the array-based overload (6 params), positiveTrait is the last int
        if (mr.Name == "CreateAttributeEffectTrait" && mr.Parameters.Count == 6
            && mr.Parameters[3].ParameterType.IsArray)
        {
            for (int i = consts.Count - 1; i >= 0; i--)
            {
                if (consts[i] is int iv) return iv != 0;
            }
            return false;
        }

        // For all other overloads, the first int after the trait ID string is positiveTrait.
        // Strings for name/desc come from LocString calls (non-constant); attrId strings come next,
        // then float deltas (non-constant if from ldsfld); then the bool as int.
        bool passedId = false;
        foreach (var c in consts)
        {
            if (!passedId && c is string) { passedId = true; continue; }
            if (passedId && c is int iv) return iv != 0;
        }
        return false;
    }

    /// <summary>
    /// Extract stat effects. For attribute-modifying methods, the constants pattern is:
    ///   strings after the first = attribute IDs; floats = delta values; ints = bool flags.
    /// </summary>
    private static List<DuplicantTraitEffectDto> ExtractEffects(MethodReference mr, List<object?> consts)
    {
        var effects = new List<DuplicantTraitEffectDto>();

        if (mr.Name != "CreateAttributeEffectTrait" && mr.Name != "CreateTrait") return effects;

        // For CreateTrait with on_add delegate (params[3] is Action), no inline effects
        if (mr.Name == "CreateTrait" && mr.Parameters.Count >= 7
            && mr.Parameters[3].ParameterType.Name.StartsWith("Action")) return effects;

        // Skip first string (trait ID) and collect attribute pairs
        // Pattern: string attrId followed optionally by float delta, repeat
        bool skippedId = false;
        int idx = 0;
        while (idx < consts.Count)
        {
            var c = consts[idx];

            if (c is string s)
            {
                if (!skippedId) { skippedId = true; idx++; continue; } // skip trait ID

                // This string is an attribute ID — look ahead for a float delta
                float delta = 0f;
                if (idx + 1 < consts.Count && consts[idx + 1] is float fv)
                {
                    delta = fv;
                    idx += 2;
                }
                else
                {
                    idx++;
                }

                // Negative delta means negative effect (for attrs where delta from ldsfld is unknown)
                // Only add when we have a non-zero delta or when delta is clearly set as constant
                if (delta != 0f)
                {
                    effects.Add(new DuplicantTraitEffectDto(
                        Stat: ToSnakeCase(s),
                        Modifier: delta,
                        ModifierType: "add"
                    ));
                }
            }
            else
            {
                idx++;
            }
        }

        return effects;
    }

    private static DuplicantBaseStatsDto ExtractBaseStats(ModuleDefinition module)
    {
        float oxygenGs = 100f, co2Gs = 2f, massKg = 30f, bladder = 1f;
        int calories = 1000;

        var tuningType = module.GetAllTypes().FirstOrDefault(t =>
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
