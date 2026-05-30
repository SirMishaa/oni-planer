<?php

declare(strict_types=1);

use App\Models\CodexEntry;
use App\Services\GameImport\CodexImporter;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runCodexImporter(): void
{
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new CodexImporter($resolver)->import(base_path('tests/Fixtures/GameImport/codex'));
}

it('imports codex entries with category from the folder', function (): void {
    runCodexImporter();

    expect(CodexEntry::query()->count())->toBe(2);

    $genetics = CodexEntry::query()->where('codex_id', 'Genetics')->sole();

    expect($genetics->category)->toBe('Systems')
        ->and($genetics->parent_codex_id)->toBe('SYSTEMSTUTORIALS')
        ->and($genetics->icon_asset)->toBe('codex_genetics')
        ->and($genetics->getTranslation('title', 'en'))->toBe('Genetics');
});

it('resolves content blocks into typed, locale-keyed structures', function (): void {
    runCodexImporter();

    $entry = CodexEntry::query()->where('codex_id', 'Electrolyzer')->sole();
    $blocks = $entry->content[0]['blocks'];

    expect($entry->content[0]['layout'])->toBe('Vertical')
        ->and($blocks[0])->toBe(['type' => 'text', 'style' => 'Title', 'text' => ['en' => 'Electrolyzer']])
        ->and($blocks[1])->toBe(['type' => 'divider'])
        ->and($blocks[2])->toBe(['type' => 'image', 'sprite' => 'electrolyzer_img', 'height' => 128])
        ->and($blocks[3])->toBe(['type' => 'text', 'style' => 'Body', 'text' => ['en' => 'Splits water into oxygen and hydrogen.']]);
});

it('drops text blocks whose string key is missing', function (): void {
    runCodexImporter();

    $genetics = CodexEntry::query()->where('codex_id', 'Genetics')->sole();

    // Only the resolvable Title block remains; the MISSING block is dropped.
    expect($genetics->content[0]['blocks'])->toHaveCount(1)
        ->and($genetics->content[0]['blocks'][0]['style'])->toBe('Title');
});
