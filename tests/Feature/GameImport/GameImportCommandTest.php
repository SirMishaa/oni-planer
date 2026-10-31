<?php

declare(strict_types=1);

use App\Models\Element;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->langPath = sys_get_temp_dir().'/oni-lang-'.uniqid();
});

afterEach(function (): void {
    File::deleteDirectory($this->langPath);
});

it('imports elements from game data yaml files', function (): void {
    $this->artisan('game:import', [
        '--game-path' => base_path('game-data/OxygenNotIncluded_Data'),
        '--skip-extractor' => true,
        '--cache-path' => base_path('tests/Fixtures/GameImport'),
        '--lang-path' => $this->langPath,
    ])
        ->assertSuccessful();

    expect(Element::query()->count())->toBeGreaterThan(0);
});

it('truncates existing data before reimport', function (): void {
    Element::query()->create([
        'element_id' => 'OldElement', 'state' => 'gas', 'molar_mass' => 1.0,
        'toxicity' => 0, 'material_category' => 'Test', 'tags' => [],
        'name' => ['en' => 'Old'], 'is_disabled' => false,
    ]);

    $this->artisan('game:import', [
        '--game-path' => base_path('game-data/OxygenNotIncluded_Data'),
        '--skip-extractor' => true,
        '--cache-path' => base_path('tests/Fixtures/GameImport'),
        '--lang-path' => $this->langPath,
    ])
        ->assertSuccessful();

    expect(Element::query()->where('element_id', 'OldElement')->exists())->toBeFalse();
});

it('writes the game translation catalogs', function (): void {
    $this->artisan('game:import', [
        '--game-path' => base_path('game-data/OxygenNotIncluded_Data'),
        '--skip-extractor' => true,
        '--cache-path' => base_path('tests/Fixtures/GameImport'),
        '--translations-path' => base_path('tests/Fixtures/GameImport/translations'),
        '--lang-path' => $this->langPath,
    ])
        ->assertSuccessful();

    expect($this->langPath.'/en.json')->toBeFile()
        ->and($this->langPath.'/fr.json')->toBeFile();
});
