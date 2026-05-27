using Mono.Cecil;
using Mono.Cecil.Cil;
using Mono.Cecil.Rocks;

/// <summary>
/// Extracts the building ID → category mapping from TUNING.BUILDINGS.PLANORDER in Assembly-CSharp.dll.
/// The PLANORDER static field is initialized in TUNING/BUILDINGS..cctor as a List of PlanInfo objects.
/// Each PlanInfo is constructed with: new PlanInfo(new HashedString("CategoryName"), bool, new List{string}{ "BuildingId", ... }, null, null)
/// </summary>
public sealed class BuildingCategoryExtractor
{
    private readonly string _managedPath;

    public BuildingCategoryExtractor(string managedPath) => _managedPath = managedPath;

    public Dictionary<string, string> Extract()
    {
        var module = ModuleDefinition.ReadModule(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        var allTypes = module.GetAllTypes().ToList();

        var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);

        var buildingsType = allTypes.FirstOrDefault(t => t.Name == "BUILDINGS");
        if (buildingsType == null) return result;

        var cctor = buildingsType.Methods.FirstOrDefault(m => m.Name == ".cctor");
        if (cctor?.HasBody != true) return result;

        var instructions = cctor.Body.Instructions.ToList();

        // Find the instruction index where PLANORDER is stored
        int planOrderStsfldIdx = -1;
        for (int i = 0; i < instructions.Count; i++)
        {
            if (instructions[i].OpCode == OpCodes.Stsfld &&
                instructions[i].Operand is FieldReference fr && fr.Name == "PLANORDER")
            {
                planOrderStsfldIdx = i;
                break;
            }
        }

        if (planOrderStsfldIdx < 0) return result;

        // Walk backwards through the instructions to parse the PLANORDER list construction.
        // Pattern for each category entry:
        //   ldstr "CategoryName"
        //   newobj HashedString..ctor(string)
        //   ldc.i4.0 / ldc.i4.1   (hideIfNotResearched)
        //   newobj List<string>..ctor()
        //   [dup + (ldstr / ldsfld) + callvirt List.Add]  × N  -- building IDs
        //   ldnull  (requiredDlcIds)
        //   ldnull  (forbiddenDlcIds)
        //   newobj PlanInfo..ctor(...)
        //   callvirt List<PlanInfo>.Add(...)
        //
        // We scan forward for each "newobj HashedString" followed by the pattern above.

        string? currentCategory = null;
        bool inBuildingList = false;

        for (int i = 0; i < planOrderStsfldIdx; i++)
        {
            var instr = instructions[i];

            // Detect: ldstr "CategoryName" then newobj HashedString
            if (instr.OpCode == OpCodes.Ldstr && instr.Operand is string catStr &&
                i + 1 < instructions.Count &&
                instructions[i + 1].OpCode == OpCodes.Newobj &&
                instructions[i + 1].Operand is MethodReference mr1 &&
                mr1.DeclaringType.Name == "HashedString")
            {
                currentCategory = catStr;
                inBuildingList = false;
            }

            // Detect: newobj List<string> — start collecting building IDs
            if (instr.OpCode == OpCodes.Newobj &&
                instr.Operand is MethodReference mr2 &&
                mr2.DeclaringType is GenericInstanceType git &&
                git.GenericArguments.Count == 1 &&
                git.GenericArguments[0].Name == "String" &&
                currentCategory != null)
            {
                inBuildingList = true;
            }

            // Detect: callvirt List<string>.Add — the previous ldstr/ldsfld is a building ID
            if (inBuildingList && currentCategory != null &&
                instr.OpCode == OpCodes.Callvirt &&
                instr.Operand is MethodReference mr3 &&
                mr3.Name == "Add" &&
                mr3.DeclaringType is GenericInstanceType git2 &&
                git2.GenericArguments.Count == 1 &&
                git2.GenericArguments[0].Name == "String")
            {
                // Look back for the building ID (ldstr or ldsfld)
                for (int j = i - 1; j >= 0; j--)
                {
                    var prev = instructions[j];
                    if (prev.OpCode == OpCodes.Ldstr && prev.Operand is string buildingId)
                    {
                        result[buildingId] = currentCategory;
                        break;
                    }
                    if (prev.OpCode == OpCodes.Ldsfld && prev.Operand is FieldReference sfr &&
                        sfr.Name == "ID")
                    {
                        // Static ID field — use the declaring type name minus "Config"
                        var typeName = sfr.DeclaringType.Name;
                        var id = typeName.EndsWith("Config")
                            ? typeName[..^6]
                            : typeName;
                        result[id] = currentCategory;
                        break;
                    }
                    if (prev.OpCode == OpCodes.Dup || prev.OpCode == OpCodes.Pop) continue;
                    break;
                }
            }

            // Detect: newobj PlanInfo — end of current category block
            if (instr.OpCode == OpCodes.Newobj &&
                instr.Operand is MethodReference mr4 &&
                mr4.DeclaringType.Name == "PlanInfo")
            {
                inBuildingList = false;
                currentCategory = null;
            }
        }

        // DLC buildings are added at runtime and are absent from the static PLANORDER.
        // Apply known category overrides for recognizable DLC building IDs.
        ApplyDlcOverrides(result);

        return result;
    }

    private static void ApplyDlcOverrides(Dictionary<string, string> result)
    {
        var overrides = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
        {
            // DLC1 rocket modules
            ["BatteryModule"]                     = "Rocketry",
            ["CO2Engine"]                         = "Rocketry",
            ["GasCargoBaySmall"]                  = "Rocketry",
            ["HabitatModuleMedium"]               = "Rocketry",
            ["HabitatModuleSmall"]                = "Rocketry",
            ["LiquidCargoBaySmall"]               = "Rocketry",
            ["NoseconeBasic"]                     = "Rocketry",
            ["NoseconeHarvest"]                   = "Rocketry",
            ["OrbitalCargoModule"]                = "Rocketry",
            ["PioneerModule"]                     = "Rocketry",
            ["ScoutModule"]                       = "Rocketry",
            ["SmallOxidizerTank"]                 = "Rocketry",
            ["SolidCargoBaySmall"]                = "Rocketry",
            ["SpecialCargoBayCluster"]            = "Rocketry",
            ["ArtifactCargoBay"]                  = "Rocketry",
            ["RocketInteriorGasInputPort"]        = "Rocketry",
            ["RocketInteriorGasOutputPort"]       = "Rocketry",
            ["RocketInteriorLiquidInputPort"]     = "Rocketry",
            ["RocketInteriorLiquidOutputPort"]    = "Rocketry",
            // DLC1 medical
            ["AdvancedApothecary"]                = "Medical",
            // DLC2 / Equipment
            ["TemporalTearOpener"]                = "Equipment",
            ["WarpConduitReceiver"]               = "Equipment",
            ["WarpConduitSender"]                 = "Equipment",
            // DLC2 Base
            ["WoodStorage"]                       = "Base",
        };

        foreach (var kv in overrides)
        {
            if (!result.ContainsKey(kv.Key))
                result[kv.Key] = kv.Value;
        }
    }
}
