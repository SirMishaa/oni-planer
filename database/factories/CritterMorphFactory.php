<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Critter;
use App\Models\CritterMorph;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CritterMorph> */
final class CritterMorphFactory extends Factory
{
    protected $model = CritterMorph::class;

    public function definition(): array
    {
        return [
            'critter_id' => Critter::factory(),
            'morph_id' => $this->faker->unique()->word(),
            'is_base' => true,
            'min_temp' => 283.15,
            'max_temp' => 313.15,
            'calories_per_cycle' => 700000,
            'incubation_time' => 20,
            'lifespan' => 0,
            'overcrowding_threshold' => 12,
            'name' => ['en' => $this->faker->word()],
        ];
    }

    public function baby(): static
    {
        return $this->state(['is_base' => false, 'incubation_time' => 0]);
    }
}
