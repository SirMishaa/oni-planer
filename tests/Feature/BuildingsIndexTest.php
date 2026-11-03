<?php

declare(strict_types=1);

use App\Models\Building;
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
