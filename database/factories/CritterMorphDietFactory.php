<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\Element;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CritterMorphDiet> */
final class CritterMorphDietFactory extends Factory
{
    protected $model = CritterMorphDiet::class;

    public function definition(): array
    {
        return [
            'critter_morph_id' => CritterMorph::factory(),
            'consumed_element_id' => fn () => Element::factory()->create()->element_id,
            'amount_per_cycle' => 140,
            'produced_element_id' => fn () => Element::factory()->create()->element_id,
            'conversion_ratio' => 0.5,
        ];
    }
}
