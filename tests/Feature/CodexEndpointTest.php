<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\CodexEntry;
use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\Element;
use Illuminate\Support\Facades\File;

// Asset checks read public_path(): point it at a throwaway directory so tests never touch real game assets.
beforeEach(function (): void {
    $this->publicDir = sys_get_temp_dir().'/oni-codex-public-'.uniqid();
    app()->usePublicPath($this->publicDir);
});

afterEach(function (): void {
    File::deleteDirectory($this->publicDir);
});

it('lists codex entries with their natural key, category, parent and title', function (): void {
    CodexEntry::factory()->create([
        'codex_id' => 'Electrolyzer',
        'category' => 'Buildings',
        'parent_codex_id' => 'OXYGEN',
        'title' => ['en' => 'Electrolyzer'],
    ]);

    $this->getJson(route('codex.index'))
        ->assertOk()
        ->assertJsonFragment([
            'codex_id' => 'Electrolyzer',
            'category' => 'Buildings',
            'parent_codex_id' => 'OXYGEN',
            'title' => 'Electrolyzer',
        ]);
});

it('returns an article with its content blocks', function (): void {
    $content = [
        [
            'layout' => 'Vertical',
            'lock_id' => null,
            'blocks' => [
                ['type' => 'text', 'style' => 'Body', 'text' => ['en' => 'Splits water into oxygen and hydrogen.']],
                ['type' => 'divider'],
            ],
        ],
    ];

    CodexEntry::factory()->create([
        'codex_id' => 'Electrolyzer',
        'category' => 'Buildings',
        'title' => ['en' => 'Electrolyzer'],
        'content' => $content,
    ]);

    $this->getJson(route('codex.show', 'Electrolyzer'))
        ->assertOk()
        ->assertJsonPath('codex_id', 'Electrolyzer')
        ->assertJsonPath('title', 'Electrolyzer')
        ->assertJsonPath('content.0.blocks.0', ['type' => 'text', 'style' => 'Body', 'text' => 'Splits water into oxygen and hydrogen.'])
        ->assertJsonPath('content.0.blocks.1', ['type' => 'divider']);
});

it('indexes linked map prefabs even when their article has a different id', function (): void {
    $buildingArticle = CodexEntry::factory()->create(['codex_id' => 'PressArticle']);
    $building = Building::factory()->create(['building_id' => 'Biochemistry_ExpellerPress']);
    $buildingArticle->buildings()->attach($building);

    $critterArticle = CodexEntry::factory()->create(['codex_id' => 'PacuSpecies']);
    $critter = Critter::factory()->create(['critter_id' => 'PacuBeta']);
    $critterArticle->critters()->attach($critter);
    CritterMorph::factory()->create(['critter_id' => $critter->id, 'morph_id' => 'PacuBetaBaby']);

    $entries = collect($this->getJson(route('codex.index'))->assertOk()->json())->keyBy('codex_id');

    expect($entries['PressArticle']['prefabs'] ?? [])->toBe(['Biochemistry_ExpellerPress'])
        ->and($entries['PacuSpecies']['prefabs'] ?? [])->toBe(['PacuBeta', 'PacuBetaBaby']);
});

it('adds adult variant diet and butchering blocks without borrowing facts across variants', function (): void {
    config(['game.assets_path' => $this->publicDir]);
    foreach (['plants/PlanktonCoral', 'foods/FishMeat', 'critters/PrehistoricPacu', 'critters/PacuBeta', 'critters/Pacu'] as $sprite) {
        $path = $this->publicDir.'/'.$sprite.'.webp';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');
    }

    $entry = CodexEntry::factory()->create(['codex_id' => 'PacuSpecies', 'content' => []]);
    $base = Critter::factory()->create(['critter_id' => 'Pacu']);
    $beta = Critter::factory()->create(['critter_id' => 'PacuBeta']);
    $entry->critters()->attach([$base->id, $beta->id]);

    CritterMorph::factory()->create(['critter_id' => $base->id, 'morph_id' => 'Pacu', 'is_base' => true]);
    $morph = CritterMorph::factory()->create([
        'critter_id' => $beta->id, 'morph_id' => 'PacuBeta', 'name' => ['en' => 'Beta Pacu'], 'is_base' => true,
        'codex_data' => ['butcher_drops' => [['id' => 'FishMeat', 'mass_kg' => 1, 'kcal' => 1000]]],
    ]);
    CritterMorphDiet::factory()->create([
        'critter_morph_id' => $morph->id, 'consumed_element_id' => 'PlanktonCoral',
        'produced_element_id' => 'Lime', 'food_kind' => 'plant',
        'codex_data' => ['amount' => 12.5, 'unit' => 'percent_growth_per_cycle', 'output_kg_per_cycle' => 5],
    ]);
    CritterMorph::factory()->create(['critter_id' => $beta->id, 'morph_id' => 'PacuBetaBaby', 'is_base' => false]);
    $predator = Critter::factory()->create(['critter_id' => 'PrehistoricPacu']);
    $predatorMorph = CritterMorph::factory()->create(['critter_id' => $predator->id, 'morph_id' => 'PrehistoricPacu', 'is_base' => true]);
    CritterMorphDiet::factory()->create([
        'critter_morph_id' => $predatorMorph->id, 'consumed_element_id' => 'PacuBeta', 'food_kind' => 'prey',
    ]);

    $this->getJson(route('codex.show', 'PacuSpecies'))
        ->assertOk()
        ->assertJsonCount(1, 'content')
        ->assertJsonPath('content.0.section_title', 'Beta Pacu (PacuBeta)')
        ->assertJsonPath('content.0.section_sprite', '/assets/game/critters/PacuBeta.webp')
        ->assertJsonPath('content.0.blocks.1.text', 'PlanktonCoral 12,5 % de croissance/cycle → Lime 5 kg/cycle')
        ->assertJsonPath('content.0.blocks.1.parts.0.sprite', '/assets/game/plants/PlanktonCoral.webp')
        ->assertJsonPath('content.0.blocks.1.parts.1.text', ' → ')
        ->assertJsonPath('content.0.blocks.1.parts.2', ['text' => 'Lime 5 kg/cycle', 'sprite' => null])
        ->assertJsonPath('content.0.blocks.3.text', 'FishMeat : 1 kg, 1 000 kcal par découpe')
        ->assertJsonPath('content.0.blocks.3.parts.0.sprite', '/assets/game/foods/FishMeat.webp')
        ->assertJsonPath('content.0.blocks.5.text', 'Consommé par PrehistoricPacu')
        ->assertJsonPath('content.0.blocks.5.parts.1.sprite', '/assets/game/critters/PrehistoricPacu.webp')
        ->assertDontSee('PacuBetaBaby');
});

