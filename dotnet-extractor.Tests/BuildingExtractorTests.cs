public class BuildingExtractorTests
{
    private static readonly List<BuildingDto> Buildings =
        new BuildingExtractor(CritterExtractorTests.ManagedPath).Extract();

    [Theory]
    [InlineData("SuitLocker", "changingarea_kanim")]
    [InlineData("Generator", "generatorphos_kanim")]
    public void Building_exposes_its_kanim_name(string buildingId, string expectedKanim)
    {
        var building = Buildings.First(b => b.BuildingId == buildingId);

        Assert.Equal(expectedKanim, building.Kanim);
    }

    [Fact]
    public void Every_building_has_a_kanim()
    {
        var withoutKanim = Buildings.Where(b => string.IsNullOrEmpty(b.Kanim)).Select(b => b.BuildingId);

        Assert.Empty(withoutKanim);
    }
}
