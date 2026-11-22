<?php

declare(strict_types=1);

use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\LookupTool;
use App\Models\Building;
use App\Models\BuildingConstructionMaterial;
use App\Models\CodexEntry;
use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use App\Models\Element;
use App\Models\ElementThermalProperty;
use App\Models\Plant;
use App\Models\PrefabName;
use App\Models\Recipe;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->catalogDir = sys_get_temp_dir().'/oni-lookup-'.uniqid();
    File::ensureDirectoryExists($this->catalogDir.'/assets/elements');
    File::put($this->catalogDir.'/fr.json', json_encode([
        'STRINGS.ELEMENTS.OXYGEN.NAME' => 'Oxygène',
        'STRINGS.BUILDINGS.PREFABS.FARMSTATION.NAME' => 'Station agricole',
    ]));
    File::put($this->catalogDir.'/en.json', json_encode([
        'STRINGS.ELEMENTS.OXYGEN.NAME' => 'Oxygen',
        'STRINGS.BUILDINGS.PREFABS.FARMSTATION.NAME' => 'Farm Station',
    ]));
    app('translator')->addJsonPath($this->catalogDir);
    app()->setLocale('fr');
    config(['game.assets_path' => $this->catalogDir.'/assets']);

    PrefabName::factory()->create(['prefab_id' => 'Oxygen', 'name_key' => 'STRINGS.ELEMENTS.OXYGEN.NAME']);
    PrefabName::factory()->create(['prefab_id' => 'FarmStation', 'name_key' => 'STRINGS.BUILDINGS.PREFABS.FARMSTATION.NAME']);
    Element::factory()->create(['element_id' => 'Oxygen', 'state' => 'gas', 'name' => ['en' => 'Oxygen'], 'low_temp_transition_target' => 'LiquidOxygen']);
    ElementThermalProperty::query()->create(['element_id' => 'Oxygen', 'specific_heat_capacity' => 1.005, 'thermal_conductivity' => 0.024, 'low_temp' => 90.19, 'high_temp' => 10_000.0, 'default_temperature' => 293.15, 'light_absorption_factor' => 1.0, 'radiation_absorption_factor' => 1.0, 'radiation_per_1000_mass' => 0.0]);
});

afterEach(function (): void {
    File::deleteDirectory($this->catalogDir);
});

it('details one element found by id', function (): void {
    OniServer::tool(LookupTool::class, ['query' => 'Oxygen'])
        ->assertOk()
        ->assertSee('element Oxygène / Oxygen (Oxygen)')
        ->assertSee('state gas')
        ->assertSee('specific heat 1.005')
        ->assertSee('below -183.0 °C → LiquidOxygen')
        ->assertSee('sprite missing');
});

it('finds an element by its French name, accents optional', function (): void {
    OniServer::tool(LookupTool::class, ['query' => 'oxygene'])->assertOk()->assertSee('element Oxygène / Oxygen (Oxygen)');
});

it('resolves a machine shown in game by its French name, English name or id ahead of its linked Codex article', function (): void {
    $building = Building::factory()->create(['building_id' => 'FarmStation']);
    $article = CodexEntry::factory()->create(['codex_id' => 'FarmStation', 'title' => ['en' => 'Farm Station']]);
    $article->buildings()->attach($building);

    foreach (['Station agricole', 'Farm Station', 'FarmStation'] as $query) {
        OniServer::tool(LookupTool::class, ['query' => $query])
            ->assertOk()
            ->assertSee('building Station agricole / Farm Station (FarmStation)')
            ->assertDontSee('exact matches');
    }
});

it('lists several matches', function (): void {
    Element::factory()->create(['element_id' => 'ContaminatedOxygen', 'state' => 'gas', 'name' => ['en' => 'Polluted Oxygen']]);

    OniServer::tool(LookupTool::class, ['query' => 'oxyg'])
        ->assertOk()
        ->assertSee('2 matches')
        ->assertSee('element Oxygen · Oxygène / Oxygen')
        ->assertSee('element ContaminatedOxygen');
});

