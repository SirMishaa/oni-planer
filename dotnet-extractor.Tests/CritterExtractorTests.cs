using Mono.Cecil;
using Mono.Cecil.Cil;

public class CritterExtractorTests
{
    public const string ManagedPath = "../../../../game-data/OxygenNotIncluded_Data/Managed";

    // Loaded once for the whole class: ReadModule on Assembly-CSharp.dll is expensive.
    private static readonly ModuleDefinition Module =
        ModuleDefinition.ReadModule(System.IO.Path.Combine(ManagedPath, "Assembly-CSharp.dll"));

    private static readonly Dictionary<int, string> SimHashes = IlHelper.BuildSimHashesMap(Module);

    private static readonly List<CritterDto> Critters = new CritterExtractor(ManagedPath).Extract();

    [Fact]
    public void Hatch_has_calories_and_pen_size()
    {
        var hatch = new CritterExtractor(ManagedPath).Extract()
            .First(c => c.CritterId == "Hatch");

        Assert.Equal(700000f, hatch.CaloriesPerCycle);
        Assert.True(hatch.OvercrowdingThreshold > 0); // PEN_SIZE_PER_CREATURE
    }

    [Fact]
    public void Hatch_diet_consumes_minerals_and_produces_coal()
    {
        var resolver = new CritterDietResolver(Module, SimHashes, new TuningConstantResolver(Module));
        var diets = resolver.Resolve(Module.GetType("HatchConfig"));

        // HatchConfig BasicRockDiet: exactly 9 entries (SiltStone + Coquina since the 2026 update), all producing Carbon at fixed rates.
        Assert.Equal(9, diets.Count);

        var consumed = diets.Select(d => d.ConsumedElementId).ToHashSet();
        Assert.Equal(
            new HashSet<string?> { "Sand", "SandStone", "Clay", "CrushedRock", "Dirt", "SedimentaryRock", "Shale", "SiltStone", "Coquina" },
            consumed);

        Assert.All(diets, d => Assert.Equal("Carbon", d.ProducedElementId));
        Assert.All(diets, d => Assert.Equal(5000f, d.CaloriesPerKg));
        Assert.All(diets, d => Assert.Equal(0.5f, d.ConversionRatio));
    }

    [Fact]
    public void Hatch_lays_an_egg_output_and_has_diet_outputs()
    {
        var hatch = new CritterExtractor(ManagedPath).Extract()
            .First(c => c.CritterId == "Hatch");

        // Diets must be populated (Hatch eats minerals and produces Coal) at
        // 700000 cal/cycle ÷ 5000 cal/kg = 140 kg/cycle consumed per entry.
        Assert.Equal(9, hatch.Diets.Count);
        Assert.All(hatch.Diets, d => Assert.Equal(140f, d.AmountPerCycle));

        // Egg output: Hatch fertilityCycles = 60 → amount = 1/60 egg/cycle.
        Assert.Contains(hatch.Outputs, o => o.OutputType == "egg");
        var egg = hatch.Outputs.First(o => o.OutputType == "egg");
        // fertilityCycles is stored as ~60.000004 in IL, so compare with tolerance.
        Assert.Equal(1f / 60f, egg.AmountPerCycle, 5);
    }

    [Fact]
    public void Hatch_variants_share_a_variant_family()
    {
        var critters = new CritterExtractor(ManagedPath).Extract();
        var hatch = critters.First(c => c.CritterId == "Hatch");
        var stone = critters.First(c => c.CritterId == "HatchHard");

        Assert.Equal("Hatch", hatch.VariantFamily);
        Assert.Equal("Hatch", stone.VariantFamily);

        // Baby morphs inherit the family from their parent adult config.
        var babyHatch = critters.First(c => c.CritterId == "HatchBaby");
        Assert.Equal("Hatch", babyHatch.VariantFamily);
    }

    [Fact]
    public void FindNameKey_resolves_simple_species_config()
    {
        var type = Module.GetType("OilFloaterConfig");
        var key = CritterExtractor.InvokeFindNameKey(type);

        Assert.Equal("STRINGS.CREATURES.SPECIES.OILFLOATER.NAME", key);
    }

    [Fact]
    public void FindNameKey_resolves_variant_whose_species_id_differs_from_class_name()
    {
        // GoldBellyConfig's true species id is ICEBELLY with variant suffix
        // VARIANT_GOLD — neither is derivable from the class name or from
        // this extractor's own variant_family output ("Belly"). This is the
        // case that proves the fix works generally, not just for the easy case.
        var type = Module.GetType("GoldBellyConfig");
        var key = CritterExtractor.InvokeFindNameKey(type);

        Assert.Equal("STRINGS.CREATURES.SPECIES.ICEBELLY.VARIANT_GOLD.NAME", key);
    }

    [Fact]
    public void FindNameKey_resolves_baby_variant_config()
    {
        var type = Module.GetType("BabyIceBellyConfig");
        var key = CritterExtractor.InvokeFindNameKey(type);

        Assert.Equal("STRINGS.CREATURES.SPECIES.ICEBELLY.BABY.NAME", key);
    }

