<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Element;
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

    // The fixture slice has no Steam element, which the fixture geyser references.
    File::copyDirectory(base_path('tests/Fixtures/OniExport'), $this->workDir.'/export');
    File::put($this->workDir.'/export/database/geyser.json', '{"geysers": []}');

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

it('leaves the previous data intact when the import fails midway', function (): void {
    Building::factory()->create(['building_id' => 'Survivor']);
    File::put($this->workDir.'/export/database/recipe.json', '{broken');

    expect(fn () => $this->artisan('game:import', ($this->importOptions)())->run())
        ->toThrow(JsonException::class);

    expect(Building::query()->where('building_id', 'Survivor')->exists())->toBeTrue();
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
