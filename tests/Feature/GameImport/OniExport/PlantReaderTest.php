<?php

declare(strict_types=1);

use App\Models\Element;
use App\Models\Plant;
use App\Models\PlantVariant;
use App\Services\GameImport\OniExport\ModCatalog;
use App\Services\GameImport\OniExport\OniExport;
use App\Services\GameImport\OniExport\PlantReader;
use App\Services\GameImport\PlantImporter;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fixturePlantReader(): PlantReader
{
    $root = base_path('tests/Fixtures/OniExport');
    $export = new OniExport($root);
    $catalog = ModCatalog::fromExport($export, $root.'/mods', ['3557584850']);
    $catalog->persist();

    return new PlantReader($export, $catalog);
}

/** @return array<string, array<string, mixed>> */
function readFixturePlants(): array
{
    return collect(fixturePlantReader()->records())->keyBy('plant_id')->all();
}

it('reads bristle blossom growth', function (): void {
    $flower = readFixturePlants()['PrickleFlower'];

    expect($flower['variant_id'])->toBe('PrickleFlower')
        ->and($flower['is_base'])->toBeTrue()
        ->and($flower['growth_time'])->toBe(6.0)
        ->and($flower['min_temp'])->toBe(218.15)
        ->and($flower['max_temp'])->toBe(398.15)
        ->and($flower['min_pressure'])->toBe(0.15)
        ->and($flower['max_pressure'])->toBe(10.0)
        ->and($flower['atmosphere_element_id'])->toBeNull()
        ->and($flower['light_required'])->toBeTrue()
        ->and($flower['kanim'])->toBe('bristleblossom_kanim')
        ->and($flower['name_localization_id'])->toBe('STRINGS.CREATURES.SPECIES.PRICKLEFLOWER.NAME')
        ->and($flower['dlc_id'])->toBeNull()
        ->and($flower['mod_id'])->toBeNull()
        ->and($flower['inputs'])->toHaveCount(1)
        ->and($flower['inputs'][0]['element_id'])->toBe('Water')
        ->and($flower['inputs'][0]['input_type'])->toBe('irrigation')
        ->and($flower['inputs'][0]['amount_per_cycle'])->toEqualWithDelta(20.0, 0.0001)
        ->and($flower['outputs'])->toBe([
            ['element_id' => 'PrickleFruit', 'amount_per_harvest' => 1.0, 'output_type' => 'food'],
            ['element_id' => 'PrickleFlowerSeed', 'amount_per_harvest' => 1.0, 'output_type' => 'resource'],
        ]);
});

it('leaves pressure empty for a plant that is not pressure sensitive and takes the dlc', function (): void {
    $lily = readFixturePlants()['BogLily'];

    expect($lily['min_pressure'])->toBeNull()
        ->and($lily['max_pressure'])->toBeNull()
        ->and($lily['light_required'])->toBeFalse()
        ->and($lily['dlc_id'])->toBe('EXPANSION1_ID')
        ->and($lily['inputs'])->toBe([
            ['element_id' => 'Water', 'amount_per_cycle' => 60.0, 'input_type' => 'irrigation'],
            ['element_id' => 'Dirt', 'amount_per_cycle' => 12.0, 'input_type' => 'fertilizer'],
        ]);
});

it('skips plants of unsupported mods and counts dropped references to their items', function (): void {
    $reader = fixturePlantReader();
    $plants = collect($reader->records())->keyBy('plant_id');

    expect($plants)->not->toHaveKey('BlueprintsPlant')
        ->and($plants['BogLily']['outputs'])->toBe([])
        ->and($reader->skippedReferences())->toBe(2);
});

it('imports plant records, storing unbounded pressure as zero and dropping items that are not elements', function (): void {
    foreach (['Water', 'Dirt'] as $elementId) {
        Element::query()->create([
            'element_id' => $elementId, 'state' => 'liquid', 'molar_mass' => 1.0,
            'toxicity' => 0, 'material_category' => 'Liquid', 'tags' => [],
            'name' => ['en' => $elementId], 'is_disabled' => false,
        ]);
    }

    $importer = new PlantImporter(new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot')));
    $importer->importRecords(fixturePlantReader()->records());

    $lily = PlantVariant::query()->where('variant_id', 'BogLily')->firstOrFail();
    $flower = PlantVariant::query()->where('variant_id', 'PrickleFlower')->firstOrFail();

    expect(Plant::query()->count())->toBe(2)
        ->and($lily->min_pressure)->toBe(0.0)
        ->and($lily->max_pressure)->toBe(0.0)
        ->and($lily->inputs)->toHaveCount(2)
        ->and($flower->inputs)->toHaveCount(1)
        ->and($flower->outputs)->toHaveCount(0)
        ->and($importer->skippedReferences())->toBe(2);
});
