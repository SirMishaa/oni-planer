<?php

declare(strict_types=1);

use App\Models\Element;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports elements from game data yaml files', function (): void {
    $this->artisan('game:import', [
        '--game-path' => base_path('game-data/OxygenNotIncluded_Data'),
        '--skip-extractor' => true,
        '--cache-path' => base_path('tests/Fixtures/GameImport'),
    ])
        ->assertSuccessful();

    expect(Element::count())->toBeGreaterThan(0);
});

it('truncates existing data before reimport', function (): void {
    Element::create([
        'element_id' => 'OldElement', 'state' => 'gas', 'molar_mass' => 1.0,
        'toxicity' => 0, 'material_category' => 'Test', 'tags' => [],
        'name' => ['en' => 'Old'], 'is_disabled' => false,
    ]);

    $this->artisan('game:import', [
        '--game-path' => base_path('game-data/OxygenNotIncluded_Data'),
        '--skip-extractor' => true,
        '--cache-path' => base_path('tests/Fixtures/GameImport'),
    ])
        ->assertSuccessful();

    expect(Element::where('element_id', 'OldElement')->exists())->toBeFalse();
});
