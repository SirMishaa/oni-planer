using Mono.Cecil;

public class CritterExtractorTests
{
    public const string ManagedPath = "../../../../game-data/OxygenNotIncluded_Data/Managed";

    // Loaded once for the whole class: ReadModule on Assembly-CSharp.dll is expensive.
    private static readonly ModuleDefinition Module =
        ModuleDefinition.ReadModule(System.IO.Path.Combine(ManagedPath, "Assembly-CSharp.dll"));

    private static readonly Dictionary<int, string> SimHashes = IlHelper.BuildSimHashesMap(Module);

    [Fact]
    public void Hatch_has_calories_and_pen_size()
    {
        var hatch = new CritterExtractor(ManagedPath).Extract()
            .First(c => c.CritterId == "Hatch");

        Assert.Equal(700000f, hatch.CaloriesPerCycle);
        Assert.True(hatch.OvercrowdingThreshold > 0); // PEN_SIZE_PER_CREATURE
    }

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

    [Fact]
    public void Hatch_lays_an_egg_output_and_has_diet_outputs()
    {
        var hatch = new CritterExtractor(ManagedPath).Extract()
            .First(c => c.CritterId == "Hatch");

        // Diets must be populated (Hatch eats minerals and produces Coal)
        Assert.NotEmpty(hatch.Diets);

        // Egg output must be present
        Assert.Contains(hatch.Outputs, o => o.OutputType == "egg");
        var egg = hatch.Outputs.First(o => o.OutputType == "egg");

        // Hatch fertilityCycles = 60 → amount = 1/60 ≈ 0.0167
        Assert.True(egg.AmountPerCycle > 0);
    }
}
