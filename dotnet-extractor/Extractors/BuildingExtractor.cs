using Mono.Cecil;
using Mono.Cecil.Cil;
using Mono.Cecil.Rocks;
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

        foreach (var type in module.GetAllTypes())
        {
            if (type.BaseType?.Name != "IBuildingConfig")
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

        string category = powerGen.HasValue ? "Power"
            : powerCon.HasValue ? "Power"
            : "Other";

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
