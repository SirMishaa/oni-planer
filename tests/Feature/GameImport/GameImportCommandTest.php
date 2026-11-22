<?php

declare(strict_types=1);

use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\LookupTool;
use App\Models\Building;
use App\Models\Element;
use App\Models\Food;
use App\Models\Mod;
use App\Models\PrefabName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Laravel\Octane\FrankenPhp\ServerProcessInspector;

uses(RefreshDatabase::class);

const FIXTURE_MOD_ID = '3557584850';

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/oni-import-'.uniqid();
    $this->langPath = $this->workDir.'/lang';
    $this->gamePath = $this->workDir.'/game';

    File::ensureDirectoryExists($this->gamePath.'/StreamingAssets/strings');
    File::copy(
        base_path('tests/Fixtures/GameImport/strings.pot'),
        $this->gamePath.'/StreamingAssets/strings/strings_template.pot',
    );

    File::copyDirectory(base_path('tests/Fixtures/GameImport/codex'), $this->gamePath.'/StreamingAssets/codex');
    File::copyDirectory(base_path('tests/Fixtures/OniExport'), $this->workDir.'/export');

    config(['game.supported_mods' => [FIXTURE_MOD_ID], 'octane.server' => 'frankenphp']);

    // Never reload a local Octane server from the test suite.
    $this->mock(ServerProcessInspector::class, function ($mock): void {
        $mock->shouldReceive('serverIsRunning')->andReturnFalse();
    });

    $this->importOptions = fn (array $overrides = []): array => [
        '--game-path' => $this->gamePath,
        '--export-path' => $this->workDir.'/export',
        '--mods-path' => base_path('tests/Fixtures/OniExport/mods'),
        '--translations-path' => base_path('tests/Fixtures/GameImport/translations'),
        '--lang-path' => $this->langPath,
        ...$overrides,
    ];
});

afterEach(function (): void {
    File::deleteDirectory($this->workDir);
});

it('verifies the Codex pilot against a local OniExtract export', function (): void {
    $export = getenv('ONI_TEST_EXPORT_PATH') ?: base_path('game-data/oniextract/current');
    $game = base_path('game-data/OxygenNotIncluded_Data');
    $mods = base_path('game-data/mods');

    config(['game.supported_mods' => ['*']]);
    $this->artisan('game:import', ($this->importOptions)([
        '--game-path' => $game,
        '--export-path' => $export,
        '--mods-path' => $mods,
    ]))->assertSuccessful();

    OniServer::tool(LookupTool::class, ['query' => 'PacuBeta', 'kind' => 'critter', 'details' => true])
        ->assertOk()->assertSee(['PacuAlgae', 'PacuPlate', 'source mod:', 'SaltWater 0.2 kg/s → Water 0.2 kg/s'])
        ->assertDontSee('DirtyWater 0.2 kg/s');
    OniServer::tool(LookupTool::class, ['query' => 'PacuCleaner', 'kind' => 'critter', 'details' => true])
        ->assertOk()->assertSee('DirtyWater 0.2 kg/s → Water 0.2 kg/s')->assertDontSee('SaltWater 0.2 kg/s');
    OniServer::tool(LookupTool::class, ['query' => 'Pacu', 'kind' => 'critter'])
        ->assertOk()->assertSee('PlanktonCoralSeed 1 units/cycle → ToxicSand 3 kg/cycle');
    OniServer::tool(LookupTool::class, ['query' => 'PlanktonCoral', 'kind' => 'plant', 'details' => true])
        ->assertOk()->assertSee(['growth 4 cycles', 'pressure ignored', '25.0 °C..45.0 °C', 'Water, SaltWater', 'Coquina 20 kg/cycle', 'Phosphorite 80 kg/harvest', 'consumed by ParrotFish (plant)']);
    OniServer::tool(LookupTool::class, ['query' => 'ParrotFish', 'kind' => 'critter', 'details' => true])
        ->assertOk()->assertSee(['PlanktonCoral 12.5 % growth/cycle → Lime 5 kg/cycle', 'consumed by PrehistoricPacu (prey)']);
    OniServer::tool(LookupTool::class, ['query' => 'PrehistoricPacu', 'kind' => 'critter'])
        ->assertOk()->assertSee('ParrotFish 1 prey/cycle → Rust 60 kg/cycle');
    if (getenv('ONI_TEST_BUTCHER_DROPS') === '1') {
        OniServer::tool(LookupTool::class, ['query' => 'ParrotFish', 'kind' => 'critter', 'details' => true])
            ->assertOk()->assertSee('butchering: FishMeat 1 kg, 1000 kcal per event');
        OniServer::tool(LookupTool::class, ['query' => 'PacuBeta', 'kind' => 'critter', 'details' => true])
            ->assertOk()->assertSee('butchering: FishMeat 1 kg, 1000 kcal per event')
            ->assertDontSee('PrehistoricPacuFillet');
        OniServer::tool(LookupTool::class, ['query' => 'PrehistoricPacu', 'kind' => 'critter', 'details' => true])
            ->assertOk()->assertSee('butchering: PrehistoricPacuFillet 12 kg, 12000 kcal per event');
        $beakonArticle = $this->getJson(route('codex.show', 'ParrotFishSpecies'))->assertOk();
        $beakonFacts = collect($beakonArticle->json('content'))->firstWhere('section_title', 'Beakon (ParrotFish)');
        expect(collect($beakonFacts['blocks'] ?? [])->pluck('text')->all())->toContain(
            'Produits de découpe',
            'Fish Fillet : 1 kg, 1 000 kcal par découpe',
            'Starnacle 12,5 % de croissance/cycle → Lime 5 kg/cycle',
        );
    }
    OniServer::tool(LookupTool::class, ['query' => 'FarmStation', 'kind' => 'building', 'details' => true])
        ->assertOk()->assertSee(['size 2x3', 'Metal 400 kg', 'effect:', 'exported skill perk: CanFarmTinker']);
    $this->getJson(route('codex.show', 'FarmStation'))->assertOk()
        ->assertJsonPath('category', 'Buildings')
        ->assertJsonPath('links.0.key', 'FarmStation');
    if (getenv('ONI_TEST_BUILDING_CODEX') === '1') {
        OniServer::tool(LookupTool::class, ['query' => 'FarmStation', 'kind' => 'building', 'details' => true])
            ->assertOk()->assertSee('staffed operation: Fertilizer 5 kg/use → FarmStationTools 1 unit/use · skill CanFarmTinker');
    }
})->skip(fn (): bool => getenv('ONI_TEST_REAL_EXPORT') !== '1', 'Requires the local game data and mod export.');

