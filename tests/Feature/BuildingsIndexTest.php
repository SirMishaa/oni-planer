<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Mod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

it('renders the buildings index page', function (): void {
    Building::factory()->count(3)->create();

    $this->get(route('buildings.index'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('Buildings/Index')
                ->has('buildings', 3)
        );
});

it('returns buildings ordered by game category order then building_id', function (): void {
    Building::factory()->create(['building_id' => 'Zebra', 'category' => 'Power']);
    Building::factory()->create(['building_id' => 'Alpha', 'category' => 'Power']);
    Building::factory()->create(['building_id' => 'Bravo', 'category' => 'Other']);

    // Power (index 2) sorts before Other (index 15) in the game's tab order
    $this->get(route('buildings.index'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('Buildings/Index')
                ->where('buildings.0.building_id', 'Alpha')
                ->where('buildings.1.building_id', 'Zebra')
                ->where('buildings.2.building_id', 'Bravo')
        );
});

it('preserves category and alphabetical order when served from cache', function (): void {
    Building::factory()->create(['building_id' => 'Zebra', 'category' => 'Power']);
    Building::factory()->create(['building_id' => 'Alpha', 'category' => 'Power']);
    Building::factory()->create(['building_id' => 'Bravo', 'category' => 'Other']);

    // First request warms the cache
    $this->get(route('buildings.index'))->assertOk();

    // Second request reads from cache — order must be identical
    $this->get(route('buildings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Buildings/Index')
            ->where('buildings.0.building_id', 'Alpha')
            ->where('buildings.1.building_id', 'Zebra')
            ->where('buildings.2.building_id', 'Bravo')
        );
});

it('labels sub_groups with the translated build menu subcategory title', function (): void {
    $catalogDir = sys_get_temp_dir().'/oni-buildings-index-'.uniqid();
    File::ensureDirectoryExists($catalogDir);
    File::put($catalogDir.'/en.json', (string) json_encode([
        'STRINGS.UI.NEWBUILDCATEGORIES.TESTGENERATORS.BUILDMENUTITLE' => 'Test Generators',
    ]));
    app('translator')->addJsonPath($catalogDir);

    Building::factory()->create(['building_id' => 'A', 'category' => 'Power', 'subcategory' => 'testgenerators']);
    Building::factory()->create(['building_id' => 'B', 'category' => 'Power', 'subcategory' => 'untranslatedsubcategory']);
    Building::factory()->create(['building_id' => 'C', 'category' => 'Power', 'subcategory' => null]);

    $this->get(route('buildings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Buildings/Index')
            ->where('buildings.0.sub_group', 'Test Generators')
            ->where('buildings.1.sub_group', 'untranslatedsubcategory')
            ->where('buildings.2.sub_group', null)
        );

    File::deleteDirectory($catalogDir);
});

it('exposes the mod on modded buildings', function (): void {
    $mod = Mod::factory()->create(['workshop_id' => '2000001', 'title' => 'Ronivans Legacy - Industrial Revolution']);
    Building::factory()->create(['building_id' => 'Modded', 'category' => 'Power', 'mod_id' => $mod->id]);
    Building::factory()->create(['building_id' => 'Vanilla', 'category' => 'Power']);

    $this->get(route('buildings.index'))
        ->assertInertia(
            fn ($page) => $page
                ->where('buildings.0.building_id', 'Modded')
                ->where('buildings.0.mod.title', 'Ronivans Legacy - Industrial Revolution')
                ->where('buildings.0.mod.workshop_id', '2000001')
                ->where('buildings.1.mod', null)
        );
});

it('ignores an index cached by code with another payload shape', function (): void {
    Building::factory()->create(['building_id' => 'Fresh']);
    Cache::forever('buildings.index.'.app()->getLocale(), [['building_id' => 'Stale']]);

    $this->get(route('buildings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('buildings.0.building_id', 'Fresh'));
});

it('keys the index cache by payload shape and locale', function (): void {
    expect(Building::indexCacheKey('fr'))->toMatch('/^buildings\.index\.[0-9a-f]{12}\.fr$/');
});
