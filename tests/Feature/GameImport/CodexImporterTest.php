<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\CodexEntry;
use App\Models\Critter;
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

    expect(CodexEntry::query()->count())->toBe(5);

    $genetics = CodexEntry::query()->where('codex_id', 'Genetics')->sole();

    expect($genetics->category)->toBe('Systems')
        ->and($genetics->parent_codex_id)->toBe('SYSTEMSTUTORIALS')
        ->and($genetics->icon_asset)->toBe('codex_genetics')
        ->and($genetics->getTranslation('title', 'en'))->toBe('Genetics');
});

it('reads the parent from the generated parentEntryID key', function (): void {
    runCodexImporter();

    $hatchHard = CodexEntry::query()->where('codex_id', 'HatchHard')->sole();

    expect($hatchHard->parent_codex_id)->toBe('HatchSpecies');
});

it('resolves content blocks into typed, locale-keyed structures', function (): void {
    runCodexImporter();

    $entry = CodexEntry::query()->where('codex_id', 'Electrolyzer')->sole();
    $blocks = $entry->content[0]['blocks'];

    expect($entry->content[0]['layout'])->toBe('Vertical')
        ->and($blocks[0])->toBe(['type' => 'text', 'style' => 'Title', 'key' => 'STRINGS.CODEX.ELECTROLYZER.TITLE', 'text' => ['en' => 'Electrolyzer']])
        ->and($blocks[1])->toBe(['type' => 'divider'])
        ->and($blocks[2])->toBe(['type' => 'image', 'sprite' => 'electrolyzer_img', 'height' => 128])
        ->and($blocks[3])->toBe(['type' => 'text', 'style' => 'Body', 'key' => 'STRINGS.CODEX.ELECTROLYZER.BODY', 'text' => ['en' => 'Splits water into oxygen and hydrogen.']]);
});

it('drops text blocks whose string key is missing', function (): void {
    runCodexImporter();

    $genetics = CodexEntry::query()->where('codex_id', 'Genetics')->sole();

    // Only the resolvable Title block remains; the MISSING block is dropped.
    expect($genetics->content[0]['blocks'])->toHaveCount(1)
        ->and($genetics->content[0]['blocks'][0]['style'])->toBe('Title');
});

it('links an entry to a building that shares its codex id, leaving lore unlinked', function (): void {
    $building = Building::factory()->create(['building_id' => 'Electrolyzer']);

    runCodexImporter();

    $electrolyzer = CodexEntry::query()->where('codex_id', 'Electrolyzer')->sole();
    $genetics = CodexEntry::query()->where('codex_id', 'Genetics')->sole();

    expect($building->fresh()->codexEntries)->toHaveCount(1)
        ->and($building->fresh()->codexEntries->first()->codex_id)->toBe('Electrolyzer')
        ->and($electrolyzer->buildings)->toHaveCount(1)
        ->and($genetics->buildings)->toHaveCount(0);
});

it('links a Species entry to every critter of the family', function (): void {
    $hard = Critter::factory()->create(['critter_id' => 'HatchHard', 'variant_family' => 'Hatch']);
    $base = Critter::factory()->create(['critter_id' => 'Hatch', 'variant_family' => 'Hatch']);

    runCodexImporter();

    $species = CodexEntry::query()->where('codex_id', 'HatchSpecies')->sole();

    expect($species->critters->pluck('critter_id')->all())->toEqualCanonicalizing(['Hatch', 'HatchHard'])
        ->and($base->fresh()->codexEntries->pluck('codex_id'))->toContain('HatchSpecies')
        ->and($hard->fresh()->codexEntries->pluck('codex_id'))->toContain('HatchSpecies', 'HatchHard');
});

it('links entries whose codex id only differs from the critter id by case', function (): void {
    Critter::factory()->create(['critter_id' => 'OilFloater', 'variant_family' => 'OilFloater']);

    runCodexImporter();

    $entry = CodexEntry::query()->where('codex_id', 'Oilfloater')->sole();

    expect($entry->critters->pluck('critter_id')->all())->toBe(['OilFloater']);
});

it('relinks idempotently when the importer runs twice', function (): void {
    Critter::factory()->create(['critter_id' => 'Hatch', 'variant_family' => 'Hatch']);

    runCodexImporter();
    runCodexImporter();

    $species = CodexEntry::query()->where('codex_id', 'HatchSpecies')->sole();

    expect($species->critters)->toHaveCount(1);
});
