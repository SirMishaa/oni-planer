<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Element;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Element> */
final class ElementFactory extends Factory
{
    protected $model = Element::class;

    public function definition(): array
    {
        return [
            'element_id' => $this->faker->unique()->word(),
            'state' => $this->faker->randomElement(['gas', 'liquid', 'solid', 'special']),
            'molar_mass' => $this->faker->randomFloat(2, 1, 200),
            'toxicity' => 0,
            'material_category' => 'Mineral',
            'tags' => [],
            'name' => ['en' => $this->faker->word()],
            'is_disabled' => false,
        ];
    }

    public function gas(): static
    {
        return $this->state(['state' => 'gas']);
    }

    public function liquid(): static
    {
        return $this->state(['state' => 'liquid']);
    }

    public function solid(): static
    {
        return $this->state(['state' => 'solid']);
    }
}
