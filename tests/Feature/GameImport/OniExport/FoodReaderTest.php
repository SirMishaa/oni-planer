<?php

declare(strict_types=1);

use App\Models\Food;
use App\Services\GameImport\FoodImporter;
use App\Services\GameImport\OniExport\FoodReader;
use App\Services\GameImport\OniExport\OniExport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reads foods with calories, quality and dlc', function (): void {
    $foods = collect(new FoodReader(new OniExport(base_path('tests/Fixtures/OniExport')))->records())->keyBy('food_id');

    expect($foods)->not->toHaveKey('Zero')
        ->and($foods['Lettuce'])->toMatchArray(['calories_per_unit' => 400000.0, 'quality' => 0, 'can_rot' => true, 'dlc_id' => null])
        ->and($foods['SwampFruit']['dlc_id'])->toBe('EXPANSION1_ID');
});

it('imports food records', function (): void {
    new FoodImporter()->importRecords(new FoodReader(new OniExport(base_path('tests/Fixtures/OniExport')))->records());

    $fishMeat = Food::query()->where('food_id', 'FishMeat')->firstOrFail();

    expect(Food::query()->count())->toBe(4)
        ->and($fishMeat->calories_per_unit)->toBe(1000000.0)
        ->and($fishMeat->quality)->toBe(2)
        ->and($fishMeat->can_rot)->toBeTrue()
        ->and(Food::query()->where('food_id', 'SwampFruit')->value('dlc_id'))->toBe('EXPANSION1_ID');
});
