using Mono.Cecil;
using Mono.Cecil.Cil;
using Mono.Cecil.Rocks;
using System.Text.Json.Serialization;

public record GeyserDto(
    [property: JsonPropertyName("geyser_id")] string GeyserId,
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

        // GeyserType instances are created via constructor calls in GeyserGenericConfig.GenerateConfigs,
        // not by GeyserConfigurator itself. Search all types to find the method with the most newobj calls.
        var allTypes = module.GetAllTypes();

        var populateMethod = allTypes
            .SelectMany(t => t.Methods)
            .Where(m => m.HasBody && m.Body.Instructions.Any(i =>
                i.OpCode == OpCodes.Newobj &&
                i.Operand is MethodReference mr &&
                mr.DeclaringType.FullName == geyserTypeNested.FullName))
            .OrderByDescending(m => m.Body.Instructions
                .Count(i => i.OpCode == OpCodes.Newobj &&
                            i.Operand is MethodReference mr &&
                            mr.DeclaringType.FullName == geyserTypeNested.FullName))
            .FirstOrDefault();

        if (populateMethod == null) return results;

        var instructions = populateMethod.Body.Instructions;

        for (int i = 0; i < instructions.Count; i++)
        {
            var instr = instructions[i];

            if (instr.OpCode == OpCodes.Newobj &&
                instr.Operand is MethodReference ctor &&
                ctor.DeclaringType.FullName == geyserTypeNested.FullName)
            {
                var args = IlHelper.ExtractCallArgs(instructions, i, ctor.Parameters.Count);
                var dto = BuildDtoFromCtorArgs(args, ctor, simHashes);
                if (dto != null) results.Add(dto);
            }
        }

        return results;
    }

    // GeyserType constructor parameter order (18-param variant):
    // [0]  string id
    // [1]  SimHashes element  (int)
    // [2]  GeyserShape shape  (int, ignored)
    // [3]  float temperature
    // [4]  float minRatePerCycle
    // [5]  float maxRatePerCycle
    // [6]  float maxPressure
    // [7]  string[] requiredDlcIds
    // [8]  string[] forbiddenDlcIds
    // [9]  float minIterationLength
    // [10] float maxIterationLength
    // [11] float minIterationPercent  (actual eruption duration in seconds, not a fraction)
    // [12] float maxIterationPercent  (actual eruption duration in seconds, not a fraction)
    // [13] float minYearLength
    // [14] float maxYearLength
    // [15] float minYearPercent  (ignored)
    // [16] float maxYearPercent  (ignored)
    // [17] float geyserTemperature (ignored)
    private GeyserDto? BuildDtoFromCtorArgs(object?[] args, MethodReference ctor, Dictionary<int, string> simHashes)
    {
        if (args.Length < 14) return null;
        if (args[0] is not string id) return null;

        string elementId = "Unknown";
        if (args[1] is int enumVal && simHashes.TryGetValue(enumVal, out var elemName))
            elementId = elemName;

        float temperature = args[3] is float t ? t : 0f;
        float minRatePerCycle = args[4] is float mnr ? mnr : 0f;
        float maxRatePerCycle = args[5] is float mxr ? mxr : 0f;
        float maxPressure = args[6] is float mp ? mp : 0f;
        float minIterLen = args[9] is float mil ? mil : 0f;
        float maxIterLen = args[10] is float mal ? mal : 0f;
        float minIterPct = args[11] is float mip ? mip : 0f;
        float maxIterPct = args[12] is float map ? map : 0f;
        float minYearLen = args[13] is float mnyl ? mnyl : 0f;
        float maxYearLen = args.Length > 14 && args[14] is float mxyl ? mxyl : 0f;

        string? dlcId = null;

        string type = id.Contains("Volcano") ? "volcano"
            : id.Contains("Vent") || id.Contains("vent") ? "vent"
            : "geyser";

        return new GeyserDto(
            GeyserId: id,
            Type: type,
            ElementId: elementId,
            Temperature: temperature,
            MaxPressure: maxPressure,
            MinYieldRate: minRatePerCycle,
            MaxYieldRate: maxRatePerCycle,
            MinEruptionDuration: minIterPct,
            MaxEruptionDuration: maxIterPct,
            MinEruptionPeriod: minIterLen,
            MaxEruptionPeriod: maxIterLen,
            DormancyMinCycles: minYearLen,
            DormancyMaxCycles: maxYearLen,
            NameLocalizationId: null,
            DlcId: dlcId
        );
    }
}
