<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Food;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Food> */
final class FoodFactory extends Factory
{
    protected $model = Food::class;

    public function definition(): array
    {
        return [
            'food_id' => $this->faker->unique()->word(),
            'calories_per_unit' => $this->faker->randomElement([400000.0, 800000.0, 1600000.0]),
            'quality' => $this->faker->numberBetween(-1, 6),
            'can_rot' => true,
            'dlc_id' => null,
        ];
    }
}
