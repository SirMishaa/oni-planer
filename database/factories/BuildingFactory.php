<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Building> */
final class BuildingFactory extends Factory
{
    protected $model = Building::class;

    public function definition(): array
    {
        return [
            'building_id' => $this->faker->unique()->word(),
            'category' => $this->faker->randomElement(['Power', 'Other']),
            'power_consumption' => null,
            'power_generation' => null,
            'heat_generation' => 0,
            'width' => $this->faker->numberBetween(1, 4),
            'height' => $this->faker->numberBetween(1, 4),
            'construction_time' => $this->faker->randomFloat(1, 10, 120),
            'tags' => [],
            'name' => ['en' => $this->faker->words(2, true)],
            'description' => null,
            'dlc_id' => null,
        ];
    }

    public function power(): static
    {
        return $this->state([
            'category' => 'Power',
            'power_generation' => $this->faker->numberBetween(100, 2000),
        ]);
    }
}
