<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Colony;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Colony> */
final class ColonyFactory extends Factory
{
    protected $model = Colony::class;

    public function definition(): array
    {
        return [
            'guid' => $this->faker->unique()->uuid(),
            'name' => $this->faker->word(),
            'cycle' => $this->faker->numberBetween(1, 500),
            'state' => ['schema' => 1],
            'schema' => 1,
            'last_seen_at' => now(),
        ];
    }
}
