using Mono.Cecil;
using Mono.Cecil.Cil;

/// <summary>
/// Resolves constant values of static *Tuning fields by reading the type's
/// static constructor (.cctor). Handles both float (ldc.r4) and int (ldc.i4*)
/// literals assigned directly via stsfld. Also handles a single ldsfld
/// indirection (e.g. PEN_SIZE_PER_CREATURE = TUNING.CREATURES/SPACE_REQUIREMENTS::TIER3).
/// Computed/non-literal fields are left unresolved (TryResolve returns false).
/// </summary>
public sealed class TuningConstantResolver
{
    private readonly ModuleDefinition _module;
    private readonly Dictionary<string, Dictionary<string, float>> _cache = new();

    // Track types currently being resolved to guard against cycles.
    private readonly HashSet<string> _resolving = new();

    public TuningConstantResolver(ModuleDefinition module) => _module = module;

    public bool TryResolve(TypeDefinition? tuningType, string fieldName, out float value)
    {
        value = 0f;
        if (tuningType == null) { return false; }
        var map = MapFor(tuningType);
        return map.TryGetValue(fieldName, out value);
    }

    private Dictionary<string, float> MapFor(TypeDefinition tuningType)
    {
        if (_cache.TryGetValue(tuningType.FullName, out var cached)) { return cached; }

        // Guard against cycles — return empty map if we're already resolving this type.
        if (!_resolving.Add(tuningType.FullName)) { return new Dictionary<string, float>(); }

        try
        {
            var map = new Dictionary<string, float>();
            var cctor = tuningType.Methods.FirstOrDefault(m => m.Name == ".cctor" && m.HasBody);
            if (cctor != null)
            {
                var ins = cctor.Body.Instructions;
                for (int i = 0; i < ins.Count; i++)
                {
                    if (ins[i].OpCode != OpCodes.Stsfld || ins[i].Operand is not FieldReference fr) { continue; }

                    // Walk backwards from the stsfld to find the value being stored.
                    for (int j = i - 1; j >= 0; j--)
                    {
                        var op = ins[j].OpCode.Code;

                        // Case 1: literal constant — use it directly.
                        if (IlHelper.TryReadConstant(ins[j], out var c) && c is not null)
                        {
                            if (c is float f) { map[fr.Name] = f; }
                            else if (c is int iv) { map[fr.Name] = iv; }
                            else if (c is double d) { map[fr.Name] = (float)d; }
                            break;
                        }

                        // Case 2: single ldsfld indirection — resolve the referenced field.
                        if (op == Code.Ldsfld && ins[j].Operand is FieldReference srcField)
                        {
                            var resolvedField = TryResolveFieldReference(srcField);
                            if (resolvedField != null)
                            {
                                var declaringType = resolvedField.DeclaringType;
                                var srcMap = MapFor(declaringType);
                                if (srcMap.TryGetValue(resolvedField.Name, out var indirect))
                                {
                                    map[fr.Name] = indirect;
                                }
                            }
                            break;
                        }

                        // Stop searching backward at any other side-effecting instruction.
                        if (op == Code.Call || op == Code.Callvirt || op == Code.Newobj || op == Code.Stsfld) { break; }
                    }
                }
            }

            _cache[tuningType.FullName] = map;
            return map;
        }
        finally
        {
            _resolving.Remove(tuningType.FullName);
        }
    }

    private static FieldDefinition? TryResolveFieldReference(FieldReference fieldRef)
    {
        try { return fieldRef.Resolve(); }
        catch { return null; }
    }
}
