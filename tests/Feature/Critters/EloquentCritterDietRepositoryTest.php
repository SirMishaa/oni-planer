<?php

declare(strict_types=1);

use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use App\Services\Critters\EloquentCritterDietRepository;
use Illuminate\Support\Facades\DB;

it('reads the diet and output rows of a morph by its morph id', function (): void {
    $morph = CritterMorph::factory()->create(['morph_id' => 'Pacu']);
    CritterMorphDiet::factory()->create(['critter_morph_id' => $morph->id,
        'consumed_element_id' => 'Algae', 'amount_per_cycle' => 7.5,
        'produced_element_id' => 'ToxicSand', 'conversion_ratio' => 0.5, 'food_kind' => 'item',
    ]);
    CritterMorphDiet::factory()->create(['critter_morph_id' => $morph->id,
        'consumed_element_id' => 'Kelp', 'amount_per_cycle' => 20,
        'produced_element_id' => null, 'conversion_ratio' => null, 'food_kind' => 'plant',
    ]);
    CritterMorphOutput::factory()->create(['critter_morph_id' => $morph->id, 'element_id' => 'PacuEgg', 'amount_per_cycle' => 0.25, 'output_type' => 'egg']);
    CritterMorphDiet::factory()->create(['consumed_element_id' => 'Sand']);

    $repository = new EloquentCritterDietRepository;

    expect($repository->diets('Pacu'))->toBe([
        ['resource' => 'Algae', 'kgPerCycle' => 7.5, 'produces' => 'ToxicSand', 'conversionRatio' => 0.5, 'kind' => 'item'],
        ['resource' => 'Kelp', 'kgPerCycle' => 20.0, 'produces' => null, 'conversionRatio' => null, 'kind' => 'plant'],
    ])
        ->and($repository->outputs('Pacu'))->toBe([
            ['resource' => 'PacuEgg', 'perCycle' => 0.25, 'kind' => 'egg'],
        ])
        ->and($repository->diets('Unknown'))->toBe([])
        ->and($repository->outputs('Unknown'))->toBe([]);
});

it('reads a morph once per instance', function (): void {
    $morph = CritterMorph::factory()->create(['morph_id' => 'Pacu']);
    CritterMorphDiet::factory()->create(['critter_morph_id' => $morph->id, 'consumed_element_id' => 'Algae']);
    $repository = new EloquentCritterDietRepository;
    $repository->diets('Pacu');

    DB::enableQueryLog();
    $repository->diets('Pacu');
    $repository->outputs('Pacu');

    expect(DB::getQueryLog())->toBe([]);
});
