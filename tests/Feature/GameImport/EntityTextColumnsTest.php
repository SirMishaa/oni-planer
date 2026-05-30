<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Critter;
use App\Models\Plant;

it('stores effect and description translatable maps on a building', function (): void {
    $building = Building::factory()->create([
        'description' => ['en' => 'Electrolyzes water.'],
        'effect' => ['en' => 'Converts water into oxygen and hydrogen.'],
    ]);

    $fresh = $building->fresh();

    expect($fresh->getTranslation('description', 'en'))->toBe('Electrolyzes water.')
        ->and($fresh->getTranslation('effect', 'en'))->toBe('Converts water into oxygen and hydrogen.');
});

it('stores a description map on a critter and a plant', function (): void {
    $critter = Critter::query()->create(['critter_id' => 'Hatch', 'name' => ['en' => 'Hatch'], 'description' => ['en' => 'A digger.']]);
    $plant = Plant::query()->create(['plant_id' => 'Mealwood', 'name' => ['en' => 'Mealwood'], 'description' => ['en' => 'A crop.']]);

    expect($critter->fresh()->getTranslation('description', 'en'))->toBe('A digger.')
        ->and($plant->fresh()->getTranslation('description', 'en'))->toBe('A crop.');
});
