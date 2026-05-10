using System.Text.Json.Serialization;

public record RecipeItemDto(
    [property: JsonPropertyName("element_id")] string ElementId,
    [property: JsonPropertyName("amount")] float Amount
);

public record RecipeDto(
    [property: JsonPropertyName("building_id")] string BuildingId,
    [property: JsonPropertyName("duration")] float Duration,
    [property: JsonPropertyName("fabricators")] List<string>? Fabricators,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("inputs")] List<RecipeItemDto> Inputs,
    [property: JsonPropertyName("outputs")] List<RecipeItemDto> Outputs
);

public sealed class RecipeExtractor
{
    private readonly string _managedPath;

    public RecipeExtractor(string managedPath) => _managedPath = managedPath;

    public List<RecipeDto> Extract()
    {
        throw new NotImplementedException(
            "Fill in after inspecting Assembly-CSharp.dll. " +
            "See tests/Fixtures/GameImport/recipes.json for the expected output shape."
        );
    }
}