it('details a building with its size, power and materials', function (): void {
    Building::factory()->create(['building_id' => 'FarmStation', 'width' => 2, 'height' => 2, 'power_consumption' => null, 'heat_generation' => 0, 'category' => 'Food']);
    BuildingConstructionMaterial::query()->create(['building_id' => Building::query()->where('building_id', 'FarmStation')->value('id'), 'amount' => 400, 'material_category' => 'Metal', 'element_id' => null]);

    OniServer::tool(LookupTool::class, ['query' => 'Station agricole', 'kind' => 'building'])
        ->assertOk()
        ->assertSee('building Station agricole / Farm Station (FarmStation)')
        ->assertSee('size 2x2')
        ->assertSee('materials: Metal 400 kg');
});

it('adds the imported building description and effect only on request', function (): void {
    Building::factory()->create([
        'building_id' => 'PilotFarmStation',
        'description' => ['en' => 'Used to tend crops.'],
        'effect' => ['en' => 'Produces Micronutrient Fertilizer.'],
    ]);

    OniServer::tool(LookupTool::class, ['query' => 'PilotFarmStation', 'kind' => 'building'])
        ->assertOk()->assertDontSee('Micronutrient Fertilizer');

    OniServer::tool(LookupTool::class, ['query' => 'PilotFarmStation', 'kind' => 'building', 'details' => true])
        ->assertOk()->assertSee('Used to tend crops.')->assertSee('Produces Micronutrient Fertilizer.');
});

it('separates building room descriptors, staffed operations and manual Codex links', function (): void {
    Building::factory()->create([
        'building_id' => 'PilotFarmStation',
        'codex_data' => [
            'room' => ['id' => 'Farm', 'mode' => 'Required'],
            'tinker' => ['input_id' => 'Fertilizer', 'input_kg' => 5, 'output_id' => 'FarmStationTools', 'skill_perk' => 'CanFarmTinker'],
            'manual_conversions' => [
                ['label' => 'Example link', 'input_id' => 'Dirt', 'output_id' => 'Fertilizer'],
            ],
        ],
    ]);

    OniServer::tool(LookupTool::class, ['query' => 'PilotFarmStation', 'kind' => 'building', 'details' => true])
        ->assertOk()->assertSee([
            'room descriptor: Farm (Required)',
            'staffed operation: Fertilizer 5 kg/use → FarmStationTools 1 unit/use · skill CanFarmTinker',
            'manual Codex conversion: Dirt → Fertilizer (Example link)',
        ])->assertDontSee('kg/cycle');
});

it('reads the stored Codex text and linked entities on request', function (): void {
    $entry = CodexEntry::factory()->create([
        'codex_id' => 'PacuSpecies',
        'content' => [['blocks' => [['type' => 'text', 'text' => ['en' => 'An aquatic creature.']]]]],
    ]);
    $critter = Critter::factory()->create(['critter_id' => 'Pacu']);
    $entry->critters()->attach($critter);

    OniServer::tool(LookupTool::class, ['query' => 'PacuSpecies', 'kind' => 'codex', 'details' => true])
        ->assertOk()->assertSee('An aquatic creature.')->assertSee('critter Pacu');
});

it('links a resource to its recipe consumers and producers without exchanging their roles', function (): void {
    $builder = Building::factory()->create(['building_id' => 'PilotFabricator']);
    Element::factory()->create(['element_id' => 'PilotProduct']);
    $recipe = Recipe::query()->create(['building_id' => $builder->id, 'duration' => 10, 'fabricators' => ['PilotFabricator'], 'name' => ['en' => 'Pilot recipe']]);
    $recipe->items()->create(['element_id' => 'Oxygen', 'role' => 'input', 'amount' => 10]);
    $recipe->items()->create(['element_id' => 'PilotProduct', 'role' => 'output', 'amount' => 2]);

    OniServer::tool(LookupTool::class, ['query' => 'Oxygen', 'kind' => 'element', 'details' => true])
        ->assertOk()->assertSee('recipe consumed at PilotFabricator')->assertDontSee('recipe produced at PilotFabricator');
    OniServer::tool(LookupTool::class, ['query' => 'PilotProduct', 'kind' => 'element', 'details' => true])
        ->assertOk()->assertSee('recipe produced at PilotFabricator')->assertDontSee('recipe consumed at PilotFabricator');
});

