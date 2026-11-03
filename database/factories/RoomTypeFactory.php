<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RoomType> */
final class RoomTypeFactory extends Factory
{
    protected $model = RoomType::class;

    public function definition(): array
    {
        $roomTypeId = $this->faker->unique()->word();

        return [
            'room_type_id' => $roomTypeId,
            'category' => 'Bathroom',
            'name_key' => 'STRINGS.ROOMS.TYPES.'.mb_strtoupper($roomTypeId).'.NAME',
            'requirements' => [],
            'size_min' => null,
            'size_max' => null,
            'bonus_effect' => null,
            'priority' => 1,
        ];
    }
}
