using Mono.Cecil;
using Mono.Cecil.Cil;
using Mono.Cecil.Rocks;
using System.Text.Json.Serialization;

public record RecipeItemDto(
    [property: JsonPropertyName("element_id")] string ElementId,
    [property: JsonPropertyName("amount")] float Amount
);

public record RecipeDto(
    [property: JsonPropertyName("building_id")] string BuildingId,
    [property: JsonPropertyName("duration")] float Duration,
    [property: JsonPropertyName("fabricators")] List<string>? Fabricators,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("inputs")] List<RecipeItemDto> Inputs,
    [property: JsonPropertyName("outputs")] List<RecipeItemDto> Outputs
);

public sealed class RecipeExtractor
{
    private readonly string _managedPath;

    public RecipeExtractor(string managedPath) => _managedPath = managedPath;

    public List<RecipeDto> Extract()
    {
        var module = ModuleDefinition.ReadModule(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        var simHashMap = IlHelper.BuildSimHashesMap(module);
        var results = new List<RecipeDto>();
        var seen = new HashSet<string>();

        foreach (var type in module.GetAllTypes())
        {
            foreach (var method in type.Methods)
            {
                if (!method.HasBody) { continue; }

                var instructions = method.Body.Instructions;

                for (int i = 0; i < instructions.Count; i++)
                {
                    var instr = instructions[i];
                    if (instr.OpCode != OpCodes.Newobj) { continue; }
                    if (instr.Operand is not MethodReference ctor) { continue; }
                    if (ctor.DeclaringType.Name != "ComplexRecipe") { continue; }

                    var dto = ExtractRecipe(instructions, i, ctor, simHashMap);
                    if (dto != null)
                    {
                        string key = $"{dto.BuildingId}|{string.Join(",", dto.Inputs.Select(x => x.ElementId))}|{string.Join(",", dto.Outputs.Select(x => x.ElementId))}";
                        if (seen.Add(key)) { results.Add(dto); }
                    }
                }
            }
        }

        return results;
    }

    private RecipeDto? ExtractRecipe(
        IList<Instruction> instructions,
        int newobjIdx,
        MethodReference ctor,
        Dictionary<int, string> simHashMap)
    {
        // The ComplexRecipe ctor has signature:
        //   (String id, RecipeElement[] ingredients, RecipeElement[] results [, extra...])
        // The IL before newobj pushes these in order. The ingredients array is param[1]
        // and results is param[2], so they are at ctor.Parameters.Count - 2 and
        // ctor.Parameters.Count - 1 positions counting from the last ldloc/ldsfld before newobj.
        //
        // We find the ldloc for ingredients and results by scanning backward through the
        // ctor args. The ctor params are pushed left-to-right, so the last arg pushed is
        // just before newobj. We skip exactly (paramCount - 2 - 1) values to get past extra
        // args (HEP ints, DLC string arrays) back to the results then ingredients ldloc.

        int paramCount = ctor.Parameters.Count;

        // Better approach: find all ldloc instructions immediately before newobj (up to paramCount back),
        // looking for the pattern ldloc[ingr], ldloc[results], [extra args...], newobj.
        // The ingredients and results ldloc are at positions:
        //   results: newobjIdx - 1 - extraParamCount (if extra args are single instructions)
        //   ingr:    newobjIdx - 2 - extraParamCount
        // But extra params may span multiple instructions (e.g., newarr + fill for DLC string array).
        // Instead, we scan backward from newobjIdx to find the two contiguous ldloc instructions
        // that are the last two ldloc before the extra args.

        // Reliable method: find ldloc by matching to stloc patterns.
        // The pattern near newobj ComplexRecipe is always:
        //   ldloc X  (ingredients)
        //   ldloc Y  (results)
        //   [extra args] (0 to N instructions for HEP ints, DLC arrays, etc.)
        //   newobj ComplexRecipe
        //
        // We scan backward from newobjIdx, skipping extra args to find ldloc Y then ldloc X.

        // Find the two ldloc that load ingr and results arrays.
        // Walk backward collecting ldloc indices, stopping once we find two consecutive ldlocs.
        int resultsLocalIdx = -1;
        int ingrLocalIdx = -1;
        var ldlocSequence = new List<(int pos, int localIdx)>();
        int j = newobjIdx - 1;

        while (j >= 0 && ldlocSequence.Count < 2)
        {
            int localIdx = GetLdlocIndex(instructions[j]);
            if (localIdx >= 0)
            {
                ldlocSequence.Insert(0, (j, localIdx));
            }
            else
            {
                // If we've already started finding ldlocs, stop - extra args are between newobj and ldlocs
                // But if we haven't found any yet, keep scanning past extra args
                if (ldlocSequence.Count > 0) { break; }
            }

            j--;
        }

        // If we didn't find two ldlocs by going backward ignoring non-ldlocs, try a different scan:
        // find exactly the last two ldloc instructions before newobjIdx
        const int LdlocScanWindow = 20; // max instructions to scan back when local tracking fails

        if (ldlocSequence.Count < 2)
        {
            ldlocSequence.Clear();
            for (int k = newobjIdx - 1; k >= Math.Max(0, newobjIdx - LdlocScanWindow); k--)
            {
                int localIdx = GetLdlocIndex(instructions[k]);
                if (localIdx >= 0)
                {
                    ldlocSequence.Insert(0, (k, localIdx));
                    if (ldlocSequence.Count == 2) { break; }
                }
            }
        }

        if (ldlocSequence.Count < 2) { return null; }

        ingrLocalIdx = ldlocSequence[0].localIdx;
        resultsLocalIdx = ldlocSequence[1].localIdx;

        // Find stloc positions for these local variables (searching backward)
        int ingrStlocPos = -1;
        int resultsStlocPos = -1;
        int searchStart = ldlocSequence[0].pos - 1;

        for (int k = searchStart; k >= 0; k--)
        {
            int stIdx = GetStlocIndex(instructions[k]);
            if (stIdx < 0) { continue; }

            if (stIdx == resultsLocalIdx && resultsStlocPos < 0)
            {
                resultsStlocPos = k;
            }
            else if (stIdx == ingrLocalIdx && ingrStlocPos < 0)
            {
                ingrStlocPos = k;
            }

            if (ingrStlocPos >= 0 && resultsStlocPos >= 0) { break; }
        }

        if (ingrStlocPos < 0 || resultsStlocPos < 0) { return null; }

        // Find newarr before each stloc — don't stop at intermediate stlocs (temp SimHash locals etc.)
        int ingrNewarrPos = FindNewarrBefore(instructions, ingrStlocPos);
        int resultsNewarrPos = FindNewarrBefore(instructions, resultsStlocPos);

        if (ingrNewarrPos < 0 || resultsNewarrPos < 0) { return null; }

        var inputs = ExtractElements(instructions, ingrNewarrPos, ingrStlocPos, simHashMap);
        var outputs = ExtractElements(instructions, resultsNewarrPos, resultsStlocPos, simHashMap);

        if (inputs.Count == 0 && outputs.Count == 0) { return null; }

        // Scan forward from newobj to extract time, fabricators
        float duration = 0f;
        var fabricators = new List<string>();

        for (int i = newobjIdx + 1; i < Math.Min(instructions.Count, newobjIdx + 200); i++)
        {
            var instr = instructions[i];

            if (instr.OpCode == OpCodes.Stfld && instr.Operand is FieldReference fr &&
                fr.DeclaringType.Name == "ComplexRecipe")
            {
                if (fr.Name == "time")
                {
                    // time is set via: ldc.r4 X [mul Y] stfld time
                    // OR: ldsfld float TUNING.X stfld time
                    // OR: ldsfld int TUNING.X conv.r4 [mul Y] stfld time
                    // We look backward for ldc.r4 or conv.r4 (the latter implies ldsfld int before it)
                    for (int k = i - 1; k >= newobjIdx; k--)
                    {
                        var ki = instructions[k];

                        if (ki.OpCode == OpCodes.Ldc_R4)
                        {
                            duration = (float)ki.Operand;
                            break;
                        }

                        if (ki.OpCode == OpCodes.Ldc_R8)
                        {
                            duration = (float)(double)ki.Operand;
                            break;
                        }

                        // ldsfld float field → treated as non-zero sentinel (can't know value statically)
                        if (ki.OpCode == OpCodes.Ldsfld &&
                            ki.Operand is FieldReference dfr &&
                            dfr.FieldType.MetadataType == MetadataType.Single)
                        {
                            // Value unknown statically; leave duration as 0f.
                            break;
                        }

                        // Stop at structural boundaries
                        if (ki.OpCode == OpCodes.Stfld ||
                            ki.OpCode == OpCodes.Newobj) { break; }
                    }
                }

                if (fr.Name == "fabricators")
                {
                    // Pattern: newobj List<Tag>, dup, ldstr "BuildingId", call Tag::op_Implicit, callvirt Add, stfld fabricators
                    // Scan backward from stfld to find all ldstr values that are building IDs
                    var fabs = new List<string>();
                    for (int k = i - 1; k >= newobjIdx; k--)
                    {
                        var ki = instructions[k];

                        // Stop when we hit the List constructor
                        if (ki.OpCode == OpCodes.Newobj &&
                            ki.Operand is MethodReference mr2 &&
                            mr2.DeclaringType.FullName.Contains("List`1"))
                        {
                            break;
                        }

                        if (ki.OpCode == OpCodes.Ldstr && ki.Operand is string fab)
                        {
                            // Next instruction should be a Tag conversion call
                            if (k + 1 < instructions.Count)
                            {
                                var next = instructions[k + 1];
                                if (next.OpCode == OpCodes.Call &&
                                    next.Operand is MethodReference mr &&
                                    (mr.Name == "op_Implicit" || mr.Name == "Create" || mr.Name == "ToTag"))
                                {
                                    fabs.Insert(0, fab);
                                }
                            }
                        }
                    }

                    fabricators = fabs;
                }
            }
        }

        string buildingId = fabricators.Count > 0
            ? fabricators[0]
            : "Unknown";

        return new RecipeDto(
            BuildingId: buildingId,
            Duration: duration,
            Fabricators: fabricators.Count > 0 ? fabricators : null,
            NameLocalizationId: null,
            Inputs: inputs,
            Outputs: outputs
        );
    }

    /// <summary>
    /// Extract RecipeElement items between newarrPos (exclusive) and stlocPos (exclusive).
    /// For each newobj RecipeElement ctor, scan backward within the window to find the
    /// Tag identifier and amount.
    /// </summary>
    private List<RecipeItemDto> ExtractElements(
        IList<Instruction> instructions,
        int newarrPos,
        int stlocPos,
        Dictionary<int, string> simHashMap)
    {
        var items = new List<RecipeItemDto>();

        for (int i = newarrPos + 1; i < stlocPos; i++)
        {
            var instr = instructions[i];
            if (instr.OpCode != OpCodes.Newobj) { continue; }
            if (instr.Operand is not MethodReference ctor) { continue; }
            if (ctor.DeclaringType.Name != "RecipeElement") { continue; }

            // Scan backward from this newobj to find tag name and amount.
            // Stop at newarrPos or at a stelem.ref (previous element boundary).
            string? elementId = null;
            float amount = 0f;
            bool foundAmount = false;

            for (int j = i - 1; j >= newarrPos; j--)
            {
                var ji = instructions[j];

                // Stop at previous element boundary
                if (ji.OpCode == OpCodes.Stelem_Ref || ji.OpCode == OpCodes.Stelem_Any)
                {
                    break;
                }

                // Amount: ldc.r4 (but only first one from the right — the amount)
                if (!foundAmount && (ji.OpCode == OpCodes.Ldc_R4 || ji.OpCode == OpCodes.Ldc_R8))
                {
                    amount = ji.OpCode == OpCodes.Ldc_R4
                        ? (float)ji.Operand
                        : (float)(double)ji.Operand;
                    foundAmount = true;
                }

                if (elementId != null) { continue; }

                // Pattern 1: call GameTagExtensions::CreateTag(SimHashes)
                // Preceded by ldc.i4 HASH
                if (ji.OpCode == OpCodes.Call &&
                    ji.Operand is MethodReference mr &&
                    mr.Name == "CreateTag" &&
                    mr.DeclaringType.Name == "GameTagExtensions" &&
                    j > newarrPos)
                {
                    int? hash = GetInt32Value(instructions[j - 1]);
                    if (hash.HasValue && simHashMap.TryGetValue(hash.Value, out var elemName))
                    {
                        elementId = elemName;
                    }

                    continue;
                }

                // Pattern 2: call Tag::op_Implicit / ToTag / TagManager::Create
                // Preceded by ldstr "name" OR ldsfld SomeConfig::ID (string field)
                if (ji.OpCode == OpCodes.Call &&
                    ji.Operand is MethodReference mr2 &&
                    (mr2.Name == "op_Implicit" || mr2.Name == "ToTag" || mr2.Name == "Create") &&
                    j > newarrPos)
                {
                    var prev = instructions[j - 1];
                    if (prev.OpCode == OpCodes.Ldstr && prev.Operand is string s)
                    {
                        elementId = s;
                    }
                    else if (prev.OpCode == OpCodes.Ldsfld &&
                             prev.Operand is FieldReference sfr &&
                             sfr.FieldType.Name == "String")
                    {
                        // ldsfld string SomeConfig::ID → use declaring type name minus "Config"
                        string typeName = sfr.DeclaringType.Name;
                        elementId = typeName.EndsWith("Config")
                            ? typeName[..^6]
                            : sfr.Name == "ID" ? typeName : sfr.Name;
                    }

                    continue;
                }

                // Pattern 3: constrained SimHashes, callvirt ToString, call op_Implicit
                // Used for SimHash enum values stored in a local and converted to string at runtime
                if (ji.OpCode == OpCodes.Callvirt &&
                    ji.Operand is MethodReference mv &&
                    mv.Name == "ToString" &&
                    j >= newarrPos + 2)
                {
                    // Walk backward from ldloca to find ldc.i4 hash
                    for (int k = j - 1; k >= newarrPos; k--)
                    {
                        int? hash = GetInt32Value(instructions[k]);
                        if (hash.HasValue && simHashMap.TryGetValue(hash.Value, out var elemName2))
                        {
                            elementId = elemName2;
                            break;
                        }

                        if (instructions[k].OpCode == OpCodes.Stelem_Ref ||
                            instructions[k].OpCode == OpCodes.Stelem_Any) { break; }
                    }

                    if (elementId != null) { continue; }
                }

                // Pattern 4: ldsfld Tag SomeConfig::SOME_TAG — a static Tag field
                if (ji.OpCode == OpCodes.Ldsfld &&
                    ji.Operand is FieldReference tagField &&
                    tagField.FieldType.Name == "Tag")
                {
                    // Convert field name like BLEACH_STONE_TAG → BleachStone
                    string fieldName = tagField.Name;
                    if (fieldName.EndsWith("_TAG")) { fieldName = fieldName[..^4]; }
                    elementId = ToPascalCase(fieldName);
                    continue;
                }

                // Pattern 5: ldsfld Tag[] GameTags::Fabrics — a static Tag[] group field
                // Represents "any material from this group"
                if (ji.OpCode == OpCodes.Ldsfld &&
                    ji.Operand is FieldReference tagArrField &&
                    (tagArrField.FieldType.Name == "Tag[]" || tagArrField.FieldType.FullName.Contains("Tag[]")))
                {
                    elementId = tagArrField.Name; // e.g., "Fabrics", "BasicWoods"
                    continue;
                }
            }

            if (elementId != null || foundAmount)
            {
                items.Add(new RecipeItemDto(elementId ?? "Unknown", amount));
            }
        }

        return items;
    }

    /// <summary>
    /// Find the nearest newarr instruction before pos, scanning backward without stopping at stlocs
    /// (since array fills may use intermediate stloc for temp SimHash conversions).
    /// Stop if we encounter another newarr followed by a stloc that matches a different local
    /// (indicating we've crossed into a previous array construction region).
    /// </summary>
    private int FindNewarrBefore(IList<Instruction> instructions, int pos)
    {
        for (int j = pos - 1; j >= 0; j--)
        {
            if (instructions[j].OpCode == OpCodes.Newarr)
            {
                // Make sure this newarr is for RecipeElement (not some other array)
                if (instructions[j].Operand is TypeReference tr &&
                    tr.Name == "RecipeElement")
                {
                    return j;
                }
            }
        }

        return -1;
    }

    private static int GetLdlocIndex(Instruction instr)
    {
        return instr.OpCode.Code switch
        {
            Code.Ldloc_0 => 0,
            Code.Ldloc_1 => 1,
            Code.Ldloc_2 => 2,
            Code.Ldloc_3 => 3,
            Code.Ldloc_S => ((VariableReference)instr.Operand).Index,
            Code.Ldloc => ((VariableReference)instr.Operand).Index,
            _ => -1
        };
    }

    private static int GetStlocIndex(Instruction instr)
    {
        return instr.OpCode.Code switch
        {
            Code.Stloc_0 => 0,
            Code.Stloc_1 => 1,
            Code.Stloc_2 => 2,
            Code.Stloc_3 => 3,
            Code.Stloc_S => ((VariableReference)instr.Operand).Index,
            Code.Stloc => ((VariableReference)instr.Operand).Index,
            _ => -1
        };
    }

    private static int? GetInt32Value(Instruction instr)
    {
        return instr.OpCode.Code switch
        {
            Code.Ldc_I4 => (int)instr.Operand,
            Code.Ldc_I4_S => (int)(sbyte)instr.Operand,
            Code.Ldc_I4_0 => 0,
            Code.Ldc_I4_1 => 1,
            Code.Ldc_I4_2 => 2,
            Code.Ldc_I4_3 => 3,
            Code.Ldc_I4_4 => 4,
            Code.Ldc_I4_5 => 5,
            Code.Ldc_I4_6 => 6,
            Code.Ldc_I4_7 => 7,
            Code.Ldc_I4_8 => 8,
            Code.Ldc_I4_M1 => -1,
            _ => null
        };
    }

    /// <summary>
    /// Convert SCREAMING_SNAKE_CASE to PascalCase: BLEACH_STONE → BleachStone.
    /// </summary>
    private static string ToPascalCase(string name)
    {
        if (string.IsNullOrEmpty(name)) { return name; }

        return string.Concat(
            name.Split('_')
                .Where(part => part.Length > 0)
                .Select(part => char.ToUpper(part[0]) + part[1..].ToLower())
        );
    }
}
