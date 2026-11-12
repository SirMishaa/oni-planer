<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Zone;
use App\Models\ZoneVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ZoneVersion> */
final class ZoneVersionFactory extends Factory
{
    protected $model = ZoneVersion::class;

    public function definition(): array
    {
        return [
            'zone_id' => Zone::factory(),
            'version' => 1,
            'cells' => ZoneFactory::sampleCells(),
            'valid_from_game_seconds' => 35_400.0,
        ];
    }
}
