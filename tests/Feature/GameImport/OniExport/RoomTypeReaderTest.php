<?php

declare(strict_types=1);

use App\Models\RoomType;
use App\Services\GameImport\OniExport\ModCatalog;
use App\Services\GameImport\OniExport\OniExport;
use App\Services\GameImport\OniExport\RoomTypeReader;
use App\Services\GameImport\RoomTypeImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array<string, array<string, mixed>> */
function readFixtureRoomTypes(): array
{
    $root = base_path('tests/Fixtures/OniExport');
    $export = new OniExport($root);

    return collect(new RoomTypeReader($export, ModCatalog::fromExport($export, $root.'/mods', []))->records())
        ->keyBy('room_type_id')
        ->all();
}

it('imports the washroom room type with its constraints', function (): void {
    $root = base_path('tests/Fixtures/OniExport');
    $export = new OniExport($root);
    $reader = new RoomTypeReader($export, ModCatalog::fromExport($export, $root.'/mods', []));

    new RoomTypeImporter()->importRecords($reader->records());

    $bathroom = RoomType::query()->where('room_type_id', 'PlumbedBathroom')->firstOrFail();

    expect($bathroom->category)->toBe('Bathroom')
        ->and($bathroom->name_key)->toBe('STRINGS.ROOMS.TYPES.PLUMBEDBATHROOM.NAME')
        ->and($bathroom->priority)->toBe(1)
        ->and($bathroom->bonus_effect)->toBe('RoomBathroom')
        ->and($bathroom->size_min)->toBe(12)
        ->and($bathroom->size_max)->toBe(128)
        ->and($bathroom->requirements[0])->toBe([
            'description_key' => 'STRINGS.ROOMS.CRITERIA.FLUSHTOILETTYPE.DESCRIPTION',
            'tags' => ['FlushToiletType'],
            'min' => 1,
            'max' => null,
        ]);
});

it('keeps additional constraints, skipping size ones which become size bounds', function (): void {
    $requirements = readFixtureRoomTypes()['PlumbedBathroom']['requirements'];

    expect($requirements)->toHaveCount(4)
        ->and($requirements[3]['description_key'])->toBe('STRINGS.ROOMS.CRITERIA.NO_INDUSTRIAL_MACHINERY.DESCRIPTION')
        ->and($requirements[3]['tags'])->toBe(['IndustrialMachinery'])
        ->and(array_column($requirements, 'description_key'))->toContain('STRINGS.ROOMS.CRITERIA.NO_OUTHOUSES.DESCRIPTION');
});

it('skips room types without a game string key', function (): void {
    expect(readFixtureRoomTypes())->toHaveKeys(['PlumbedBathroom', 'Latrine'])->not->toHaveKey('BathroomRoom');
});
