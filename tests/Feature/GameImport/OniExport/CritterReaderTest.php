<?php

declare(strict_types=1);

use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\LookupTool;
use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\Mod;
use App\Services\GameImport\CritterImporter;
use App\Services\GameImport\OniExport\CritterReader;
use App\Services\GameImport\OniExport\ModCatalog;
use App\Services\GameImport\OniExport\OniExport;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $supported
 */
function fixtureCritterReader(array $supported = ['3557584850']): CritterReader
{
    $root = base_path('tests/Fixtures/OniExport');
    $export = new OniExport($root);
    $catalog = ModCatalog::fromExport($export, $root.'/mods', $supported);
    $catalog->persist();

    return new CritterReader($export, $catalog);
}

/**
 * @param  list<string>  $supported
 * @return array<string, array<string, mixed>>
 */
function readFixtureCritters(array $supported = ['3557584850']): array
{
    return collect(fixtureCritterReader($supported)->records())->keyBy('critter_id')->all();
}

it('reads hatch ranching data', function (): void {
    $hatch = readFixtureCritters()['Hatch'];

    expect($hatch['calories_per_cycle'])->toBe(700000.0)
        ->and($hatch['lifespan'])->toBe(100.0)
        ->and($hatch['incubation_time'])->toBe(20.0)
        ->and($hatch['min_temp'])->toBe(283.15)
        ->and($hatch['max_temp'])->toBe(313.15)
        ->and($hatch['overcrowding_threshold'])->toBe(12)
        ->and($hatch['is_base'])->toBeTrue()
        ->and($hatch['parent_critter_id'])->toBeNull()
        ->and($hatch['species_tag'])->toBe('HatchSpecies')
        ->and($hatch['variant_family'])->toBe('Hatch')
        ->and($hatch['name_localization_id'])->toBe('STRINGS.CREATURES.SPECIES.HATCH.NAME')
        ->and($hatch['dlc_id'])->toBeNull()
        ->and($hatch['mod_id'])->toBeNull()
        ->and($hatch['outputs'])->toHaveCount(1)
        ->and($hatch['outputs'][0]['element_id'])->toBe('HatchEgg')
        ->and($hatch['outputs'][0]['output_type'])->toBe('egg')
        ->and($hatch['outputs'][0]['amount_per_cycle'])->toEqualWithDelta(0.016667, 0.000001)
        ->and($hatch['diets'][0])->toBe([
            'consumed_element_id' => 'Sand',
            'amount_per_cycle' => 140.0,
            'produced_element_id' => 'Carbon',
            'conversion_ratio' => 0.5,
            'food_kind' => 'item',
        ]);
});

it('links a baby to its adult', function (): void {
    $baby = readFixtureCritters()['HatchBaby'];

    expect($baby['parent_critter_id'])->toBe('Hatch')
        ->and($baby['is_base'])->toBeFalse()
        ->and($baby['lifespan'])->toBe(100.0)
        ->and($baby['calories_per_cycle'])->toBe(700000.0)
        ->and($baby['incubation_time'])->toBe(0.0)
        ->and($baby['outputs'])->toBe([])
        ->and($baby['name_localization_id'])->toBe('STRINGS.CREATURES.SPECIES.HATCH.BABY.NAME');
});

it('reads a non-original morph as a base morph of its own critter', function (): void {
    $hatchHard = readFixtureCritters()['HatchHard'];

    expect($hatchHard['is_base'])->toBeTrue()
        ->and($hatchHard['parent_critter_id'])->toBeNull()
        ->and($hatchHard['variant_family'])->toBe('Hatch')
        ->and($hatchHard['name_localization_id'])->toBe('STRINGS.CREATURES.SPECIES.HATCH.VARIANT_HARD.NAME');
});

it('reads only creatures', function (): void {
    expect(array_keys(readFixtureCritters()))->toBe(['Hatch', 'HatchBaby', 'HatchHard']);
});

it('drops diet items from unsupported mods', function (): void {
    $reader = fixtureCritterReader();
    $hatch = collect($reader->records())->keyBy('critter_id')['Hatch'];

    expect(array_column($hatch['diets'], 'consumed_element_id'))->toBe(['Sand', 'SandStone'])
        ->and($reader->skippedReferences())->toBe(1);
});

