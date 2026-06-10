<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CritterMorph;
use App\Models\CritterMorphOutput;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CritterMorphOutput> */
final class CritterMorphOutputFactory extends Factory
{
    protected $model = CritterMorphOutput::class;

    public function definition(): array
    {
        return [
            'critter_morph_id' => CritterMorph::factory(),
            'element_id' => 'HatchEgg',
            'amount_per_cycle' => 1 / 60,
            'output_type' => 'egg',
        ];
    }

    public function egg(): static
    {
        return $this->state(['output_type' => 'egg', 'element_id' => 'HatchEgg']);
    }
}
