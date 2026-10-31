public class PlantExtractorTests
{
    private static readonly List<PlantDto> Plants =
        new PlantExtractor(CritterExtractorTests.ManagedPath).Extract();

    [Theory]
    [InlineData("PrickleFlower", "bristleblossom_kanim")]
    [InlineData("SpiceVine", "vinespicenut_kanim")]  // not its seed_spicenut_kanim
    public void Plant_exposes_its_own_kanim_not_its_seed(string plantId, string expectedKanim)
    {
        var plant = Plants.First(p => p.PlantId == plantId);

        Assert.Equal(expectedKanim, plant.Kanim);
    }

    [Fact]
    public void Every_plant_has_a_kanim()
    {
        var withoutKanim = Plants.Where(p => string.IsNullOrEmpty(p.Kanim)).Select(p => p.PlantId);

        Assert.Empty(withoutKanim);
    }
}
