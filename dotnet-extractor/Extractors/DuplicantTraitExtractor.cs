using System.Text.Json.Serialization;

public record DuplicantBaseStatsDto(
    [property: JsonPropertyName("oxygen_consumption_gs")] float OxygenConsumptionGs,
    [property: JsonPropertyName("co2_production_gs")] float Co2ProductionGs,
    [property: JsonPropertyName("calories_per_cycle")] int CaloriesPerCycle,
    [property: JsonPropertyName("mass_kg")] float MassKg,
    [property: JsonPropertyName("bladder_fill_per_cycle")] float BladderFillPerCycle
);

public record DuplicantTraitEffectDto(
    [property: JsonPropertyName("stat")] string Stat,
    [property: JsonPropertyName("modifier")] float Modifier,
    [property: JsonPropertyName("modifier_type")] string ModifierType
);

public record DuplicantTraitDto(
    [property: JsonPropertyName("trait_id")] string TraitId,
    [property: JsonPropertyName("is_positive")] bool IsPositive,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("dlc_id")] string? DlcId,
    [property: JsonPropertyName("effects")] List<DuplicantTraitEffectDto> Effects
);

public record DuplicantDataDto(
    [property: JsonPropertyName("base_stats")] DuplicantBaseStatsDto BaseStats,
    [property: JsonPropertyName("traits")] List<DuplicantTraitDto> Traits
);

public sealed class DuplicantTraitExtractor
{
    private readonly string _managedPath;

    public DuplicantTraitExtractor(string managedPath) => _managedPath = managedPath;

    public DuplicantDataDto Extract()
    {
        throw new NotImplementedException(
            "Fill in after inspecting Assembly-CSharp.dll. " +
            "See tests/Fixtures/GameImport/duplicant_traits.json for the expected output shape."
        );
    }
}
