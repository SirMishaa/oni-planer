<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UplinkMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UplinkMessage> */
final class UplinkMessageFactory extends Factory
{
    protected $model = UplinkMessage::class;

    public function definition(): array
    {
        return [
            'event' => 'client-colony.state',
            'colony_guid' => $this->faker->uuid(),
            'colony_name' => $this->faker->word(),
            'cycle' => $this->faker->numberBetween(1, 500),
            'size' => 2,
            'outcome' => 'stored',
            'reason' => null,
            'section_errors' => [],
            'payload' => '{}',
            'received_at' => now(),
        ];
    }
}
