<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\CodexEntry;
use App\Models\Element;

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
            'title' => ['en' => 'Electrolyzer'],
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
        ->assertJsonPath('content', $content);
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
    @mkdir(public_path('assets/game/buildings'), 0777, true);
    file_put_contents(public_path($relative), 'webp');

    try {
        $this->getJson(route('codex.show', 'PowerTransformerSmall'))
            ->assertOk()
            ->assertJsonPath('image', '/'.$relative);
    } finally {
        @unlink(public_path($relative));
    }
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
    @mkdir(public_path('assets/game/buildings'), 0777, true);
    file_put_contents(public_path($relative), 'webp');

    try {
        $this->getJson(route('codex.index'))
            ->assertOk()
            ->assertJsonFragment(['codex_id' => 'Generator', 'image' => '/'.$relative]);
    } finally {
        @unlink(public_path($relative));
    }
});
