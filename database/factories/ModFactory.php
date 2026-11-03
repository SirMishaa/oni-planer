<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Mod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Mod> */
final class ModFactory extends Factory
{
    protected $model = Mod::class;

    public function definition(): array
    {
        return [
            'workshop_id' => (string) $this->faker->unique()->numberBetween(1_000_000_000, 3_999_999_999),
            'static_id' => $this->faker->unique()->word(),
            'title' => $this->faker->words(3, true),
        ];
    }
}
