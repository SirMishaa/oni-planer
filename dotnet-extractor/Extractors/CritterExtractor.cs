using System.Text.Json.Serialization;

public record CritterDietDto(
    [property: JsonPropertyName("consumed_element_id")] string ConsumedElementId,
    [property: JsonPropertyName("amount_per_cycle")] float AmountPerCycle,
    [property: JsonPropertyName("produced_element_id")] string? ProducedElementId,
    [property: JsonPropertyName("conversion_ratio")] float? ConversionRatio
);

public record CritterOutputDto(
    [property: JsonPropertyName("element_id")] string ElementId,
    [property: JsonPropertyName("amount_per_cycle")] float AmountPerCycle,
    [property: JsonPropertyName("output_type")] string OutputType
);

public record CritterDto(
    [property: JsonPropertyName("critter_id")] string CritterId,
    [property: JsonPropertyName("parent_critter_id")] string? ParentCritterId,
    [property: JsonPropertyName("is_base")] bool IsBase,
    [property: JsonPropertyName("min_temp")] float MinTemp,
    [property: JsonPropertyName("max_temp")] float MaxTemp,
    [property: JsonPropertyName("calories_per_cycle")] float CaloriesPerCycle,
    [property: JsonPropertyName("incubation_time")] float IncubationTime,
    [property: JsonPropertyName("lifespan")] float Lifespan,
    [property: JsonPropertyName("overcrowding_threshold")] int OvercrowdingThreshold,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("diets")] List<CritterDietDto> Diets,
    [property: JsonPropertyName("outputs")] List<CritterOutputDto> Outputs
);

public sealed class CritterExtractor
{
    private readonly string _managedPath;

    public CritterExtractor(string managedPath) => _managedPath = managedPath;

    public List<CritterDto> Extract()
    {
        throw new NotImplementedException(
            "Fill in after inspecting Assembly-CSharp.dll. " +
            "See tests/Fixtures/GameImport/critters.json for the expected output shape."
        );
    }
}
