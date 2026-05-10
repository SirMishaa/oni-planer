using Mono.Cecil;
using Mono.Cecil.Cil;

public static class IlHelper
{
    // Walk instructions forward, simulate a value stack tracking constants.
    // At `callIndex`, pop `paramCount` values from the simulated stack → args in order.
    public static object?[] ExtractCallArgs(IList<Instruction> instructions, int callIndex, int paramCount)
    {
        var stack = new Stack<object?>();

        for (int i = 0; i < callIndex; i++)
        {
            var instr = instructions[i];

            if (TryReadConstant(instr, out var constant))
            {
                stack.Push(constant);
                continue;
            }

            switch (instr.OpCode.Code)
            {
                // Non-constant pushes
                case Code.Ldloc_0: case Code.Ldloc_1: case Code.Ldloc_2: case Code.Ldloc_3:
                case Code.Ldloc_S: case Code.Ldloc:
                case Code.Ldarg_0: case Code.Ldarg_1: case Code.Ldarg_2: case Code.Ldarg_3:
                case Code.Ldarg_S: case Code.Ldarg:
                case Code.Ldfld: case Code.Ldsfld: case Code.Ldsflda: case Code.Ldflda:
                case Code.Ldnull: case Code.Ldtoken:
                case Code.Dup:
                case Code.Sizeof:
                    stack.Push(null);
                    break;

                // Pops (no push)
                case Code.Pop:
                case Code.Stloc_0: case Code.Stloc_1: case Code.Stloc_2: case Code.Stloc_3:
                case Code.Stloc_S: case Code.Stloc:
                case Code.Stfld: case Code.Stsfld:
                    if (stack.Count > 0) stack.Pop();
                    break;

                // Newarr: pops length, pushes array ref
                case Code.Newarr:
                    if (stack.Count > 0) stack.Pop();
                    stack.Push(null);
                    break;

                // Newobj: pop ctor args, push instance
                case Code.Newobj:
                {
                    var ctor = (MethodReference)instr.Operand;
                    int ctorArgs = ctor.Parameters.Count;
                    for (int j = 0; j < ctorArgs && stack.Count > 0; j++) stack.Pop();
                    stack.Push(null);
                    break;
                }

                // Call/Callvirt: pop args (+ instance for callvirt), push return if non-void
                case Code.Call: case Code.Callvirt:
                {
                    var mr = (MethodReference)instr.Operand;
                    int argsToPop = mr.Parameters.Count;
                    if (instr.OpCode.Code == Code.Callvirt || mr.HasThis) argsToPop++;
                    for (int j = 0; j < argsToPop && stack.Count > 0; j++) stack.Pop();
                    if (mr.ReturnType.MetadataType != MetadataType.Void)
                        stack.Push(null);
                    break;
                }

                // Stelem: pops value, index, array (no push)
                case Code.Stelem_Any: case Code.Stelem_I4: case Code.Stelem_R4:
                case Code.Stelem_I8: case Code.Stelem_R8: case Code.Stelem_Ref:
                    for (int j = 0; j < 3 && stack.Count > 0; j++) stack.Pop();
                    break;

                // Ldelem: pops index+array, pushes element
                case Code.Ldelem_Any: case Code.Ldelem_I4: case Code.Ldelem_R4:
                case Code.Ldelem_Ref:
                    for (int j = 0; j < 2 && stack.Count > 0; j++) stack.Pop();
                    stack.Push(null);
                    break;

                // Box/Unbox/Castclass: replace top
                case Code.Box: case Code.Unbox_Any: case Code.Castclass:
                    break;

                // Initobj: pops address (no push)
                case Code.Initobj:
                    if (stack.Count > 0) stack.Pop();
                    break;
            }
        }

        var args = new object?[paramCount];
        // Pop last arg first (stack is LIFO, last param pushed is on top)
        for (int i = paramCount - 1; i >= 0; i--)
        {
            if (stack.Count > 0) args[i] = stack.Pop();
        }
        return args;
    }

    public static bool TryReadConstant(Instruction instr, out object? value)
    {
        value = instr.OpCode.Code switch
        {
            Code.Ldstr => instr.Operand,
            Code.Ldc_I4 => (int)instr.Operand,
            Code.Ldc_I4_0 => 0, Code.Ldc_I4_1 => 1,
            Code.Ldc_I4_2 => 2, Code.Ldc_I4_3 => 3,
            Code.Ldc_I4_4 => 4, Code.Ldc_I4_5 => 5,
            Code.Ldc_I4_6 => 6, Code.Ldc_I4_7 => 7,
            Code.Ldc_I4_8 => 8, Code.Ldc_I4_M1 => -1,
            Code.Ldc_I4_S => (int)(sbyte)instr.Operand,
            Code.Ldc_R4 => (float)instr.Operand,
            Code.Ldc_R8 => (double)instr.Operand,
            Code.Ldc_I8 => (long)instr.Operand,
            _ => null
        };
        // null from switch means either Ldnull or not a constant
        return value != null || instr.OpCode.Code == Code.Ldnull;
    }

    // Build a dictionary of SimHashes enum: int value → string name (= element ID)
    public static Dictionary<int, string> BuildSimHashesMap(ModuleDefinition module)
    {
        var simHashesType = module.Types.FirstOrDefault(t => t.Name == "SimHashes");
        if (simHashesType == null) return [];

        return simHashesType.Fields
            .Where(f => f.IsStatic && f.IsLiteral && f.Constant is int)
            .ToDictionary(f => (int)f.Constant!, f => f.Name);
    }

    // Find all instructions of type `newobj <typeName>` across a method
    public static IEnumerable<(int Index, Instruction Instr)> FindNewobj(
        IList<Instruction> instructions, string typeName) =>
        instructions
            .Select((instr, idx) => (idx, instr))
            .Where(x => x.instr.OpCode == OpCodes.Newobj &&
                        x.instr.Operand is MethodReference mr &&
                        mr.DeclaringType.Name == typeName);

    // Scan forward from `startIndex`; for each stfld instruction, call handler(fieldName, value)
    // Stop when we hit another newobj of same type or a return
    public static void ReadFieldStores(
        IList<Instruction> instructions, int startIndex,
        Action<string, object?> handler)
    {
        for (int i = startIndex; i < instructions.Count; i++)
        {
            var instr = instructions[i];
            if (instr.OpCode == OpCodes.Ret) break;
            if (instr.OpCode == OpCodes.Stfld && instr.Operand is FieldReference fr)
            {
                // The value is one instruction before the stfld (preceded by ldloc or dup)
                // Look back for the most recent constant
                for (int j = i - 1; j >= startIndex; j--)
                {
                    if (TryReadConstant(instructions[j], out var val))
                    {
                        handler(fr.Name, val);
                        break;
                    }
                    // Stop at store/load instructions that indicate a different expression
                    if (instructions[j].OpCode == OpCodes.Ldloc_0 ||
                        instructions[j].OpCode == OpCodes.Ldloc_S ||
                        instructions[j].OpCode == OpCodes.Ldloc)
                        break;
                }
            }
        }
    }
}
