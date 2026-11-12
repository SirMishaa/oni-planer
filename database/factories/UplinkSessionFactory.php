<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UplinkSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UplinkSession> */
final class UplinkSessionFactory extends Factory
{
    protected $model = UplinkSession::class;

    public function definition(): array
    {
        return [
            'colony_guid' => $this->faker->uuid(),
            'session_id' => $this->faker->unique()->uuid(),
            'loaded_game_seconds' => 35_400.0,
            'cycle' => 59,
            'started_at' => now(),
        ];
    }
}
