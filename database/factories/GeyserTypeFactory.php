<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GeyserType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GeyserType> */
final class GeyserTypeFactory extends Factory
{
    protected $model = GeyserType::class;

    public function definition(): array
    {
        return [
            'geyser_id' => $this->faker->unique()->slug(),
            'type' => $this->faker->randomElement(['geyser', 'vent', 'volcano', 'fissure']),
            'element_id' => 'Water',
            'temperature' => $this->faker->randomFloat(2, 300, 3000),
            'max_pressure' => 500,
            'min_yield_rate' => 1,
            'max_yield_rate' => 4,
            'min_eruption_duration' => 60,
            'max_eruption_duration' => 1140,
            'min_eruption_period' => 167,
            'max_eruption_period' => 833,
            'dormancy_min_cycles' => 25,
            'dormancy_max_cycles' => 75,
            'name' => ['en' => $this->faker->words(2, true)],
        ];
    }
}
