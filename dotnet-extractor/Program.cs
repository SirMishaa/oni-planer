using System.Text.Json;

if (args.Length < 2)
{
    Console.Error.WriteLine("Usage: dotnet-extractor <game-data-path> <output-cache-path>");
    return 1;
}

string gamePath = args[0];
string cachePath = args[1];
string managedPath = Path.Combine(gamePath, "Managed");

Directory.CreateDirectory(cachePath);

var options = new JsonSerializerOptions { WriteIndented = true };

Console.WriteLine("Extracting buildings...");
var buildings = new BuildingExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "buildings.json"), JsonSerializer.Serialize(buildings, options));

Console.WriteLine("Extracting recipes...");
var recipes = new RecipeExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "recipes.json"), JsonSerializer.Serialize(recipes, options));

Console.WriteLine("Extracting critters...");
var critters = new CritterExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "critters.json"), JsonSerializer.Serialize(critters, options));

Console.WriteLine("Extracting plants...");
var plants = new PlantExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "plants.json"), JsonSerializer.Serialize(plants, options));

Console.WriteLine("Extracting geysers...");
var geysers = new GeyserExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "geyser_types.json"), JsonSerializer.Serialize(geysers, options));

Console.WriteLine("Extracting duplicant traits...");
var dupes = new DuplicantTraitExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "duplicant_traits.json"), JsonSerializer.Serialize(dupes, options));

Console.WriteLine("Extracting rocket components...");
var rockets = new RocketExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "rocket_components.json"), JsonSerializer.Serialize(rockets, options));

Console.WriteLine("Extracting assets...");
new AssetExtractor(gamePath).Extract(Path.Combine("..", "public", "assets", "game"));

Console.WriteLine("Done.");
return 0;
