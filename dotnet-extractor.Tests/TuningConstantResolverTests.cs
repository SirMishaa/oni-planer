using Mono.Cecil;

public class TuningConstantResolverTests
{
    private const string ManagedPath = "../../../../game-data/OxygenNotIncluded_Data/Managed";

    [Fact]
    public void Resolves_float_and_int_tuning_constants()
    {
        var module = ModuleDefinition.ReadModule(System.IO.Path.Combine(ManagedPath, "Assembly-CSharp.dll"));
        var resolver = new TuningConstantResolver(module);
        var tuning = module.GetType("HatchTuning");

        Assert.True(resolver.TryResolve(tuning, "STANDARD_CALORIES_PER_CYCLE", out var calories));
        Assert.Equal(700000f, calories);

        Assert.True(resolver.TryResolve(tuning, "EGG_MASS", out var eggMass));
        Assert.Equal(2f, eggMass);
    }

    [Fact]
    public void Resolves_pen_size_via_ldsfld_indirection()
    {
        var module = ModuleDefinition.ReadModule(System.IO.Path.Combine(ManagedPath, "Assembly-CSharp.dll"));
        var resolver = new TuningConstantResolver(module);
        var tuning = module.GetType("HatchTuning");
        Assert.True(resolver.TryResolve(tuning, "PEN_SIZE_PER_CREATURE", out var pen));
        Assert.Equal(12f, pen);
    }
}