it('imports structured building Codex descriptors from the export sidecar', function (): void {
    $path = $this->workDir.'/export/database/planner_extras.json';
    $extras = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    $extras['buildings']['Electrolyzer'] += [
        'room' => ['id' => 'FixtureRoom', 'mode' => 'Recommended'],
        'tinker' => ['inputId' => 'Water', 'inputKg' => 5, 'outputId' => 'Oxygen', 'skillPerk' => 'FixtureSkill'],
        'manualConversions' => [[
            'label' => 'Fixture conversion',
            'input' => ['id' => 'Water', 'amount' => 1, 'customFormatting' => true],
            'output' => ['id' => 'Oxygen', 'amount' => 1, 'customFormatting' => false],
        ]],
    ];
    File::put($path, json_encode($extras, JSON_THROW_ON_ERROR));

    $this->artisan('game:import', ($this->importOptions)())->assertSuccessful();

    OniServer::tool(LookupTool::class, ['query' => 'Electrolyzer', 'kind' => 'building', 'details' => true])
        ->assertOk()->assertSee([
            'room descriptor: FixtureRoom (Recommended)',
            'staffed operation: Water 5 kg/use → Oxygen 1 unit/use · skill FixtureSkill',
            'manual Codex conversion: Water → Oxygen (Fixture conversion)',
        ]);
});

it('aborts before truncating when the export is missing', function (): void {
    Building::factory()->create();
    File::ensureDirectoryExists($this->workDir.'/empty');

    $this->artisan('game:import', ($this->importOptions)(['--export-path' => $this->workDir.'/empty']))
        ->assertExitCode(1);

    expect(Building::query()->count())->toBe(1);
});

it('aborts before truncating when planner_extras.json is missing', function (): void {
    Building::factory()->create();
    File::delete($this->workDir.'/export/database/planner_extras.json');

    $this->artisan('game:import', ($this->importOptions)())
        ->assertExitCode(1);

    expect(Building::query()->count())->toBe(1);
});

it('aborts before truncating when a supported mod folder is missing', function (): void {
    Building::factory()->create();
    File::ensureDirectoryExists($this->workDir.'/mods');

    $this->artisan('game:import', ($this->importOptions)(['--mods-path' => $this->workDir.'/mods']))
        ->assertExitCode(1);

    expect(Building::query()->count())->toBe(1);
});

it('aborts before truncating when the codex folder is missing', function (): void {
    Building::factory()->create();
    File::deleteDirectory($this->gamePath.'/StreamingAssets/codex');

    $this->artisan('game:import', ($this->importOptions)())
        ->expectsOutputToContain('codex')
        ->assertExitCode(1);

    expect(Building::query()->count())->toBe(1);
});

it('warns on build version mismatch', function (): void {
    $this->artisan('game:import', ($this->importOptions)(['--game-build' => 'U1-000000-OTHER']))
        ->expectsOutputToContain('U59-744825-SCRPAN')
        ->assertSuccessful();
});

