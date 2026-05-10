using System.Text.Json.Serialization;

public record GeyserDto(
    [property: JsonPropertyName("geyser_id")] string GeyserIds,
    [property: JsonPropertyName("type")] string Type,
    [property: JsonPropertyName("element_id")] string ElementId,
    [property: JsonPropertyName("temperature")] float Temperature,
    [property: JsonPropertyName("max_pressure")] float MaxPressure,
    [property: JsonPropertyName("min_yield_rate")] float MinYieldRate,
    [property: JsonPropertyName("max_yield_rate")] float MaxYieldRate,
    [property: JsonPropertyName("min_eruption_duration")] float MinEruptionDuration,
    [property: JsonPropertyName("max_eruption_duration")] float MaxEruptionDuration,
    [property: JsonPropertyName("min_eruption_period")] float MinEruptionPeriod,
    [property: JsonPropertyName("max_eruption_period")] float MaxEruptionPeriod,
    [property: JsonPropertyName("dormancy_min_cycles")] float DormancyMinCycles,
    [property: JsonPropertyName("dormancy_max_cycles")] float DormancyMaxCycles,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("dlc_id")] string? DlcId
);

public sealed class GeyserExtractor
{
    private readonly string _managedPath;

    public GeyserExtractor(string managedPath) => _managedPath = managedPath;

    public List<GeyserDto> Extract()
    {
        throw new NotImplementedException(
            "Fill in after inspecting Assembly-CSharp.dll. " +
            "See tests/Fixtures/GameImport/geyser_types.json for the expected output shape."
        );
    }
}