    [Fact]
    public void FindNameKey_returns_null_when_no_matching_field_read_exists()
    {
        // A type with methods but no ldsfld targeting a STRINGS...::NAME field
        // must fall back to null, exactly like today's behavior for every
        // critter — this is the safety net the rest of the pipeline relies on.
        var module = ModuleDefinition.CreateModule("Test", ModuleKind.Dll);
        var type = new TypeDefinition("", "EmptyType", Mono.Cecil.TypeAttributes.Public, module.TypeSystem.Object);
        var method = new MethodDefinition("DoNothing", Mono.Cecil.MethodAttributes.Public, module.TypeSystem.Void);
        method.Body.Instructions.Add(Instruction.Create(OpCodes.Ret));
        type.Methods.Add(method);

        var key = CritterExtractor.InvokeFindNameKey(type);

        Assert.Null(key);
    }

    [Theory]
    [InlineData("Hatch", 100f)]       // inline constant in HatchConfig.CreateHatch
    [InlineData("Pacu", 25f)]         // inline constant in the shared BasePacuConfig helper
    [InlineData("OilFloaterDecor", 150f)]
    public void Critter_lifespan_comes_from_the_age_max_attribute_modifier(string critterId, float expected)
    {
        var critter = Critters.First(c => c.CritterId == critterId);

        Assert.Equal(expected, critter.Lifespan);
    }

    [Theory]
    [InlineData("Chameleon")]  // static int field ChameleonConfig.LIFESPAN
    [InlineData("Moo")]        // static float field MooTuning.STANDARD_LIFESPAN
    public void Critter_lifespan_resolves_static_field_values(string critterId)
    {
        var critter = Critters.First(c => c.CritterId == critterId);

        Assert.True(critter.Lifespan > 0, $"{critterId} lifespan was {critter.Lifespan}");
    }

    [Fact]
    public void Every_critter_has_a_lifespan_except_ageless_morbs()
    {
        // GlomConfig (Morb) only modifies HitPoints: Morbs never die of old age.
        var missing = Critters.Where(c => c.Lifespan <= 0).Select(c => c.CritterId);

        Assert.Equal(["Glom"], missing);
    }

    [Theory]
    [InlineData("Hatch", "HatchSpecies")]
    [InlineData("GlassDeer", "DeerSpecies")]
    [InlineData("SeaHorse", "SeaHorseSpecies")]
    [InlineData("Bee", "BeetaSpecies")]
    [InlineData("HatchBaby", "HatchSpecies")]
    public void Critter_exposes_its_species_tag(string critterId, string expectedTag)
    {
        var critter = Critters.First(c => c.CritterId == critterId);

        Assert.Equal(expectedTag, critter.SpeciesTag);
    }

    [Fact]
    public void Every_critter_has_a_species_tag()
    {
        var missing = Critters.Where(c => string.IsNullOrEmpty(c.SpeciesTag)).Select(c => c.CritterId);

        Assert.Empty(missing);
    }

    [Theory]
    [InlineData("LightBugBaby", "LightBug")]      // LightBugBabyConfig, not Baby*-prefixed
    [InlineData("OilFloaterBaby", "OilFloater")]  // game passes "Oilfloater"
    [InlineData("MosquitoLarva", "Mosquito")]
    [InlineData("HatchBaby", "Hatch")]
    [InlineData("CrabBaby", "Crab")]              // not "CrabShell", the item dropped on growing up
    public void Baby_configs_are_morphs_of_their_adult(string babyId, string adultId)
    {
        var baby = Critters.First(c => c.CritterId == babyId);

        Assert.False(baby.IsBase);
        Assert.Equal(adultId, baby.ParentCritterId);
    }

    [Theory]
    [InlineData("Bee")]        // via BeeConfig.CreateBee helper
    [InlineData("Butterfly")]
    [InlineData("SeaFairy")]
    [InlineData("Mosquito")]   // fertile but not tameable
    [InlineData("Glom")]
    public void Creatures_built_through_helpers_or_without_taming_are_extracted(string critterId)
    {
        Assert.Contains(Critters, c => c.CritterId == critterId && c.IsBase);
    }

    [Fact]
    public void Cut_content_without_a_species_tag_is_skipped()
    {
        Assert.DoesNotContain(Critters, c => c.CritterId == "Shockworm");
    }

    [Fact]
    public void Dartle_eats_dew_drips()
    {
        var resolver = new CritterDietResolver(Module, SimHashes, new TuningConstantResolver(Module));

        var diets = resolver.Resolve(Module.GetType("ChameleonConfig"));

        Assert.Contains(diets, d => d.ConsumedElementId == "DewDrip");
    }

    [Fact]
    public void Shove_vole_eats_the_ores_passed_to_its_simple_ore_diet_helper()
    {
        var resolver = new CritterDietResolver(Module, SimHashes, new TuningConstantResolver(Module));

        var diets = resolver.Resolve(Module.GetType("MoleConfig"));

        Assert.Contains(diets, d => d.ConsumedElementId == "Regolith");
        Assert.All(diets, d => Assert.True(d.CaloriesPerKg > 0));
    }
}
