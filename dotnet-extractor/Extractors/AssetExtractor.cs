public sealed class AssetExtractor
{
    private readonly string _gamePath;

    public AssetExtractor(string gamePath) => _gamePath = gamePath;

    public void Extract(string outputPath)
    {
        Directory.CreateDirectory(Path.Combine(outputPath, "elements"));
        Directory.CreateDirectory(Path.Combine(outputPath, "buildings"));
        Directory.CreateDirectory(Path.Combine(outputPath, "critters"));
        Directory.CreateDirectory(Path.Combine(outputPath, "plants"));
        Directory.CreateDirectory(Path.Combine(outputPath, "geysers"));

        Console.WriteLine("Asset extraction: implement after reviewing Unity bundle file format.");
    }
}
