<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Critter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Critter> */
final class CritterFactory extends Factory
{
    protected $model = Critter::class;

    public function definition(): array
    {
        $id = $this->faker->unique()->word();

        return [
            'critter_id' => $id,
            'variant_family' => $id,
            'name' => ['en' => $id],
            'description' => ['en' => $this->faker->sentence()],
            'dlc_id' => null,
        ];
    }
}
