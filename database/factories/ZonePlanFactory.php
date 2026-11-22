<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Zone;
use App\Models\ZonePlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ZonePlan> */
final class ZonePlanFactory extends Factory
{
    protected $model = ZonePlan::class;

    public function definition(): array
    {
        return [
            'zone_id' => Zone::factory(),
        ];
    }
}
