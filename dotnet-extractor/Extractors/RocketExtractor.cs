using Mono.Cecil;
using Mono.Cecil.Cil;
using Mono.Cecil.Rocks;
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

    private static readonly string[] EngineKeywords = ["Engine", "Thruster"];
    private static readonly string[] ModuleKeywords =
    [
        "CargoBay", "NoseCone", "Nosecone", "Cockpit", "FuelTank",
        "OxidizerTank", "LandingModule", "CommandModule", "OrbitalResearch"
    ];

    public RocketExtractor(string managedPath) => _managedPath = managedPath;

    public RocketDataDto Extract()
    {
        var module = ModuleDefinition.ReadModule(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        var simHashes = IlHelper.BuildSimHashesMap(module);
        var engines = new List<RocketEngineDto>();
        var modules = new List<RocketModuleDto>();

        foreach (var type in module.GetAllTypes())
        {
            if (!InheritsIBuildingConfig(type)) { continue; }

            bool isEngine = EngineKeywords.Any(kw => type.Name.Contains(kw)) &&
                            HasFieldStore(type, "exhaustTemperature");
            bool isModule = !isEngine && ModuleKeywords.Any(kw => type.Name.Contains(kw));

            if (isEngine)
            {
                var engine = ExtractEngine(type, simHashes);
                if (engine != null) { engines.Add(engine); }
            }
            else if (isModule)
            {
                var mod = ExtractModule(type);
                if (mod != null) { modules.Add(mod); }
            }
        }

        return new RocketDataDto(Engines: engines, Modules: modules);
    }

    // IBuildingConfig is a base class (not an interface) — walk the BaseType chain
    private static bool InheritsIBuildingConfig(TypeDefinition type)
    {
        var current = type.BaseType;
        while (current != null)
        {
            if (current.Name == "IBuildingConfig") { return true; }
            try { current = current.Resolve()?.BaseType; }
            catch (AssemblyResolutionException) { break; }
        }
        return false;
    }

    private static bool HasFieldStore(TypeDefinition type, string fieldName) =>
        type.Methods.Any(m => m.HasBody && m.Body.Instructions.Any(i =>
            i.OpCode == OpCodes.Stfld &&
            i.Operand is FieldReference fr &&
            fr.Name == fieldName));

    // Extract the building ID from the first string arg to BuildingTemplates.CreateBuildingDef,
    // falling back to stripping "Config" from the class name.
    private static string ExtractBuildingId(TypeDefinition type)
    {
        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            var instructions = method.Body.Instructions;
            for (int i = 0; i < instructions.Count; i++)
            {
                var instr = instructions[i];
                if (instr.OpCode != OpCodes.Call && instr.OpCode != OpCodes.Callvirt) { continue; }
                if (instr.Operand is not MethodReference mr) { continue; }
                if (mr.DeclaringType.Name != "BuildingTemplates" || mr.Name != "CreateBuildingDef") { continue; }

                var args = IlHelper.ExtractCallArgs(instructions, i, mr.Parameters.Count);
                if (args.Length > 0 && args[0] is string id) { return id; }
            }
        }
        return type.Name.EndsWith("Config") ? type.Name[..^6] : type.Name;
    }

    private static RocketEngineDto? ExtractEngine(TypeDefinition type, Dictionary<int, string> simHashes)
    {
        var engineId = ExtractBuildingId(type);
        string? fuelId = null, oxidizerId = null, nameKey = null, dlcId = null;
        float maxRange = 0f, fuelRate = 0f, oxRate = 0f, exhaustTemp = 0f;

        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            var instructions = method.Body.Instructions;
            for (int i = 0; i < instructions.Count; i++)
            {
                var instr = instructions[i];

                // Pattern: ldc.i4 <simHash>; call FindElementByHash; ldfld tag; stfld fuelTag
                if (instr.OpCode == OpCodes.Stfld &&
                    instr.Operand is FieldReference fr &&
                    fr.Name == "fuelTag" &&
                    i >= 3)
                {
                    // Find the ldc.i4 before the FindElementByHash call
                    for (int j = i - 1; j >= Math.Max(0, i - 5); j--)
                    {
                        if (IlHelper.TryReadConstant(instructions[j], out var hashVal) && hashVal is int hashInt)
                        {
                            if (simHashes.TryGetValue(hashInt, out var elemName)) { fuelId = elemName; }
                            break;
                        }
                    }
                }

                // exhaustTemperature is stored with a direct ldc.r4 constant
                if (instr.OpCode == OpCodes.Stfld &&
                    instr.Operand is FieldReference fr2 &&
                    fr2.Name == "exhaustTemperature" &&
                    i > 0 &&
                    IlHelper.TryReadConstant(instructions[i - 1], out var tempVal))
                {
                    exhaustTemp = tempVal is float f ? f : exhaustTemp;
                }

                // maxRange / fuelConsumptionRate / oxidizerConsumptionRate (older DLL versions)
                if (instr.OpCode == OpCodes.Stfld && instr.Operand is FieldReference fr3)
                {
                    var prev = i > 0 ? instructions[i - 1] : null;
                    if (prev != null && IlHelper.TryReadConstant(prev, out var val))
                    {
                        switch (fr3.Name)
                        {
                            case "MaxRange" or "maxRange": maxRange = val is float f1 ? f1 : maxRange; break;
                            case "FuelConsumptionRate" or "fuelConsumptionRate": fuelRate = val is float f2 ? f2 : fuelRate; break;
                            case "OxidizerConsumptionRate" or "oxidizerConsumptionRate": oxRate = val is float f3 ? f3 : oxRate; break;
                        }
                    }
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

    private static RocketModuleDto? ExtractModule(TypeDefinition type)
    {
        var moduleId = ExtractBuildingId(type);
        float mass = 0f, capacity = 0f, powerCon = 0f;
        string? nameKey = null, dlcId = null;

        string moduleType = type.Name switch
        {
            var n when n.Contains("Liquid") => "cargo_liquid",
            var n when n.Contains("Gas") => "cargo_gas",
            var n when n.Contains("NoseCone") || n.Contains("Nosecone") || n.Contains("Cockpit") => "nosecone",
            var n when n.Contains("Oxidizer") => "oxidizer",
            var n when n.Contains("FuelTank") => "fuel",
            _ => "cargo_solid"
        };

        foreach (var method in type.Methods.Where(m => m.HasBody))
        {
            IlHelper.ReadFieldStores(method.Body.Instructions, 0, (fieldName, val) =>
            {
                switch (fieldName)
                {
                    case "Mass" or "mass": mass = val is float f1 ? f1 : mass; break;
                    case "Capacity" or "capacity" or "capacityKg" or "Storage": capacity = val is float f2 ? f2 : capacity; break;
                    case "EnergyConsumptionWhenActive": powerCon = val is float f3 ? f3 : powerCon; break;
                }
            });
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
