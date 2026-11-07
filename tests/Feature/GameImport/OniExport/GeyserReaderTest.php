<?php

declare(strict_types=1);

use App\Models\Element;
use App\Models\GeyserType;
use App\Models\Mod;
use App\Services\GameImport\GeyserImporter;
use App\Services\GameImport\OniExport\GeyserReader;
use App\Services\GameImport\OniExport\ModCatalog;
use App\Services\GameImport\OniExport\OniExport;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fixtureGeyserReader(): GeyserReader
{
    $root = base_path('tests/Fixtures/OniExport');
    $export = new OniExport($root);
    $catalog = ModCatalog::fromExport($export, $root.'/mods', ['3557584850']);
    $catalog->persist();

    return new GeyserReader($export, $catalog);
}

/** @return array<string, array<string, mixed>> */
function readFixtureGeysers(): array
{
    return collect(fixtureGeyserReader()->records())->keyBy('geyser_id')->all();
}

it('reads the steam geyser', function (): void {
    $steam = readFixtureGeysers()['steam'];

    expect($steam['element_id'])->toBe('Steam')
        ->and($steam['type'])->toBe('geyser')
        ->and($steam['temperature'])->toBe(383.15)
        ->and($steam['min_yield_rate'])->toBe(1000.0)
        ->and($steam['max_yield_rate'])->toBe(2000.0)
        ->and($steam['max_pressure'])->toBe(5.0)
        ->and($steam['min_eruption_duration'])->toBe(0.1)
        ->and($steam['max_eruption_duration'])->toBe(0.9)
        ->and($steam['min_eruption_period'])->toBe(60.0)
        ->and($steam['max_eruption_period'])->toBe(1140.0)
        ->and($steam['dormancy_min_cycles'])->toBe(15000.0)
        ->and($steam['dormancy_max_cycles'])->toBe(135000.0)
        ->and($steam['kanim'])->toBe('geyser_gas_steam_kanim')
        ->and($steam['name_localization_id'])->toBe('STRINGS.CREATURES.SPECIES.GEYSER.STEAM.NAME')
        ->and($steam['dlc_id'])->toBeNull()
        ->and($steam['mod_id'])->toBeNull();
});

it('classifies volcanoes and reads the dlc', function (): void {
    $volcano = readFixtureGeysers()['small_volcano'];

    expect($volcano['type'])->toBe('volcano')
        ->and($volcano['element_id'])->toBe('Magma')
        ->and($volcano['dlc_id'])->toBe('EXPANSION1_ID');
});

it('attributes a geyser to the supported mod that declares its name', function (): void {
    $ammonia = readFixtureGeysers()['AmmoniaGeyser'];

    expect($ammonia['mod_id'])->toBe(Mod::query()->where('workshop_id', '3557584850')->value('id'))
        ->and($ammonia['mod_id'])->not->toBeNull();
});

it('imports geyser records', function (): void {
    foreach (['Steam', 'Magma', 'AIO_ChromiteOre_Solid'] as $elementId) {
        Element::query()->create([
            'element_id' => $elementId, 'state' => 'gas', 'molar_mass' => 1.0,
            'toxicity' => 0, 'material_category' => 'Gas', 'tags' => [],
            'name' => ['en' => $elementId], 'is_disabled' => false,
        ]);
    }

    (new GeyserImporter(new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'))))
        ->importRecords(fixtureGeyserReader()->records());

    expect(GeyserType::query()->count())->toBe(3)
        ->and(GeyserType::query()->where('geyser_id', 'AmmoniaGeyser')->value('mod_id'))->not->toBeNull();
});
