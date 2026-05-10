using System.Text.Json.Serialization;

public record RocketEngineDto(
    [property: JsonPropertyName("engine_id")] string EngineId,
    [property: JsonPropertyName("fuel_element_id")] string? FuelElementId,
    [property: JsonPropertyName("oxidizer_element_id")] string? OxidizerElementId,
    [property: JsonPropertyName("max_range")] float MaxRange,
    [property: JsonPropertyName("fuel_consumption_rate")] float FuelConsumptionRate,
    [property: JsonPropertyName("oxidizer_consumption_rate")] float? OxidizerConsumptionRate,
    [property: JsonPropertyName("exhaust_temperature")] float ExhaustTemperature,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("dlc_id")] string? DlcId
);

public record RocketModuleDto(
    [property: JsonPropertyName("module_id")] string ModuleId,
    [property: JsonPropertyName("module_type")] string ModuleType,
    [property: JsonPropertyName("mass")] float Mass,
    [property: JsonPropertyName("capacity")] float Capacity,
    [property: JsonPropertyName("power_consumption")] float? PowerConsumption,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("dlc_id")] string? DlcId
);

public record RocketDataDto(
    [property: JsonPropertyName("engines")] List<RocketEngineDto> Engines,
    [property: JsonPropertyName("modules")] List<RocketModuleDto> Modules
);

public sealed class RocketExtractor
{
    private readonly string _managedPath;

    public RocketExtractor(string managedPath) => _managedPath = managedPath;

    public RocketDataDto Extract()
    {
        throw new NotImplementedException(
            "Fill in after inspecting Assembly-CSharp.dll. " +
            "See tests/Fixtures/GameImport/rocket_components.json for the expected output shape."
        );
    }
}
