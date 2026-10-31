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
