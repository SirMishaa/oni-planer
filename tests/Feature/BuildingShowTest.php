<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\BuildingConstructionMaterial;
use App\Models\Mod;

it('returns building JSON for a valid building_id', function (): void {
    $building = Building::factory()->create([
        'building_id' => 'TestGenerator',
        'category' => 'Power',
        'width' => 2,
        'height' => 3,
        'power_consumption' => null,
        'power_generation' => 800.0,
        'heat_generation' => 20.0,
        'construction_time' => 30.0,
        'name' => ['en' => 'Test Generator'],
        'description' => ['en' => 'Generates power.'],
    ]);

    $this->getJson(route('buildings.show', $building->building_id))
        ->assertOk()
        ->assertJsonFragment([
            'building_id' => 'TestGenerator',
            'category' => 'Power',
            'width' => 2,
            'height' => 3,
            'power_generation' => 800.0,
        ]);
});

it('returns 404 for an unknown building_id', function (): void {
    $this->getJson(route('buildings.show', 'DoesNotExist'))
        ->assertNotFound();
});

it('includes construction materials in the response', function (): void {
    $building = Building::factory()->create(['building_id' => 'BuildingWithMaterials']);

    BuildingConstructionMaterial::query()->create([
        'building_id' => $building->id,
        'element_id' => null,
        'material_category' => 'RefinedMetal',
        'amount' => 400.0,
    ]);

    $this->getJson(route('buildings.show', $building->building_id))
        ->assertOk()
        ->assertJsonPath('materials.0.material_category', 'RefinedMetal')
        ->assertJsonPath('materials.0.amount', 400);
});

it('splits camelCase names in the show response', function (): void {
    $building = Building::factory()->create([
        'building_id' => 'WireRefined',
        'name' => ['en' => 'WireRefined'],
    ]);

    $this->getJson(route('buildings.show', $building->building_id))
        ->assertOk()
        ->assertJsonPath('name', 'Wire Refined');
});

it('exposes the mod on modded buildings', function (): void {
    $mod = Mod::factory()->create(['title' => 'Ronivans Legacy - Industrial Revolution']);
    $modded = Building::factory()->create(['mod_id' => $mod->id]);
    $vanilla = Building::factory()->create();

    $this->getJson(route('buildings.show', $modded->building_id))
        ->assertJsonPath('mod.title', 'Ronivans Legacy - Industrial Revolution');
    $this->getJson(route('buildings.show', $vanilla->building_id))
        ->assertJsonPath('mod', null);
});
