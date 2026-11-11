<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

/**
 * Turns `database/food.json` into food records for
 * {@see \App\Services\GameImport\FoodImporter::importRecords()}.
 *
 * Calories stay in cal, as the game stores them. The dlc is the first entry of
 * `requiredDlcIdsMap[Id]`. Foods without calories (seeds, ingredients the game
 * lists but no duplicant eats) are skipped.
 *
 * @phpstan-type FoodRecord array{
 *     food_id: string,
 *     calories_per_unit: float,
 *     quality: int,
 *     can_rot: bool,
 *     dlc_id: string|null,
 * }
 */
final readonly class FoodReader
{
    public function __construct(private OniExport $export) {}

    /** @return list<FoodRecord> */
    public function records(): array
    {
        $food = $this->export->json('food.json');
        /** @var array<string, list<string>|null> $requiredDlcIds */
        $requiredDlcIds = $food['requiredDlcIdsMap'] ?? [];

        $records = [];

        foreach ($food['foodInfoList'] ?? [] as $info) {
            $caloriesPerUnit = (float) ($info['CaloriesPerUnit'] ?? 0);

            if ($caloriesPerUnit <= 0) {
                continue;
            }

            $foodId = (string) $info['Id'];

            $records[] = [
                'food_id' => $foodId,
                'calories_per_unit' => $caloriesPerUnit,
                'quality' => (int) ($info['Quality'] ?? 0),
                'can_rot' => (bool) ($info['CanRot'] ?? false),
                'dlc_id' => $requiredDlcIds[$foodId][0] ?? null,
            ];
        }

        return $records;
    }
}
