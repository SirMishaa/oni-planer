<?php

declare(strict_types=1);

use App\Models\Building;

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

it('groups buildings with the same terminal word into the same sub_group', function (): void {
    Building::factory()->create(['building_id' => 'A', 'category' => 'Base', 'name' => ['en' => 'Wooden Door']]);
    Building::factory()->create(['building_id' => 'B', 'category' => 'Base', 'name' => ['en' => 'Insulated Door']]);
    Building::factory()->create(['building_id' => 'C', 'category' => 'Base', 'name' => ['en' => 'Manual Airlock']]);

    $this->get(route('buildings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Buildings/Index')
            ->where('buildings.0.sub_group', 'Door')
            ->where('buildings.1.sub_group', 'Door')
            ->where('buildings.2.sub_group', null) // unique terminal word → ungrouped
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

it('does not create a first-word group from buildings already assigned to a last-word group', function (): void {
    // GasReservoir and LiquidReservoir share last word "Reservoir" → Reservoir group
    // GasMembrane has first word "Gas" like GasReservoir, but GasReservoir is already assigned
    // so "Gas" first-word frequency among unassigned = 1 → GasMembrane stays ungrouped
    Building::factory()->create(['building_id' => 'A', 'category' => 'Base', 'name' => ['en' => 'GasReservoir']]);
    Building::factory()->create(['building_id' => 'B', 'category' => 'Base', 'name' => ['en' => 'LiquidReservoir']]);
    Building::factory()->create(['building_id' => 'C', 'category' => 'Base', 'name' => ['en' => 'GasMembrane']]);

    $this->get(route('buildings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Buildings/Index')
            ->where('buildings.0.sub_group', 'Reservoir') // A
            ->where('buildings.1.sub_group', 'Reservoir') // B
            ->where('buildings.2.sub_group', null)         // C — not falsely grouped as "Gas"
        );
});

it('leaves buildings ungrouped when their terminal word is unique in the category', function (): void {
    Building::factory()->create(['building_id' => 'A', 'category' => 'Power', 'name' => ['en' => 'Coal Generator']]);
    Building::factory()->create(['building_id' => 'B', 'category' => 'Power', 'name' => ['en' => 'Natural Gas Generator']]);
    Building::factory()->create(['building_id' => 'C', 'category' => 'Power', 'name' => ['en' => 'Smart Battery']]);

    $this->get(route('buildings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Buildings/Index')
            ->where('buildings.0.sub_group', 'Generator')
            ->where('buildings.1.sub_group', 'Generator')
            ->where('buildings.2.sub_group', null)
        );
});