it('labels Starnacle growth and resource quantities without inventing environmental limits', function (): void {
    Element::factory()->create(['element_id' => 'Coquina']);
    Element::factory()->create(['element_id' => 'Phosphorite']);
    $plant = Plant::query()->create(['plant_id' => 'PlanktonCoral', 'name' => ['en' => 'Starnacle']]);
    $variant = $plant->variants()->create(['variant_id' => 'PlanktonCoral', 'name' => ['en' => 'Starnacle'], 'is_base' => true, 'min_temp' => 273.15, 'max_temp' => 373.15, 'min_pressure' => 0, 'max_pressure' => 0, 'growth_time' => 4]);
    $variant->inputs()->create(['input_type' => 'fertilizer', 'element_id' => 'Coquina', 'amount_per_cycle' => 20]);
    $variant->outputs()->create(['output_type' => 'resource', 'element_id' => 'Phosphorite', 'amount_per_harvest' => 80]);

    OniServer::tool(LookupTool::class, ['query' => 'PlanktonCoral', 'kind' => 'plant'])
        ->assertOk()
        ->assertSee('growth 4 cycles')
        ->assertSee('Coquina 20 kg/cycle')
        ->assertSee('Phosphorite 80 per harvest')
        ->assertDontSee('Phosphorite 80 kg/harvest')
        ->assertDontSee(['growth 4 s', '0..0 kg', 'atmosphere any']);
});

it('details a critter with its morphs, diets and outputs', function (): void {
    $critter = Critter::factory()->create(['critter_id' => 'Hatch', 'variant_family' => 'Hatch', 'name' => ['en' => 'Hatch']]);
    $morph = CritterMorph::factory()->create(['critter_id' => $critter->id, 'morph_id' => 'Hatch', 'name' => ['en' => 'Hatch']]);
    Element::factory()->create(['element_id' => 'SandStone', 'state' => 'solid']);
    Element::factory()->create(['element_id' => 'Carbon', 'state' => 'solid']);
    CritterMorphDiet::factory()->create(['critter_morph_id' => $morph->id, 'consumed_element_id' => 'SandStone', 'produced_element_id' => 'Carbon', 'amount_per_cycle' => 140, 'conversion_ratio' => 0.5]);
    CritterMorphOutput::factory()->create(['critter_morph_id' => $morph->id, 'element_id' => 'HatchEgg', 'output_type' => 'egg']);

    OniServer::tool(LookupTool::class, ['query' => 'Hatch', 'kind' => 'critter'])
        ->assertOk()
        ->assertSee('critter')
        ->assertSee('morph Hatch')
        ->assertSee('eats SandStone 140 kg/cycle → Carbon ×0.5')
        ->assertSee('egg HatchEgg');
});

it('does not present intermediate plant and prey diet values as mass', function (): void {
    $actor = Critter::factory()->create(['critter_id' => 'ParrotFish', 'description' => ['en' => 'An aquatic creature that eats Starnacle.']]);
    $morph = CritterMorph::factory()->create(['critter_id' => $actor->id, 'morph_id' => 'ParrotFish']);
    CritterMorphDiet::factory()->create(['critter_morph_id' => $morph->id, 'consumed_element_id' => 'PlanktonCoral', 'food_kind' => 'plant', 'amount_per_cycle' => 0.5]);
    CritterMorphDiet::factory()->create(['critter_morph_id' => $morph->id, 'consumed_element_id' => 'Pacu', 'food_kind' => 'prey', 'amount_per_cycle' => 200]);
    CritterMorphOutput::factory()->create(['critter_morph_id' => $morph->id, 'element_id' => 'ParrotFishEgg', 'output_type' => 'egg', 'amount_per_cycle' => 0.05]);

    OniServer::tool(LookupTool::class, ['query' => 'ParrotFish', 'kind' => 'critter'])
        ->assertOk()->assertSee(['PlanktonCoral (plant; rate unavailable)', 'Pacu (prey; rate unavailable)', 'ParrotFishEgg 0.05 eggs/cycle'])
        ->assertDontSee(['0.5 kg/cycle', '200 kg/cycle']);

    OniServer::tool(LookupTool::class, ['query' => 'ParrotFish', 'kind' => 'critter', 'details' => true])
        ->assertOk()->assertSee(['description: An aquatic creature that eats Starnacle.', 'comfort ']);
});

