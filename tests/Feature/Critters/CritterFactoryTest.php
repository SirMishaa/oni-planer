<?php

declare(strict_types=1);

use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a critter with morphs, diets and outputs via factories', function (): void {
    $critter = Critter::factory()
        ->has(CritterMorph::factory()->state(['is_base' => true])->has(CritterMorphDiet::factory()->count(2), 'diets')->has(CritterMorphOutput::factory()->egg(), 'outputs'), 'morphs')
        ->create(['critter_id' => 'Hatch', 'variant_family' => 'Hatch']);

    expect($critter->variant_family)->toBe('Hatch');
    $morph = $critter->morphs->first();
    expect($morph->diets)->toHaveCount(2)
        ->and($morph->outputs->first()->output_type)->toBe('egg');
});
