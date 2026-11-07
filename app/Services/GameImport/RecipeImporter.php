<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Building;
use App\Models\Element;
use App\Models\Recipe;
use App\Models\RecipeItem;

final class RecipeImporter
{
    private int $skippedRecipes = 0;

    private int $skippedReferences = 0;

    public function import(string $jsonPath): void
    {
        /** @var list<array<string, mixed>> $recipes */
        $recipes = json_decode((string) file_get_contents($jsonPath), true);

        $this->importRecords($recipes);
    }

    /**
     * Persists recipe records (reader record shape, optionally with `mod_id`).
     *
     * Recipes whose fabricator is not an imported building are skipped, and so
     * are items that are not imported elements (crafted objects such as
     * EmptyElectrobank have no element row).
     *
     * @param  list<array<string, mixed>>  $records
     */
    public function importRecords(array $records): void
    {
        $this->skippedRecipes = 0;
        $this->skippedReferences = 0;

        $validElementIds = Element::query()->pluck('element_id', 'element_id')->all();
        $buildingIds = Building::query()->pluck('id', 'building_id')->all();

        foreach ($records as $raw) {
            $buildingId = $buildingIds[$raw['building_id']] ?? null;
            if ($buildingId === null) {
                $this->skippedRecipes++;

                continue;
            }

            $recipe = Recipe::query()->create([
                'building_id' => $buildingId,
                'duration' => $raw['duration'],
                'fabricators' => $raw['fabricators'] ?? null,
                'name' => $raw['name_localization_id'] ? ['en' => $raw['name_localization_id']] : null,
                'mod_id' => $raw['mod_id'] ?? null,
            ]);

            foreach (['input' => 'inputs', 'output' => 'outputs'] as $role => $key) {
                /** @var list<array<string, mixed>> $items */
                $items = is_array($raw[$key] ?? null) ? $raw[$key] : [];

                foreach ($items as $item) {
                    $elementId = $item['element_id'] ?? null;
                    if (! is_string($elementId) || ! isset($validElementIds[$elementId])) {
                        $this->skippedReferences++;

                        continue;
                    }

                    RecipeItem::query()->create([
                        'recipe_id' => $recipe->id,
                        'element_id' => $elementId,
                        'amount' => $item['amount'],
                        'role' => $role,
                        'alternative_group' => $item['alternative_group'] ?? null,
                    ]);
                }
            }
        }
    }

    /** Number of recipes skipped because their fabricator is not an imported building. */
    public function skippedRecipes(): int
    {
        return $this->skippedRecipes;
    }

    /** Number of recipe items skipped because they are not imported elements (crafted items included). */
    public function skippedReferences(): int
    {
        return $this->skippedReferences;
    }
}
