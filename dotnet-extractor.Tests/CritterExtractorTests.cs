using Mono.Cecil;

public class CritterExtractorTests
{
    public const string ManagedPath = "../../../../game-data/OxygenNotIncluded_Data/Managed";

    // Loaded once for the whole class: ReadModule on Assembly-CSharp.dll is expensive.
    private static readonly ModuleDefinition Module =
        ModuleDefinition.ReadModule(System.IO.Path.Combine(ManagedPath, "Assembly-CSharp.dll"));

    private static readonly Dictionary<int, string> SimHashes = IlHelper.BuildSimHashesMap(Module);

    [Fact]
    public void Hatch_diet_consumes_minerals_and_produces_coal()
    {
        var resolver = new CritterDietResolver(Module, SimHashes, new TuningConstantResolver(Module));
        var diets = resolver.Resolve(Module.GetType("HatchConfig"));

        Assert.NotEmpty(diets);
        // Hatch produces Coal (Carbon) for its rock/veggie diets.
        Assert.Contains(diets, d => d.ProducedElementId == "Carbon");
        Assert.Contains(diets, d => d.ConsumedElementId is not null);
        Assert.Contains(diets, d => d.ConsumedElementId is not null && d.CaloriesPerKg > 0);
    }
}
