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

        // HatchConfig BasicRockDiet: exactly 7 entries, all producing Carbon at fixed rates.
        Assert.Equal(7, diets.Count);

        var consumed = diets.Select(d => d.ConsumedElementId).ToHashSet();
        Assert.Equal(
            new HashSet<string?> { "Sand", "SandStone", "Clay", "CrushedRock", "Dirt", "SedimentaryRock", "Shale" },
            consumed);

        Assert.All(diets, d => Assert.Equal("Carbon", d.ProducedElementId));
        Assert.All(diets, d => Assert.Equal(5000f, d.CaloriesPerKg));
        Assert.All(diets, d => Assert.Equal(0.5f, d.ConversionRatio));
    }
}
