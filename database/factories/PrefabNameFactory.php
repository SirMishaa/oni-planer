<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PrefabName;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrefabName> */
final class PrefabNameFactory extends Factory
{
    protected $model = PrefabName::class;

    public function definition(): array
    {
        $segment = mb_strtoupper($this->faker->unique()->word());

        return [
            'prefab_id' => ucfirst(mb_strtolower($segment)),
            'name_key' => sprintf('STRINGS.ITEMS.FOOD.%s.NAME', $segment),
        ];
    }
}
