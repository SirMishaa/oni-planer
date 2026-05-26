<?php

declare(strict_types=1);

use App\Models\Building;

it('renders the buildings index page', function () {
    Building::factory()->count(3)->create();

    $this->get(route('buildings.index'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('Buildings/Index')
                ->has('buildings', 3)
        );
});

it('returns buildings ordered by category then building_id', function () {
    Building::factory()->create(['building_id' => 'Zebra', 'category' => 'Power']);
    Building::factory()->create(['building_id' => 'Alpha', 'category' => 'Power']);
    Building::factory()->create(['building_id' => 'Bravo', 'category' => 'Other']);

    $this->get(route('buildings.index'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('Buildings/Index')
                ->where('buildings.0.building_id', 'Bravo')
                ->where('buildings.1.building_id', 'Alpha')
                ->where('buildings.2.building_id', 'Zebra')
        );
});