it('keeps variant details separate and links adult consumers in the reverse direction', function (): void {
    $pacu = Critter::factory()->create(['critter_id' => 'Pacu', 'variant_family' => 'Pacu']);
    $beta = Critter::factory()->create(['critter_id' => 'PacuBeta', 'variant_family' => 'Pacu']);
    $cleaner = Critter::factory()->create(['critter_id' => 'PacuCleaner', 'variant_family' => 'Pacu']);
    $jawbo = Critter::factory()->create(['critter_id' => 'PrehistoricPacu']);
    CritterMorph::factory()->create(['critter_id' => $pacu->id, 'morph_id' => 'Pacu']);
    CritterMorph::factory()->create(['critter_id' => $beta->id, 'morph_id' => 'PacuBeta']);
    CritterMorph::factory()->create(['critter_id' => $cleaner->id, 'morph_id' => 'PacuCleaner']);
    $adult = CritterMorph::factory()->create(['critter_id' => $jawbo->id, 'morph_id' => 'PrehistoricPacu', 'is_base' => true]);
    $juvenile = CritterMorph::factory()->create(['critter_id' => $jawbo->id, 'morph_id' => 'PrehistoricPacuBaby', 'is_base' => false]);
    foreach ([$adult, $juvenile] as $morph) {
        CritterMorphDiet::factory()->create(['critter_morph_id' => $morph->id, 'consumed_element_id' => 'PacuBeta', 'food_kind' => 'prey']);
    }

    OniServer::tool(LookupTool::class, ['query' => 'PacuBeta', 'kind' => 'critter', 'details' => true])
        ->assertOk()->assertSee(['morph PacuBeta', 'family adults: Pacu, PacuBeta, PacuCleaner', 'consumed by PrehistoricPacu (prey)'])
        ->assertDontSee(['morph PacuCleaner', 'consumed by PrehistoricPacuBaby']);
});

it('lists only the exact matches when several kinds share an id', function (): void {
    Critter::factory()->create(['critter_id' => 'Hatch', 'variant_family' => 'Hatch', 'name' => ['en' => 'Hatch']]);
    CodexEntry::factory()->create(['codex_id' => 'Hatch', 'title' => ['en' => 'Hatch']]);
    CodexEntry::factory()->create(['codex_id' => 'HatchHazard', 'title' => ['en' => 'Hatch Hazard']]);

    OniServer::tool(LookupTool::class, ['query' => 'Hatch'])
        ->assertOk()
        ->assertSee('2 exact matches')
        ->assertSee('kind')
        ->assertSee('critter Hatch')
        ->assertSee('codex Hatch')
        ->assertDontSee('HatchHazard');
});

it('returns the sprite only when asked', function (): void {
    $webp = 'RIFF....WEBPVP8 fake';
    File::put($this->catalogDir.'/assets/elements/Oxygen.webp', $webp);

    OniServer::tool(LookupTool::class, ['query' => 'Oxygen'])->assertOk()->assertSee('sprite available')->assertDontSee(base64_encode($webp));
    OniServer::tool(LookupTool::class, ['query' => 'Oxygen', 'image' => true])->assertOk()->assertSee(base64_encode($webp));
});

it('gives the real total when matches exceed the cap', function (): void {
    foreach (range(1, 24) as $index) {
        Element::factory()->create(['element_id' => 'Oxyfake'.$index, 'state' => 'gas', 'name' => ['en' => 'Oxyfake '.$index]]);
    }

    OniServer::tool(LookupTool::class, ['query' => 'oxy'])->assertOk()->assertSee('25 matches, first 20');
});

it('says when nothing matches', function (): void {
    OniServer::tool(LookupTool::class, ['query' => 'unobtainable thing'])->assertHasErrors(['No game object matches']);
});
