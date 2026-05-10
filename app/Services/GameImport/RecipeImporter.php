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
        $recipes = json_decode((string) file_get_contents($jsonPath), true);

        foreach ($recipes as $raw) {
            $building = Building::query()->where('building_id', $raw['building_id'])->first();
            if ($building === null) {
                continue;
            }

            $recipe = Recipe::query()->create([
                'building_id' => $building->id,
                'duration' => $raw['duration'],
                'fabricators' => $raw['fabricators'] ?? null,
                'name' => $raw['name_localization_id'] ? ['en' => $raw['name_localization_id']] : null,
            ]);

            /** @var list<array<string, mixed>> $recipeInputs */
            $recipeInputs = is_array($raw['inputs'] ?? null) ? $raw['inputs'] : [];
            foreach ($recipeInputs as $item) {
                RecipeItem::query()->create([
                    'recipe_id' => $recipe->id,
                    'element_id' => $item['element_id'],
                    'amount' => $item['amount'],
                    'role' => 'input',
                ]);
            }

            /** @var list<array<string, mixed>> $recipeOutputs */
            $recipeOutputs = is_array($raw['outputs'] ?? null) ? $raw['outputs'] : [];
            foreach ($recipeOutputs as $item) {
                RecipeItem::query()->create([
                    'recipe_id' => $recipe->id,
                    'element_id' => $item['element_id'],
                    'amount' => $item['amount'],
                    'role' => 'output',
                ]);
            }
        }
    }
}
