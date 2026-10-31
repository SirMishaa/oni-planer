<?php

declare(strict_types=1);

use App\Models\PrefabName;
use App\Services\GameText;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->catalogDir = sys_get_temp_dir().'/oni-gametext-'.uniqid();
    File::ensureDirectoryExists($this->catalogDir);
    File::put($this->catalogDir.'/fr.json', json_encode([
        'STRINGS.BUILDINGS.PREFABS.GENERATOR.NAME' => 'Générateur à charbon',
        'STRINGS.BUILDINGS.PREFABS.GENERATOR.DESC' => 'Brûle du charbon.',
        'STRINGS.BUILDINGS.PREFABS.GENERATOR.EFFECT' => 'Produit de l\'électricité.',
    ]));
    app('translator')->addJsonPath($this->catalogDir);
    app()->setLocale('fr');
    PrefabName::factory()->create(['prefab_id' => 'Generator', 'name_key' => 'STRINGS.BUILDINGS.PREFABS.GENERATOR.NAME']);
});

afterEach(function (): void {
    File::deleteDirectory($this->catalogDir);
});

it('translates the name, description and effect of a prefab from its name key siblings', function (): void {
    $text = resolve(GameText::class);

    expect($text->name('Generator'))->toBe('Générateur à charbon')
        ->and($text->description('Generator'))->toBe('Brûle du charbon.')
        ->and($text->effect('Generator'))->toBe('Produit de l\'électricité.');
});

it('falls back when a prefab has no key or its key is not translated', function (): void {
    PrefabName::factory()->create(['prefab_id' => 'Untranslated', 'name_key' => 'STRINGS.ITEMS.FOOD.MISSING.NAME']);
    $text = resolve(GameText::class);

    expect($text->name('Unknown'))->toBe('Unknown')
        ->and($text->name('Unknown', 'Stored name'))->toBe('Stored name')
        ->and($text->name('Untranslated', 'Stored name'))->toBe('Stored name')
        ->and($text->description('Unknown'))->toBeNull();
});
