<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

/**
 * Turns `database/recipe.json` into recipe records for
 * {@see \App\Services\GameImport\RecipeImporter::importRecords()}.
 *
 * A recipe belongs to the mod of its fabricator, else to the first mod owning
 * one of its ingredients or results, else to the base game. Recipes whose
 * fabricator belongs to an unsupported mod are skipped, as are ingredients and
 * results from unsupported mods. Placeholder ingredients without a material
 * are ignored. When two recipes share an id the first is kept.
 *
 * Whether the fabricator or the referenced elements exist in the database is
 * decided by the importer, the reader cannot see it.
 *
 * @phpstan-type RecipeItemRecord array{element_id: string, amount: float}
 * @phpstan-type RecipeRecord array{
 *     recipe_id: string,
 *     building_id: string,
 *     duration: float,
 *     fabricators: list<string>,
 *     name_localization_id: null,
 *     mod_id: int|null,
 *     inputs: list<RecipeItemRecord>,
 *     outputs: list<RecipeItemRecord>,
 * }
 */
final class RecipeReader
{
    private int $skippedReferences = 0;

    private int $skippedRecipes = 0;

    private int $duplicateRecipes = 0;

    public function __construct(private readonly OniExport $export, private readonly ModCatalog $catalog) {}

    /** @return list<RecipeRecord> */
    public function records(): array
    {
        $this->skippedReferences = 0;
        $this->skippedRecipes = 0;
        $this->duplicateRecipes = 0;

        $records = [];
        $seen = [];

        foreach ($this->export->json('recipe.json')['recipes'] ?? [] as $recipe) {
            $recipeId = (string) $recipe['id'];

            if (isset($seen[$recipeId])) {
                $this->duplicateRecipes++;

                continue;
            }

            $seen[$recipeId] = true;

            $fabricators = array_map(fn (array $fabricator): string => (string) $fabricator['Name'], $recipe['fabricators'] ?? []);

            if ($fabricators === []) {
                $this->skippedRecipes++;

                continue;
            }

            if ($this->catalog->isSkipped($fabricators[0])) {
                $this->skippedRecipes++;

                continue;
            }

            $inputs = $this->items($recipe['ingredients'] ?? []);
            $outputs = $this->items($recipe['results'] ?? []);

            $records[] = [
                'recipe_id' => $recipeId,
                'building_id' => $fabricators[0],
                'duration' => (float) $recipe['time'],
                'fabricators' => $fabricators,
                'name_localization_id' => null,
                'mod_id' => $this->modId($fabricators[0], [...$inputs, ...$outputs]),
                'inputs' => $inputs,
                'outputs' => $outputs,
            ];
        }

        return $records;
    }

    /** Number of ingredients and results dropped because they belong to an unsupported mod. */
    public function skippedReferences(): int
    {
        return $this->skippedReferences;
    }

    /** Number of recipes skipped because their fabricator belongs to an unsupported mod. */
    public function skippedRecipes(): int
    {
        return $this->skippedRecipes;
    }

    /** Number of recipes ignored because an earlier recipe has the same id. */
    public function duplicateRecipes(): int
    {
        return $this->duplicateRecipes;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<RecipeItemRecord>
     */
    private function items(array $entries): array
    {
        $items = [];

        foreach ($entries as $entry) {
            $name = $entry['material']['Name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            if ($this->catalog->isSkipped($name)) {
                $this->skippedReferences++;

                continue;
            }

            $items[] = ['element_id' => $name, 'amount' => (float) $entry['amount']];
        }

        return $items;
    }

    /** @param  list<RecipeItemRecord>  $items */
    private function modId(string $fabricatorId, array $items): ?int
    {
        $modId = $this->catalog->modIdFor($fabricatorId);

        foreach ($items as $item) {
            $modId ??= $this->catalog->modIdFor($item['element_id']);
        }

        return $modId;
    }
}
