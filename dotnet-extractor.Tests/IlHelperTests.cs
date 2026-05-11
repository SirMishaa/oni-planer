using Mono.Cecil;
using Mono.Cecil.Cil;

public class IlHelperTests
{
    // ── TryReadConstant ──────────────────────────────────────────────────────

    [Fact]
    public void TryReadConstant_Ldc_I4_5_ReturnsTrueWithValue5()
    {
        var instr = Instruction.Create(OpCodes.Ldc_I4_5);

        bool result = IlHelper.TryReadConstant(instr, out var value);

        Assert.True(result);
        Assert.Equal(5, value);
    }

    [Fact]
    public void TryReadConstant_Ldnull_ReturnsTrueWithNullValue()
    {
        var instr = Instruction.Create(OpCodes.Ldnull);

        bool result = IlHelper.TryReadConstant(instr, out var value);

        Assert.True(result);
        Assert.Null(value);
    }

    [Fact]
    public void TryReadConstant_Ldloc0_ReturnsFalse()
    {
        var instr = Instruction.Create(OpCodes.Ldloc_0);

        bool result = IlHelper.TryReadConstant(instr, out _);

        Assert.False(result);
    }

    // ── ExtractCallArgs ──────────────────────────────────────────────────────

    [Fact]
    public void ExtractCallArgs_LdstrAndLdcI4_ReturnsCorrectArgs()
    {
        // Sequence: ldstr "hello" / ldc.i4.3 / <call at index 2>
        var instructions = new List<Instruction>
        {
            Instruction.Create(OpCodes.Ldstr, "hello"),
            Instruction.Create(OpCodes.Ldc_I4_3),
        };

        var args = IlHelper.ExtractCallArgs(instructions, callIndex: 2, paramCount: 2);

        Assert.Equal(2, args.Length);
        Assert.Equal("hello", args[0]);
        Assert.Equal(3, args[1]);
    }

    [Fact]
    public void ExtractCallArgs_Dup_PropagatesConstant()
    {
        // ldstr "hello" / dup / <call at index 2, paramCount 2>
        var instructions = new List<Instruction>
        {
            Instruction.Create(OpCodes.Ldstr, "hello"),
            Instruction.Create(OpCodes.Dup),
        };

        var args = IlHelper.ExtractCallArgs(instructions, callIndex: 2, paramCount: 2);

        Assert.Equal(2, args.Length);
        Assert.Equal("hello", args[0]);
        Assert.Equal("hello", args[1]);
    }

    [Fact]
    public void ExtractCallArgs_Stfld_PopsValueAndObjectRef()
    {
        // ldarg.0 / ldstr "val" / stfld _f / ldstr "result" / <call at index 4, paramCount 1>
        var module = ModuleDefinition.CreateModule("Test", ModuleKind.Dll);
        var fakeField = new FieldDefinition("_f", FieldAttributes.Public, module.TypeSystem.String);
        var stfld = Instruction.Create(OpCodes.Stfld, fakeField);

        var instructions = new List<Instruction>
        {
            Instruction.Create(OpCodes.Ldarg_0),
            Instruction.Create(OpCodes.Ldstr, "val"),
            stfld,
            Instruction.Create(OpCodes.Ldstr, "result"),
        };

        var args = IlHelper.ExtractCallArgs(instructions, callIndex: 4, paramCount: 1);

        Assert.Single(args);
        Assert.Equal("result", args[0]);
    }
}
