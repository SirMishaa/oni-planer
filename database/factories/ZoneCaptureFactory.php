<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Zone;
use App\Models\ZoneCapture;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ZoneCapture> */
final class ZoneCaptureFactory extends Factory
{
    protected $model = ZoneCapture::class;

    public function definition(): array
    {
        return [
            'zone_id' => Zone::factory(),
            'path' => 'zone-captures/zone-1/'.fake()->unique()->numerify('20261114-######-###').'.png',
            'taken_at' => now(),
            'game_seconds' => 35_400.0,
            'layout_signature' => 'signature',
            'trigger' => 'auto',
            'command_id' => null,
            'width' => 704,
            'height' => 128,
        ];
    }
}
