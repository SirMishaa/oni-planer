<?php

declare(strict_types=1);

use App\Services\GameImport\GameTranslationWriter;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->translationsDir = sys_get_temp_dir().'/oni-po-'.uniqid();
    $this->outputDir = sys_get_temp_dir().'/oni-lang-'.uniqid();
    File::ensureDirectoryExists($this->translationsDir);
    File::copy(base_path('tests/Fixtures/GameImport/translations/fr.po'), $this->translationsDir.'/fr.po');
});

afterEach(function (): void {
    File::deleteDirectory($this->translationsDir);
    File::deleteDirectory($this->outputDir);
});

it('writes the english catalog from the pot template without markup', function (): void {
    new GameTranslationWriter()->write(
        base_path('tests/Fixtures/GameImport/strings.pot'),
        $this->translationsDir,
        $this->outputDir,
    );

    $english = json_decode(File::get($this->outputDir.'/en.json'), true);
    expect($english)
        ->toHaveKey('STRINGS.ELEMENTS.OXYGEN.NAME', 'Oxygen')
        ->toHaveKey('STRINGS.CODEX.TEST.MULTILINE', 'First part of the body. Second part with a link.');
});

it('writes one catalog per po file, falling back to english for untranslated strings', function (): void {
    new GameTranslationWriter()->write(
        base_path('tests/Fixtures/GameImport/strings.pot'),
        $this->translationsDir,
        $this->outputDir,
    );

    $french = json_decode(File::get($this->outputDir.'/fr.json'), true);
    expect($french)
        ->toHaveKey('STRINGS.ELEMENTS.OXYGEN.NAME', 'Oxygène')
        ->toHaveKey('STRINGS.CODEX.TEST.MULTILINE', 'Première partie. Seconde partie avec un lien.')
        ->toHaveKey('STRINGS.ELEMENTS.CARBONDIOXIDE.NAME', 'Carbon Dioxide');
});

it('serves game strings through the laravel translator', function (): void {
    new GameTranslationWriter()->write(
        base_path('tests/Fixtures/GameImport/strings.pot'),
        $this->translationsDir,
        $this->outputDir,
    );
    app('translator')->addJsonPath($this->outputDir);
    app()->setLocale('fr');

    expect(__('STRINGS.ELEMENTS.OXYGEN.NAME'))->toBe('Oxygène')
        ->and(__('STRINGS.ELEMENTS.CARBONDIOXIDE.NAME'))->toBe('Carbon Dioxide');
});

it('writes mod strings into the french catalog with english fallback', function (): void {
    $mod = base_path('tests/Fixtures/OniExport/mods/3557584850');

    new GameTranslationWriter()->write(
        base_path('tests/Fixtures/GameImport/strings.pot'),
        $this->translationsDir,
        $this->outputDir,
        [$mod.'/translation_template.pot'],
        ['fr' => [$mod.'/translations/fr.po']],
    );

    $french = json_decode(File::get($this->outputDir.'/fr.json'), true);
    $english = json_decode(File::get($this->outputDir.'/en.json'), true);
    expect($french)
        ->toHaveKey('STRINGS.BUILDINGS.PREFABS.MINING_MINERALDRILL.NAME', 'Foreuse minérale')
        ->toHaveKey('STRINGS.BUILDINGS.PREFABS.MINING_MINERALDRILL.EFFECT', 'Drills minerals.')
        ->and($english)->toHaveKey('STRINGS.BUILDINGS.PREFABS.MINING_MINERALDRILL.NAME', 'Mineral Drill');
});

it('keeps the base french translation when a mod overrides a base key in english', function (): void {
    $mod = base_path('tests/Fixtures/OniExport/mods/3557584850');

    new GameTranslationWriter()->write(
        base_path('tests/Fixtures/GameImport/strings.pot'),
        $this->translationsDir,
        $this->outputDir,
        [$mod.'/translation_template.pot'],
        ['fr' => [$mod.'/translations/fr.po']],
    );

    $french = json_decode(File::get($this->outputDir.'/fr.json'), true);
    $english = json_decode(File::get($this->outputDir.'/en.json'), true);
    expect($french)->toHaveKey('STRINGS.ELEMENTS.OXYGEN.NAME', 'Oxygène')
        ->and($english)->toHaveKey('STRINGS.ELEMENTS.OXYGEN.NAME', 'Modded Oxygen');
});