it('imports a critter of a supported mod with its mod id', function (): void {
    $reader = fixtureCritterReader(['3557584850', '2020793956']);
    $pacuModId = Mod::query()->where('workshop_id', '2020793956')->value('id');

    new CritterImporter(new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot')))->importRecords($reader->records());

    expect(Critter::query()->where('critter_id', 'PacuAlgae')->value('mod_id'))->toBe($pacuModId)
        ->and(CritterMorph::query()->where('morph_id', 'PacuAlgae')->value('mod_id'))->toBe($pacuModId)
        ->and(CritterMorph::query()->where('morph_id', 'Hatch')->value('mod_id'))->toBeNull();
});

it('imports babies under their adult critter', function (): void {
    new CritterImporter(new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot')))->importRecords(fixtureCritterReader()->records());

    $hatch = Critter::query()->where('critter_id', 'Hatch')->sole();

    expect($hatch->morphs()->pluck('morph_id')->sort()->values()->all())->toBe(['Hatch', 'HatchBaby'])
        ->and($hatch->baseMorph->diets)->toHaveCount(2)
        ->and($hatch->baseMorph->diets->first()->food_kind)->toBe('item')
        ->and($hatch->baseMorph->outputs->first()->element_id)->toBe('HatchEgg')
        ->and(Critter::query()->count())->toBe(2);
});

it('reads how a critter eats each diet line', function (): void {
    $critters = readFixtureCritters();

    expect($critters['Hatch']['diets'][0]['food_kind'])->toBe('item')
        ->and($critters['HatchBaby']['diets'][0]['food_kind'])->toBe('item')
        ->and($critters['HatchHard']['diets'][0]['food_kind'])->toBe('plant');
});

it('imports Codex quantities and conversions without replacing ranching diet values', function (): void {
    $root = sys_get_temp_dir().'/oni-critter-codex-'.uniqid();
    File::copyDirectory(base_path('tests/Fixtures/OniExport'), $root);

    try {
        $path = $root.'/database/entities.json';
        $data = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        $data['entities'][] = ['name' => 'PlanktonCoral', 'cropVal' => ['cropDuration' => 2400], 'kPrefabID' => ['tags' => []]];
        $data['entities'][0]['primaryElement']['Mass'] = 200;
        $data['entities'][0]['critterTemperatureMonitorDef']['temperatureColdDeadly'] = 253.15;
        $data['entities'][0]['critterTemperatureMonitorDef']['temperatureHotDeadly'] = 373.15;
        $data['entities'][0]['elementConverters'] = [[
            'consumedElements' => [['Tag' => ['Name' => 'SaltWater'], 'MassConsumptionRate' => 0.2]],
            'outputElements' => [['elementHash' => 1836671383, 'massGenerationRate' => 0.2]],
        ]];
        $data['entities'][0]['creatureCalorieMonitorDef']['diet']['infos'] = array_map(
            fn (array $example): array => [
                'consumedTags' => [['Name' => $example[0]]],
                'foodType' => $example[1],
                'caloriesPerKg' => $example[2],
                'producedConversionRate' => $example[3],
                'producedElement' => ['Name' => $example[4]],
            ],
            [['PlanktonCoral', 1, 1400000, 10, 'Lime'], ['Hatch', 3, 3500, 0.3, 'Rust'], ['PlanktonCoral', 2, 1400000, 10, 'Lime']],
        );
        File::put($path, json_encode($data, JSON_THROW_ON_ERROR));
        $extrasPath = $root.'/database/planner_extras.json';
        $extras = json_decode(File::get($extrasPath), true, flags: JSON_THROW_ON_ERROR);
        $extras['entities']['Hatch']['butcherDrops'] = [
            ['prefabId' => 'FishMeat', 'prefabAmount' => 1, 'massKg' => 1, 'calories' => 1000000],
        ];
        File::put($extrasPath, json_encode($extras, JSON_THROW_ON_ERROR));
        File::put($root.'/database/elements.json', json_encode(['elementTable' => [1836671383 => ['id' => 'Water']]], JSON_THROW_ON_ERROR));

        $export = new OniExport($root);
        $catalog = ModCatalog::fromExport($export, $root.'/mods', ['3557584850']);
        $catalog->persist();
        new CritterImporter(new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot')))
            ->importRecords(new CritterReader($export, $catalog)->records());

        $diets = CritterMorph::query()->where('morph_id', 'Hatch')->sole()->diets()->orderBy('id')->get();
        expect((float) $diets[0]->amount_per_cycle)->toBe(0.5)
            ->and((float) $diets[1]->amount_per_cycle)->toBe(200.0)
            ->and($diets[2]->getAttributes()['codex_data'] ?? null)->toBeNull();

        OniServer::tool(LookupTool::class, ['kind' => 'critter', 'query' => 'Hatch', 'details' => true])
            ->assertOk()->assertSee([
                'PlanktonCoral 12.5 % growth/cycle → Lime 5 kg/cycle',
                'Hatch 1 prey/cycle → Rust 60 kg/cycle',
                'PlanktonCoral (plant; rate unavailable)',
                'survival -20.0 °C..100.0 °C',
                'SaltWater 0.2 kg/s → Water 0.2 kg/s',
                'butchering: FishMeat 1 kg, 1000 kcal per event',
            ]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('maps every export food type to a food kind', function (int $foodType, string $kind): void {
    expect(CritterReader::foodKind($foodType))->toBe($kind);
})->with([
    'solid item' => [0, 'item'],
    'plant eaten in place' => [1, 'plant'],
    'plant storage' => [2, 'plant'],
    'prey' => [3, 'prey'],
    'butchered prey' => [4, 'prey'],
]);

it('warns and falls back to item for an unknown food type', function (): void {
    Log::spy();

    expect(CritterReader::foodKind(9))->toBe('item');

    Log::shouldHaveReceived('warning')->once();
});
