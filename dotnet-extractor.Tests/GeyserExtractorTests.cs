public class GeyserExtractorTests
{
    private static readonly List<GeyserDto> Geysers =
        new GeyserExtractor(CritterExtractorTests.ManagedPath).Extract();

    [Fact]
    public void Small_volcano_is_a_volcano_with_its_kanim_and_name_key()
    {
        var volcano = Geysers.First(g => g.GeyserId == "small_volcano");

        Assert.Equal("volcano", volcano.Type);
        Assert.Equal("geyser_molten_volcano_small_kanim", volcano.Kanim);
        Assert.Equal("STRINGS.CREATURES.SPECIES.GEYSER.SMALL_VOLCANO.NAME", volcano.NameLocalizationId);
    }

    [Fact]
    public void Every_geyser_has_a_kanim()
    {
        var withoutKanim = Geysers.Where(g => string.IsNullOrEmpty(g.Kanim)).Select(g => g.GeyserId);

        Assert.Empty(withoutKanim);
    }
}
