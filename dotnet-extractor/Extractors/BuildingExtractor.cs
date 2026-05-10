using Mono.Cecil;
using System.Text.Json.Serialization;

public record BuildingDto(
    [property: JsonPropertyName("building_id")] string BuildingId,
    [property: JsonPropertyName("category")] string Category,
    [property: JsonPropertyName("power_generation")] float? PowerGeneration,
    [property: JsonPropertyName("power_consumption")] float? PowerConsumption,
    [property: JsonPropertyName("heat_generation")] float HeatGeneration,
    [property: JsonPropertyName("width")] int Width,
    [property: JsonPropertyName("height")] int Height,
    [property: JsonPropertyName("construction_time")] float ConstructionTime,
    [property: JsonPropertyName("tags")] List<string> Tags,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("dlc_id")] string? DlcId
);

public sealed class BuildingExtractor
{
    private readonly string _managedPath;

    public BuildingExtractor(string managedPath) => _managedPath = managedPath;

    public List<BuildingDto> Extract()
    {
        throw new NotImplementedException(
            "Fill in after inspecting Assembly-CSharp.dll with ilspycmd. " +
            "See tests/Fixtures/GameImport/buildings.json for the expected output shape."
        );
    }
}
