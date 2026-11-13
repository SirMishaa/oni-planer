<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UplinkCommand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UplinkCommand> */
final class UplinkCommandFactory extends Factory
{
    protected $model = UplinkCommand::class;

    public function definition(): array
    {
        return [
            'colony_guid' => $this->faker->uuid(),
            'type' => 'zone.resync',
            'payload' => ['zoneId' => $this->faker->uuid()],
            'status' => UplinkCommand::PENDING,
            'issued_at' => now(),
            'expires_at' => now()->addSeconds(60),
        ];
    }
}
