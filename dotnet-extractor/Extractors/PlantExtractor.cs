using System.Text.Json.Serialization;

public record PlantInputDto(
    [property: JsonPropertyName("element_id")] string ElementId,
    [property: JsonPropertyName("amount_per_cycle")] float AmountPerCycle,
    [property: JsonPropertyName("input_type")] string InputType
);

public record PlantOutputDto(
    [property: JsonPropertyName("element_id")] string ElementId,
    [property: JsonPropertyName("amount_per_harvest")] float AmountPerHarvest,
    [property: JsonPropertyName("output_type")] string OutputType
);

public record PlantDto(
    [property: JsonPropertyName("plant_id")] string PlantId,
    [property: JsonPropertyName("variant_id")] string VariantId,
    [property: JsonPropertyName("is_base")] bool IsBase,
    [property: JsonPropertyName("min_temp")] float MinTemp,
    [property: JsonPropertyName("max_temp")] float MaxTemp,
    [property: JsonPropertyName("min_pressure")] float MinPressure,
    [property: JsonPropertyName("max_pressure")] float MaxPressure,
    [property: JsonPropertyName("atmosphere_element_id")] string? AtmosphereElementId,
    [property: JsonPropertyName("light_required")] bool LightRequired,
    [property: JsonPropertyName("growth_time")] float GrowthTime,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("dlc_id")] string? DlcId,
    [property: JsonPropertyName("inputs")] List<PlantInputDto> Inputs,
    [property: JsonPropertyName("outputs")] List<PlantOutputDto> Outputs
);

public sealed class PlantExtractor
{
    private readonly string _managedPath;

    public PlantExtractor(string managedPath) => _managedPath = managedPath;

    public List<PlantDto> Extract()
    {
        throw new NotImplementedException(
            "Fill in after inspecting Assembly-CSharp.dll. " +
            "See tests/Fixtures/GameImport/plants.json for the expected output shape."
        );
    }
}
