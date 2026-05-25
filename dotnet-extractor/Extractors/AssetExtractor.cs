public sealed class AssetExtractor
{
    private readonly string _gamePath;

    public AssetExtractor(string gamePath) => _gamePath = gamePath;

    public void Extract(string outputPath)
    {
        var categories = new[] { "elements", "buildings", "critters", "plants", "geysers" };
        foreach (var cat in categories) { Directory.CreateDirectory(Path.Combine(outputPath, cat)); }

        var searchPaths = new[]
        {
            Path.Combine(_gamePath, "art"),
            Path.Combine(_gamePath, "OxygenNotIncluded_Data", "StreamingAssets", "images"),
        };

        int copied = 0;
        foreach (var searchPath in searchPaths)
        {
            if (!Directory.Exists(searchPath)) { continue; }

            foreach (var file in Directory.EnumerateFiles(searchPath, "*.png", SearchOption.AllDirectories))
            {
                var name = Path.GetFileNameWithoutExtension(file).ToLowerInvariant();
                var category = name.Contains("building") ? "buildings"
                    : name.Contains("critter") || name.Contains("creature") ? "critters"
                    : name.Contains("plant") ? "plants"
                    : name.Contains("geyser") ? "geysers"
                    : "elements";

                var dest = Path.Combine(outputPath, category, Path.GetFileName(file));
                File.Copy(file, dest, overwrite: true);
                copied++;
            }
        }

        if (copied == 0)
        {
            Console.WriteLine("Asset extraction: no pre-exported images found. " +
                "Unity asset bundle extraction requires AssetTools.NET or similar. " +
                "Output directories created, ready for manual population.");
        }
        else
        {
            Console.WriteLine($"Asset extraction: copied {copied} images.");
        }
    }
}
