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
use App\Models\PrefabName;
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
