<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Services\GameImport\OniExport\ModCatalog;
use App\Services\GameImport\OniExport\OniExport;
use App\Services\GameImport\OniExport\OniExportMissing;

function oniFixtures(): string
{
    return base_path('tests/Fixtures/OniExport');
}

function modCatalog(array $supported = ['3557584850']): ModCatalog
{
    return ModCatalog::fromExport(new OniExport(oniFixtures()), oniFixtures().'/mods', $supported);
}

it('maps a supported mod building to its mod', function (): void {
    $catalog = modCatalog();
    $catalog->persist();

    $mod = Mod::query()->where('workshop_id', '3557584850')->sole();

    expect($mod->title)->toBe('Ronivans Legacy - Industrial Revolution')
        ->and($mod->static_id)->toBe('RonivansLegacy_ChemicalProcessing')
        ->and($catalog->modIdFor('Mining_MineralDrill'))->toBe($mod->id)
        ->and($catalog->isSkipped('Mining_MineralDrill'))->toBeFalse();
});

it('maps supported mod entities and elements to their mod', function (): void {
    $catalog = modCatalog();
    $catalog->persist();

    $modId = Mod::query()->where('workshop_id', '3557584850')->value('id');

    expect($catalog->modIdFor('AIO_BioplasticGasket'))->toBe($modId)
        ->and($catalog->modIdFor('AIO_ChromiteOre_Solid'))->toBe($modId)
        ->and($catalog->modIdForWorkshop('3557584850'))->toBe($modId);
});

it('treats base prefabs as base', function (): void {
    $catalog = modCatalog();
    $catalog->persist();

    expect($catalog->modIdFor('Electrolyzer'))->toBeNull()
        ->and($catalog->isSkipped('Electrolyzer'))->toBeFalse()
        ->and($catalog->modIdFor('AbyssVista'))->toBeNull()
        ->and($catalog->isSkipped('Aerogel'))->toBeFalse();
});

it('skips prefabs of unsupported mods and reports them', function (): void {
    $catalog = modCatalog();
    $catalog->persist();

    expect($catalog->isSkipped('Stairs'))->toBeTrue()
        ->and($catalog->modIdFor('Stairs'))->toBeNull()
        ->and($catalog->isSkipped('BlueprintsV2_Element_Note'))->toBeTrue()
        ->and($catalog->skippedReport())->toContain(['workshop_id' => '2012810337', 'title' => 'Stairs', 'entities' => 2])
        ->and($catalog->skippedReport())->toContain(['workshop_id' => '3468585385', 'title' => 'Blueprints Expanded', 'entities' => 1]);
});

it('drops content of a mod removed from the supported list', function (): void {
    $catalog = modCatalog([]);
    $catalog->persist();

    expect($catalog->isSkipped('Mining_MineralDrill'))->toBeTrue()
        ->and($catalog->modIdFor('Mining_MineralDrill'))->toBeNull()
        ->and($catalog->isSkipped('Electrolyzer'))->toBeFalse()
        ->and(Mod::query()->count())->toBe(0);
});

it('updates the existing mod row when persisting twice', function (): void {
    modCatalog()->persist();
    modCatalog()->persist();

    expect(Mod::query()->count())->toBe(1);
});

it('lists translation and element files of supported mods only', function (): void {
    $catalog = modCatalog();
    $root = oniFixtures().'/mods/3557584850';

    expect($catalog->potPaths())->toBe([$root.'/translation_template.pot'])
        ->and($catalog->poPaths('fr'))->toBe([$root.'/translations/fr.po'])
        ->and($catalog->poPaths('de'))->toBe([])
        ->and($catalog->elementYamlPaths())->toBe([$root.'/elements/ChemicalProcessing_IO_Elements.yaml'])
        ->and(modCatalog([])->potPaths())->toBe([]);
});

it('throws when an export file is missing', function (): void {
    (new OniExport(oniFixtures()))->json('nope.json');
})->throws(OniExportMissing::class);

it('reads the build version', function (): void {
    expect((new OniExport(oniFixtures()))->buildVersion())->toBe('U59-744825-SCRPAN');
});
