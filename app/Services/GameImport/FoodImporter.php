<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Food;

final class FoodImporter
{
    /**
     * Persists food records (reader record shape).
     *
     * @param  list<array{food_id: string, calories_per_unit: float, quality: int, can_rot: bool, dlc_id: string|null}>  $records
     */
    public function importRecords(array $records): void
    {
        foreach ($records as $record) {
            Food::query()->create($record);
        }
    }
}
