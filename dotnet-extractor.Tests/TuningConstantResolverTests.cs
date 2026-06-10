using Mono.Cecil;

public class TuningConstantResolverTests
{
    private const string ManagedPath = "../../../../game-data/OxygenNotIncluded_Data/Managed";

    // Loaded once for the whole class: ReadModule on Assembly-CSharp.dll is expensive.
    private static readonly ModuleDefinition Module =
        ModuleDefinition.ReadModule(System.IO.Path.Combine(ManagedPath, "Assembly-CSharp.dll"));

    // Expected values are the real ONI HatchTuning constants, pinned to the repo DLL.
    [Fact]
    public void Resolves_float_and_int_tuning_constants()
    {
        var resolver = new TuningConstantResolver(Module);
        var tuning = Module.GetType("HatchTuning");

        Assert.True(resolver.TryResolve(tuning, "STANDARD_CALORIES_PER_CYCLE", out var calories));
        Assert.Equal(700000f, calories);

        Assert.True(resolver.TryResolve(tuning, "EGG_MASS", out var eggMass));
        Assert.Equal(2f, eggMass);
    }

    // PEN_SIZE_PER_CREATURE is not a literal: it is `ldsfld SPACE_REQUIREMENTS::TIER3` (== 12).
    [Fact]
    public void Resolves_pen_size_via_ldsfld_indirection()
    {
        var resolver = new TuningConstantResolver(Module);
        var tuning = Module.GetType("HatchTuning");

        Assert.True(resolver.TryResolve(tuning, "PEN_SIZE_PER_CREATURE", out var pen));
        Assert.Equal(12f, pen);
    }
}
