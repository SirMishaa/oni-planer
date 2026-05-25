using Mono.Cecil;
using Mono.Cecil.Cil;
using Mono.Cecil.Rocks;
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

        // Detection: IEntityConfig types that call EntityTemplates.ExtendEntityToBasicPlant.
        // This matches the CritterExtractor pattern (ExtendEntityToWildCreature) and is verified
        // against the actual DLL — 41 plant configs found this way.
        var plantConfigs = module.GetAllTypes()
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

    private static bool IsPlantConfig(TypeDefinition type)
    {
        if (!type.HasMethods) return false;
        return type.Methods.Any(m => m.HasBody && m.Body.Instructions.Any(i =>
            (i.OpCode == OpCodes.Call || i.OpCode == OpCodes.Callvirt) &&
            i.Operand is MethodReference mr &&
            mr.DeclaringType.Name == "EntityTemplates" &&
            mr.Name == "ExtendEntityToBasicPlant"));
    }

    private static PlantDto? ExtractPlant(TypeDefinition type)
    {
        var plantId = type.Name.EndsWith("Config") ? type.Name[..^6] : type.Name;

        float minTemp = 0f, maxTemp = 0f;
        float minPressure = 0f, maxPressure = 0f;
        float growthTime = 0f;
        string? nameKey = null;

        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            var instructions = method.Body.Instructions;

            for (int i = 0; i < instructions.Count; i++)
            {
                if ((instructions[i].OpCode != OpCodes.Call && instructions[i].OpCode != OpCodes.Callvirt) ||
                    instructions[i].Operand is not MethodReference mr ||
                    mr.DeclaringType.Name != "EntityTemplates" ||
                    mr.Name != "ExtendEntityToBasicPlant") continue;

                // ExtendEntityToBasicPlant signature (19 params, first is 'this' GameObject):
                //   [0]  GameObject template         (not a constant)
                //   [1]  float temperature_lethal_low
                //   [2]  float temperature_warning_low
                //   [3]  float temperature_warning_high
                //   [4]  float temperature_lethal_high
                //   [5]  SimHashes[] safe_elements   (array, not a constant)
                //   [6]  bool pressure_sensitive
                //   [7]  float pressure_lethal_low
                //   [8]  float pressure_warning_low
                //   [9]  string crop_id              (not used here)
                //   [10] bool can_drown
                //   [11] bool can_tinker
                //   [12] bool require_solid_tile
                //   [13] bool should_grow_old
                //   [14] float max_age               → growth time in seconds
                //   [15] float min_radiation
                //   [16] float max_radiation
                //   [17] string baseTraitId
                //   [18] string baseTraitName
                var args = IlHelper.ExtractCallArgs(instructions, i, mr.Parameters.Count);

                minTemp = args[1] is float f1 ? f1 : minTemp;
                maxTemp = args[4] is float f4 ? f4 : maxTemp;
                minPressure = args[7] is float f7 ? f7 : minPressure;
                maxPressure = args[8] is float f8 ? f8 : maxPressure;
                growthTime = args[14] is float f14 ? f14 : growthTime;

                // Name key: scan backward from the call for ldsfld of a STRINGS.CREATURES field.
                // Some configs store the name in a local first (stloc/ldloc), so we check
                // the surrounding region rather than relying on ExtractCallArgs for strings.
                if (nameKey == null)
                    nameKey = FindNameKeyNearCall(instructions, i);
            }
        }

        // Fall back to scanning all methods for a STRINGS.CREATURES ldsfld if not found near the call.
        if (nameKey == null)
            nameKey = ScanForNameKey(type);

        return new PlantDto(
            PlantId: plantId,
            VariantId: plantId,
            IsBase: true,
            MinTemp: minTemp,
            MaxTemp: maxTemp,
            MinPressure: minPressure,
            MaxPressure: maxPressure,
            AtmosphereElementId: null,
            LightRequired: false,
            GrowthTime: growthTime,
            NameLocalizationId: string.IsNullOrEmpty(nameKey) ? null : nameKey,
            DlcId: IlHelper.ExtractDlcId(type),
            Inputs: [],
            Outputs: []
        );
    }

    /// <summary>
    /// Scan backwards from the ExtendEntityToBasicPlant call for an ldsfld whose field
    /// is declared in a type whose name starts with "STRINGS.CREATURES" (or the LocString
    /// ldsfld operand whose declaring type name contains "SPECIES").
    /// Stops at the previous call boundary to avoid false positives.
    /// </summary>
    private static string? FindNameKeyNearCall(IList<Instruction> instructions, int callIndex)
    {
        for (int j = callIndex - 1; j >= 0; j--)
        {
            var instr = instructions[j];

            // Stop at previous call boundary (exclude stelem/newarr which are array building)
            if ((instr.OpCode == OpCodes.Call || instr.OpCode == OpCodes.Callvirt) &&
                instr.Operand is MethodReference mr2 &&
                mr2.DeclaringType.Name != "LocString") break;

            if (instr.OpCode == OpCodes.Ldsfld && instr.Operand is FieldReference fr)
            {
                // LocString fields in STRINGS.CREATURES.SPECIES.XYZ::NAME
                var declType = fr.DeclaringType.FullName;
                if (declType.Contains("STRINGS") && declType.Contains("CREATURES"))
                    return declType + "::" + fr.Name;
            }
        }
        return null;
    }

    /// <summary>
    /// Broad scan of all method bodies for STRINGS.CREATURES ldsfld instructions.
    /// </summary>
    private static string? ScanForNameKey(TypeDefinition type)
    {
        if (!type.HasMethods) return null;
        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            foreach (var instr in method.Body.Instructions)
            {
                if (instr.OpCode == OpCodes.Ldsfld && instr.Operand is FieldReference fr)
                {
                    var declType = fr.DeclaringType.FullName;
                    if (declType.Contains("STRINGS") && declType.Contains("CREATURES"))
                        return declType + "::" + fr.Name;
                }
            }
        }
        return null;
    }
}
