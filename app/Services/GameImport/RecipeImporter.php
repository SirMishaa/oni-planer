<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Building;
use App\Models\Recipe;
use App\Models\RecipeItem;

final class RecipeImporter
{
    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $recipes */
        $recipes = json_decode(file_get_contents($jsonPath), true);

        foreach ($recipes as $raw) {
            $building = Building::where('building_id', $raw['building_id'])->first();
            if ($building === null) {
                continue;
            }

            $recipe = Recipe::create([
                'building_id' => $building->id,
                'duration' => $raw['duration'],
                'fabricators' => $raw['fabricators'] ?? null,
                'name' => $raw['name_localization_id'] ? ['en' => $raw['name_localization_id']] : null,
            ]);

            foreach ($raw['inputs'] ?? [] as $item) {
                RecipeItem::create([
                    'recipe_id' => $recipe->id,
                    'element_id' => $item['element_id'],
                    'amount' => $item['amount'],
                    'role' => 'input',
                ]);
            }

            foreach ($raw['outputs'] ?? [] as $item) {
                RecipeItem::create([
                    'recipe_id' => $recipe->id,
                    'element_id' => $item['element_id'],
                    'amount' => $item['amount'],
                    'role' => 'output',
                ]);
            }
        }
    }
}
