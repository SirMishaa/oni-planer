<?php

declare(strict_types=1);

use App\Models\Element;
use App\Models\ElementGasProperty;
use App\Models\ElementSolidProperty;
use App\Models\ElementSpecialProperty;
use App\Services\GameImport\ElementImporter;
use App\Services\GameImport\OniExport\ElementReader;
use App\Services\GameImport\OniExport\ModCatalog;
use App\Services\GameImport\OniExport\OniExport;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/** Reads the fixture export and imports every element record; mod strings override base ones. */
function importFixtureElements(bool $withModStrings = true): ModCatalog
{
    $root = base_path('tests/Fixtures/OniExport');
    $export = new OniExport($root);
    $catalog = ModCatalog::fromExport($export, $root.'/mods', ['3557584850']);
    $catalog->persist();

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'), $withModStrings ? $catalog->potPaths() : []);
    new ElementImporter($resolver)->importRecords(new ElementReader($export, $catalog)->records());

    return $catalog;
}

it('reads oxygen from the export', function (): void {
    importFixtureElements(withModStrings: false);

    $oxygen = Element::query()->where('element_id', 'Oxygen')->firstOrFail();

    expect($oxygen->state)->toBe('gas')
        ->and($oxygen->molar_mass)->toBe(15.9994)
        ->and($oxygen->material_category)->toBe('Breathable')
        ->and($oxygen->low_temp_transition_target)->toBe('LiquidOxygen')
        ->and($oxygen->mod_id)->toBeNull()
        ->and($oxygen->dlc_id)->toBeNull()
        ->and($oxygen->getTranslation('name', 'en'))->toBe('Oxygen')
        ->and($oxygen->getTranslation('description', 'en'))->not->toBeEmpty()
        ->and($oxygen->thermalProperties->specific_heat_capacity)->toBe(1.005)
        ->and($oxygen->thermalProperties->low_temp)->toBe(90.19)
        ->and($oxygen->gasProperties->default_pressure)->toBe(101.3)
        ->and($oxygen->gasProperties->is_breathable)->toBeTrue()
        ->and($oxygen->gasProperties->is_toxic)->toBeFalse();
});

it('imports a ronivan element with its mod id', function (): void {
    $catalog = importFixtureElements();

    $bronze = Element::query()->where('element_id', 'PhosphorBronze')->firstOrFail();

    expect($bronze->state)->toBe('solid')
        ->and($bronze->mod_id)->toBe($catalog->modIdForWorkshop('3557584850'))
        ->and($bronze->mod_id)->not->toBeNull()
        ->and($bronze->getTranslation('name', 'en'))->toBe('Phosphor Bronze')
        ->and(ElementSolidProperty::query()->where('element_id', 'PhosphorBronze')->exists())->toBeTrue();
});

it('includes an element the mod re-enables', function (): void {
    importFixtureElements();

    expect(Element::query()->where('element_id', 'Brick')->value('state'))->toBe('solid');
});

it('skips disabled elements', function (): void {
    importFixtureElements();

    expect(Element::query()->where('element_id', 'Aerogel')->exists())->toBeFalse();
});

it('maps the vacuum state to the special state', function (): void {
    importFixtureElements();

    expect(Element::query()->where('element_id', 'Vacuum')->value('state'))->toBe('special')
        ->and(ElementSpecialProperty::query()->where('element_id', 'Vacuum')->exists())->toBeTrue()
        ->and(ElementGasProperty::query()->where('element_id', 'Vacuum')->exists())->toBeFalse();
});

it('stores no transition temperature when the element has no transition target', function (): void {
    importFixtureElements();

    $oxygen = Element::query()->where('element_id', 'Oxygen')->firstOrFail();
    $brick = Element::query()->where('element_id', 'Brick')->firstOrFail();

    expect($oxygen->thermalProperties->high_temp)->toBeNull()
        ->and($oxygen->thermalProperties->low_temp)->toBe(90.19)
        ->and($brick->thermalProperties->low_temp)->toBeNull()
        ->and($brick->thermalProperties->high_temp)->toBe(2000.0);
});

it('reads the element ui colour from elements.json', function (): void {
    importFixtureElements();

    expect(Element::query()->where('element_id', 'Oxygen')->value('ui_color'))->toBe(13158600)
        ->and(Element::query()->where('element_id', 'Brick')->value('ui_color'))->toBeNull()
        ->and(Element::query()->where('element_id', 'Oxygen')->firstOrFail()->uiColorHex())->toBe('#c8c8c8');
});

it('gives null ui colours when elements.json is missing', function (): void {
    $root = sys_get_temp_dir().'/oni-export-'.uniqid();
    mkdir($root.'/database', recursive: true);
    foreach (['planner_extras.json', 'building.json'] as $file) {
        copy(base_path('tests/Fixtures/OniExport/database/'.$file), $root.'/database/'.$file);
    }

    try {
        $export = new OniExport($root);
        $records = new ElementReader($export, ModCatalog::fromExport($export, $root.'/mods', []))->records();

        expect($records)->not->toBeEmpty()
            ->and(array_unique(array_column($records, 'ui_color')))->toBe([null]);
    } finally {
        File::deleteDirectory($root);
    }
});