it('imports the fixture export end to end', function (): void {
    Element::factory()->create(['element_id' => 'OldElement']);

    $this->artisan('game:import', ($this->importOptions)())
        ->expectsOutputToContain('Stairs')
        ->assertSuccessful();

    $modId = Mod::query()->where('workshop_id', FIXTURE_MOD_ID)->value('id');

    expect(Building::query()->where('building_id', 'Mining_MineralDrill')->value('mod_id'))->toBe($modId)
        ->and(Building::query()->where('building_id', 'Electrolyzer')->exists())->toBeTrue()
        ->and(Building::query()->where('building_id', 'Stairs')->exists())->toBeFalse()
        ->and(Element::query()->where('element_id', 'PhosphorBronze')->exists())->toBeTrue()
        ->and(Element::query()->where('element_id', 'OldElement')->exists())->toBeFalse()
        ->and(PrefabName::query()->where('prefab_id', 'Oxygen')->exists())->toBeTrue()
        ->and($this->langPath.'/en.json')->toBeFile()
        ->and($this->langPath.'/fr.json')->toBeFile();
});

it('leaves the previous data and catalogs intact when the import fails midway', function (): void {
    Building::factory()->create(['building_id' => 'Survivor']);
    File::ensureDirectoryExists($this->langPath);
    File::put($this->langPath.'/en.json', '{"OLD": "catalog"}');
    File::put($this->workDir.'/export/database/recipe.json', '{broken');

    expect(fn () => $this->artisan('game:import', ($this->importOptions)())->run())
        ->toThrow(JsonException::class);

    expect(Building::query()->where('building_id', 'Survivor')->exists())->toBeTrue()
        ->and(File::get($this->langPath.'/en.json'))->toBe('{"OLD": "catalog"}')
        ->and(File::files($this->langPath))->toHaveCount(1)
        ->and(File::glob($this->workDir.'/*'))->not->toContain($this->langPath.'.staging');
});

it('imports the foods with their calories and reports their count', function (): void {
    $this->artisan('game:import', ($this->importOptions)())
        ->expectsOutputToContain('  Foods: 4')
        ->assertSuccessful();

    expect(Food::query()->where('food_id', 'Lettuce')->value('calories_per_unit'))->toBe(400000.0);
});

it('reports the recipes and geysers it skips', function (): void {
    $this->artisan('game:import', ($this->importOptions)())
        ->expectsOutputToContain('  recipes: 9')
        ->expectsOutputToContain('  geysers: 2')
        ->assertSuccessful();
});

it('forgets the cached building index after the import', function (): void {
    Cache::forever(Building::indexCacheKey('en'), [['building_id' => 'Stale']]);
    Cache::forever(Building::indexCacheKey('fr'), [['building_id' => 'Stale']]);

    $this->artisan('game:import', ($this->importOptions)())->assertSuccessful();

    expect(Cache::has(Building::indexCacheKey('en')))->toBeFalse()
        ->and(Cache::has(Building::indexCacheKey('fr')))->toBeFalse();
});

it('reloads the octane workers after the import when octane is running', function (): void {
    $this->mock(ServerProcessInspector::class, function ($mock): void {
        $mock->shouldReceive('serverIsRunning')->andReturnTrue();
        $mock->shouldReceive('reloadServer')->once();
    });

    $this->artisan('game:import', ($this->importOptions)())->assertSuccessful();
});

it('tells how to reload octane when the reload fails, without failing the import', function (): void {
    $this->mock(ServerProcessInspector::class, function ($mock): void {
        $mock->shouldReceive('serverIsRunning')->andReturnTrue();
        $mock->shouldReceive('reloadServer')->andThrow(new RuntimeException('admin API down'));
    });

    $this->artisan('game:import', ($this->importOptions)())
        ->expectsOutputToContain('php artisan octane:reload')
        ->assertSuccessful();
});

it('skips the octane reload when octane is not running', function (): void {
    $this->mock(ServerProcessInspector::class, function ($mock): void {
        $mock->shouldReceive('serverIsRunning')->andReturnFalse();
        $mock->shouldNotReceive('reloadServer');
    });

    $this->artisan('game:import', ($this->importOptions)())
        ->expectsOutputToContain('Octane is not running')
        ->assertSuccessful();
});

it('imports the buildings of every mod with the wildcard, even without their mod folder', function (): void {
    config(['game.supported_mods' => ['*']]);

    $this->artisan('game:import', ($this->importOptions)())->assertSuccessful();

    expect(Building::query()->where('building_id', 'Stairs')->value('mod_id'))
        ->toBe(Mod::query()->where('workshop_id', '2012810337')->value('id'))
        ->not->toBeNull();
});
