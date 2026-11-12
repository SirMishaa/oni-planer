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

    /**
     * A sodicane farm as the mod sends it: a 20 by 2 room (40 cells) at
     * (100, 201) above a row of 13 farm tiles and 7 plain tiles, 13 planted
     * sodicanes on the farm tiles (the last one grown) and a 2 by 2 farm
     * station at (114, 201) short of fertilizer. The box keeps a 1-cell
     * margin: 22 by 4 cells from (99, 200).
     */
    public function sodicaneFarm(): self
    {
        $gridWidth = self::GRID_WIDTH;
        $cell = fn (int $x, int $y): int => $y * $gridWidth + $x;
        $box = ['x' => 99, 'y' => 200, 'width' => 22, 'height' => 4];

        $cells = [];
        foreach ([201, 202] as $y) {
            foreach (range(100, 119) as $x) {
                $cells[] = $cell($x, $y);
            }
        }

        $tiles = array_map(fn (int $x): array => [
            'cell' => $cell($x, 200),
            'prefabId' => $x <= 112 ? 'FarmTile' : 'Tile',
        ], range(100, 119));

        $plants = array_map(fn (int $x): array => [
            'id' => 'SaltySticksPlant',
            'instanceId' => (string) (5000 + $x),
            'worldId' => 0,
            'cell' => $cell($x, 201),
            'cropId' => 'SaltyStick',
            'cropUnits' => 1.0,
            'cropDurationSeconds' => 6000.0,
            'planted' => true,
            'autoHarvest' => true,
            'wilting' => false,
            'grown' => $x === 112,
            'secondsUntilHarvest' => $x === 112 ? 0.0 : 1500.0,
            'growthPerSecond' => 1 / 600,
        ], range(100, 112));

        $boxCells = $box['width'] * $box['height'];

        return $this->state(fn (): array => [
            'cell_count' => count($cells),
            'layout' => [
                'schema' => 1,
                'colonyId' => 'colony-1',
                'zoneId' => 'zone-sodicane',
                'version' => 1,
                'gameSeconds' => 36000.0,
                'gridWidth' => $gridWidth,
                'box' => $box,
                'cells' => $cells,
                'tiles' => $tiles,
                'buildings' => [[
                    'instanceId' => '7001',
                    'prefabId' => 'FarmStation',
                    'anchorCell' => $cell(114, 201),
                    'width' => 2,
                    'height' => 2,
                    'orientation' => 'Neutral',
                ]],
            ],
            'slow_state' => [
                'schema' => 1,
                'colonyId' => 'colony-1',
                'zoneId' => 'zone-sodicane',
                'gameSeconds' => 36000.0,
                'box' => $box,
                'palette' => ['Oxygen', 'Dirt'],
                'element' => array_map(fn (int $index): int => intdiv($index, $box['width']) === 0 ? 1 : 0, range(0, $boxCells - 1)),
                'mass' => array_fill(0, $boxCells, 1.5),
                'temperature' => array_fill(0, $boxCells, 295.15),
            ],
            'fast_state' => [
                'schema' => 1,
                'colonyId' => 'colony-1',
                'zoneId' => 'zone-sodicane',
                'gameSeconds' => 36030.0,
                'plants' => $plants,
                'buildings' => [[
                    'instanceId' => '7001',
                    'prefabId' => 'FarmStation',
                    'operational' => true,
                    'active' => false,
                    'statusItems' => [
                        ['id' => 'MaterialsUnavailable', 'name' => 'Insufficient Resources: Fertilizer'],
                        ['id' => 'NotInRecommendedRoom', 'name' => 'Not in a farm'],
                    ],
                    'storage' => [['id' => 'Fertilizer', 'mass' => 0.0]],
                ]],
            ],
            'layout_at' => now(),
            'slow_state_at' => now(),
            'fast_state_at' => now(),
        ]);
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
