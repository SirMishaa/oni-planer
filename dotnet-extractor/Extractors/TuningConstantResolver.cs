using Mono.Cecil;
using Mono.Cecil.Cil;

/// <summary>
/// Resolves constant values of static *Tuning fields by simulating the type's
/// static constructor (.cctor) with a small forward stack machine. Handles float
/// (ldc.r4) and int (ldc.i4*) literals, const (IsLiteral) fields, ldsfld
/// indirections (cross-type, and same-type fields assigned earlier in the same
/// .cctor), and simple arithmetic (add/sub/mul/div/rem/neg, conv.*) — e.g.
/// <c>CALORIES_PER_DRIP_EATEN = ChameleonTuning.STANDARD_CALORIES_PER_CYCLE / DRIPS_EATEN_PER_CYCLE</c>.
/// Fields whose value the simulator cannot follow are left unresolved
/// (TryResolve returns false).
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

    /// <summary>
    /// Resolve a static numeric field referenced from any method body (used by IL
    /// argument extraction). Returns null when the field's value is not statically known.
    /// </summary>
    public float? ResolveField(FieldReference fieldRef)
    {
        var field = TryResolveFieldReference(fieldRef);
        if (field is null) { return null; }

        return TryResolve(field.DeclaringType, field.Name, out var value) ? value : null;
    }

    private Dictionary<string, float> MapFor(TypeDefinition tuningType)
    {
        if (_cache.TryGetValue(tuningType.FullName, out var cached)) { return cached; }

        // Guard against cycles — return empty map if we're already resolving this type.
        if (!_resolving.Add(tuningType.FullName)) { return new Dictionary<string, float>(); }

        try
        {
            var map = new Dictionary<string, float>();

            // const fields (e.g. PufferFishTuning.STANDARD_CALORIES_PER_CYCLE = 400000f)
            // never appear in the .cctor: their value lives in metadata.
            foreach (var field in tuningType.Fields.Where(f => f.IsStatic && f.IsLiteral))
            {
                if (field.Constant is float f) { map[field.Name] = f; }
                else if (field.Constant is int iv) { map[field.Name] = iv; }
                else if (field.Constant is double d) { map[field.Name] = (float)d; }
            }

            var cctor = tuningType.Methods.FirstOrDefault(m => m.Name == ".cctor" && m.HasBody);
            if (cctor != null)
            {
                SimulateCctor(tuningType, cctor, map);
            }

            _cache[tuningType.FullName] = map;
            return map;
        }
        finally
        {
            _resolving.Remove(tuningType.FullName);
        }
    }

    /// <summary>
    /// Forward stack simulation of a .cctor, recording every <c>stsfld</c> whose value
    /// is a statically-known number. Unknown values propagate as null; any control-flow
    /// instruction clears the stack (already-recorded fields are kept). Same-type
    /// <c>ldsfld</c> reads the partial <paramref name="map"/>, so fields computed from
    /// earlier fields of the same type resolve in declaration order.
    /// </summary>
    private void SimulateCctor(TypeDefinition tuningType, MethodDefinition cctor, Dictionary<string, float> map)
    {
        var stack = new Stack<float?>();
        var locals = new Dictionary<int, float?>();

        foreach (var instr in cctor.Body.Instructions)
        {
            if (IlHelper.TryReadConstant(instr, out var constant))
            {
                stack.Push(constant switch
                {
                    float f => f,
                    int iv => iv,
                    double d => (float)d,
                    long l => l,
                    _ => null,
                });
                continue;
            }

            switch (instr.OpCode.Code)
            {
                case Code.Ldsfld when instr.Operand is FieldReference fr:
                    if (fr.DeclaringType.FullName == tuningType.FullName)
                    {
                        stack.Push(map.TryGetValue(fr.Name, out var own) ? own : null);
                    }
                    else
                    {
                        stack.Push(ResolveField(fr));
                    }

                    break;

                case Code.Stsfld when instr.Operand is FieldReference target:
                    if (Pop(stack) is float value) { map[target.Name] = value; }
                    break;

                case Code.Add: case Code.Sub: case Code.Mul: case Code.Div: case Code.Rem:
                {
                    var b = Pop(stack);
                    var a = Pop(stack);
                    stack.Push(Compute(instr.OpCode.Code, a, b));
                    break;
                }

                case Code.Neg:
                    stack.Push(-Pop(stack));
                    break;

                // Numeric conversions keep the (float-typed) top of stack as-is.
                case Code.Conv_R4: case Code.Conv_R8: case Code.Conv_I4: case Code.Conv_I8:
                case Code.Conv_U4: case Code.Conv_U8: case Code.Conv_I2: case Code.Conv_U2:
                case Code.Conv_I1: case Code.Conv_U1:
                    break;

                case Code.Dup:
                    stack.Push(stack.Count > 0 ? stack.Peek() : null);
                    break;

                case Code.Pop:
                    Pop(stack);
                    break;

                case Code.Stloc_0: locals[0] = Pop(stack); break;
                case Code.Stloc_1: locals[1] = Pop(stack); break;
                case Code.Stloc_2: locals[2] = Pop(stack); break;
                case Code.Stloc_3: locals[3] = Pop(stack); break;
                case Code.Stloc_S: case Code.Stloc:
                    locals[((VariableDefinition)instr.Operand).Index] = Pop(stack);
                    break;

                case Code.Ldloc_0: stack.Push(locals.GetValueOrDefault(0)); break;
                case Code.Ldloc_1: stack.Push(locals.GetValueOrDefault(1)); break;
                case Code.Ldloc_2: stack.Push(locals.GetValueOrDefault(2)); break;
                case Code.Ldloc_3: stack.Push(locals.GetValueOrDefault(3)); break;
                case Code.Ldloc_S: case Code.Ldloc:
                    stack.Push(locals.GetValueOrDefault(((VariableDefinition)instr.Operand).Index));
                    break;

                case Code.Newobj:
                {
                    var ctor = (MethodReference)instr.Operand;
                    PopMany(stack, ctor.Parameters.Count);
                    stack.Push(null);
                    break;
                }

                case Code.Call: case Code.Callvirt:
                {
                    var mr = (MethodReference)instr.Operand;
                    var argsToPop = mr.Parameters.Count + (mr.HasThis ? 1 : 0);
                    PopMany(stack, argsToPop);
                    if (mr.ReturnType.MetadataType != MetadataType.Void) { stack.Push(null); }
                    break;
                }

                case Code.Newarr:
                    Pop(stack);
                    stack.Push(null);
                    break;

                case Code.Stelem_Any: case Code.Stelem_I4: case Code.Stelem_R4:
                case Code.Stelem_I8: case Code.Stelem_R8: case Code.Stelem_Ref:
                    PopMany(stack, 3);
                    break;

                case Code.Ldsflda: case Code.Ldstr: case Code.Ldnull: case Code.Ldtoken:
                    stack.Push(null);
                    break;

                case Code.Box: case Code.Unbox_Any: case Code.Castclass:
                    break;

                case Code.Nop: case Code.Ret:
                    break;

                default:
                    // Branches and anything else the simulator does not model make the
                    // stack contents unknowable — reset and keep going for later stores.
                    stack.Clear();
                    break;
            }
        }
    }

    private static float? Compute(Code op, float? a, float? b)
    {
        if (a is not float x || b is not float y) { return null; }

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

    private static FieldDefinition? TryResolveFieldReference(FieldReference fieldRef)
    {
        try { return fieldRef.Resolve(); }
        catch { return null; }
    }

    private static float? Pop(Stack<float?> stack) => stack.Count > 0 ? stack.Pop() : null;

    private static void PopMany(Stack<float?> stack, int count)
    {
        for (int i = 0; i < count && stack.Count > 0; i++) { stack.Pop(); }
    }
}
