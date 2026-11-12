<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Zone> */
final class ZoneFactory extends Factory
{
    /** Width of the grid the sample cells were taken from. */
    public const int GRID_WIDTH = 606;

    /** First cell of the sample room. */
    public const int ORIGIN_CELL = 138_888;

    protected $model = Zone::class;

    /**
     * The cells of a 12 by 4 room starting at {@see self::ORIGIN_CELL}, sorted.
     *
     * @return list<int>
     */
    public static function sampleCells(): array
    {
        $cells = [];

        for ($row = 0; $row < 4; $row++) {
            for ($column = 0; $column < 12; $column++) {
                $cells[] = self::ORIGIN_CELL + $row * self::GRID_WIDTH + $column;
            }
        }

        return $cells;
    }

    public function definition(): array
    {
        return [
            'colony_guid' => $this->faker->uuid(),
            'zone_guid' => $this->faker->unique()->uuid(),
            'name' => 'Ferme sodicane',
            'world_id' => 0,
            'version' => 1,
            'cell_count' => count(self::sampleCells()),
            'orphaned' => false,
            'archived_at' => null,
        ];
    }
}
