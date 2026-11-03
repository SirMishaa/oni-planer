<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/oni-sync-'.uniqid();
    File::ensureDirectoryExists($this->workDir);

    config([
        'game.export_source' => $this->workDir.'/src-export',
        'game.mods_source' => $this->workDir.'/src-mods',
        'game.export_path' => $this->workDir.'/out/export',
        'game.mods_path' => $this->workDir.'/out/mods',
        'game.supported_mods' => ['3557584850'],
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->workDir);
});

it('mirrors the export and supported mod files', function (): void {
    File::copyDirectory(base_path('tests/Fixtures/OniExport/database'), $this->workDir.'/src-export/database');
    File::copyDirectory(base_path('tests/Fixtures/OniExport/mods/3557584850'), $this->workDir.'/src-mods/3557584850');
    File::put($this->workDir.'/src-mods/3557584850/RonivansLegacy.dll', 'binary');
    File::ensureDirectoryExists($this->workDir.'/out/export/database');
    File::put($this->workDir.'/out/export/database/stale.json', '{}');

    $this->artisan('game:sync-export')->assertSuccessful();

    expect($this->workDir.'/out/export/database/building.json')->toBeFile()
        ->and($this->workDir.'/out/export/database/stale.json')->not->toBeFile()
        ->and($this->workDir.'/out/mods/3557584850/mod.yaml')->toBeFile()
        ->and($this->workDir.'/out/mods/3557584850/translation_template.pot')->toBeFile()
        ->and($this->workDir.'/out/mods/3557584850/translations/fr.po')->toBeFile()
        ->and($this->workDir.'/out/mods/3557584850/elements/ChemicalProcessing_IO_Elements.yaml')->toBeFile()
        ->and($this->workDir.'/out/mods/3557584850/RonivansLegacy.dll')->not->toBeFile();
});

it('fails when the export has no building.json', function (): void {
    File::ensureDirectoryExists($this->workDir.'/src-export/database');

    $this->artisan('game:sync-export')->assertFailed();

    expect($this->workDir.'/out/export')->not->toBeDirectory();
});