it('translates the article title and text blocks from their STRINGS keys', function (): void {
    $catalogDir = sys_get_temp_dir().'/oni-codex-'.uniqid();
    File::ensureDirectoryExists($catalogDir);
    File::put($catalogDir.'/fr.json', json_encode([
        'STRINGS.CODEX.ELECTROLYZER.TITLE' => 'Électrolyseur',
        'STRINGS.CODEX.ELECTROLYZER.BODY' => "Sépare l'eau en oxygène et hydrogène.",
    ]));
    app('translator')->addJsonPath($catalogDir);
    app()->setLocale('fr');
    CodexEntry::factory()->create([
        'codex_id' => 'Electrolyzer',
        'category' => 'Buildings',
        'title' => ['en' => 'Electrolyzer'],
        'title_key' => 'STRINGS.CODEX.ELECTROLYZER.TITLE',
        'content' => [['layout' => 'Vertical', 'lock_id' => null, 'blocks' => [
            ['type' => 'text', 'style' => 'Body', 'key' => 'STRINGS.CODEX.ELECTROLYZER.BODY', 'text' => ['en' => 'Splits water.']],
        ]]],
    ]);

    $this->getJson(route('codex.show', 'Electrolyzer'))
        ->assertOk()
        ->assertJsonPath('title', 'Électrolyseur')
        ->assertJsonPath('content.0.blocks.0.text', "Sépare l'eau en oxygène et hydrogène.");

    File::deleteDirectory($catalogDir);
});

it('exposes linked buildings as navigable deep-links and elements via their kebab slug', function (): void {
    $entry = CodexEntry::factory()->create(['codex_id' => 'Electrolyzer', 'category' => 'Buildings']);

    $building = Building::factory()->create(['building_id' => 'Electrolyzer', 'name' => ['en' => 'Électrolyseur']]);
    $element = Element::factory()->create(['element_id' => 'OxygenGas', 'name' => ['en' => 'Oxygène']]);

    $entry->buildings()->attach($building->id);
    $entry->elements()->attach($element->id);

    $this->getJson(route('codex.show', 'Electrolyzer'))
        ->assertOk()
        ->assertJsonFragment([
            'type' => 'building',
            'key' => 'Electrolyzer',
            'label' => 'Électrolyseur',
            'url' => route('buildings.show', 'Electrolyzer'),
        ])
        ->assertJsonFragment([
            'type' => 'element',
            'key' => 'OxygenGas',
            'label' => 'Oxygène',
            'url' => route('elements.show', 'oxygen-gas'),
        ]);
});

it('returns 404 for an unknown codex entry', function (): void {
    $this->getJson(route('codex.show', 'NopeDoesNotExist'))
        ->assertNotFound();
});

it('resolves an article image from a linked entity that has an extracted asset', function (): void {
    $entry = CodexEntry::factory()->create(['codex_id' => 'PowerTransformerSmall', 'category' => 'Buildings']);
    $building = Building::factory()->create(['building_id' => 'PowerTransformerSmall']);
    $entry->buildings()->attach($building->id);

    $relative = 'assets/game/buildings/PowerTransformerSmall.webp';
    File::ensureDirectoryExists(public_path('assets/game/buildings'));
    File::put(public_path($relative), 'webp');

    $this->getJson(route('codex.show', 'PowerTransformerSmall'))
        ->assertOk()
        ->assertJsonPath('image', '/'.$relative);
});

it('returns a null image when no linked entity has an asset', function (): void {
    CodexEntry::factory()->create(['codex_id' => 'LoreOnly', 'category' => 'MyLog']);

    $this->getJson(route('codex.show', 'LoreOnly'))
        ->assertOk()
        ->assertJsonPath('image', null);
});

it('includes a thumbnail on list items whose linked entity has an asset', function (): void {
    $entry = CodexEntry::factory()->create(['codex_id' => 'Generator', 'category' => 'Buildings']);
    $building = Building::factory()->create(['building_id' => 'Generator']);
    $entry->buildings()->attach($building->id);

    $relative = 'assets/game/buildings/Generator.webp';
    File::ensureDirectoryExists(public_path('assets/game/buildings'));
    File::put(public_path($relative), 'webp');

    $this->getJson(route('codex.index'))
        ->assertOk()
        ->assertJsonFragment(['codex_id' => 'Generator', 'image' => '/'.$relative]);
});
