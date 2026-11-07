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
 * results from unsupported mods.
 *
 * An "any of" ingredient has no `material`: the fabricator accepts any one of
 * its `possibleMaterials`, each with its own amount in
 * `possibleMaterialAmounts`. It becomes one item per option, all sharing an
 * `alternative_group` (the 1-based position of the ingredient in the recipe).
 * Plain items have a null group.
 *
 * Recipe ids are not unique: the game registers MissileLongRange twice at the
 * MissileFabricator. Both recipes are kept, a repeated id gets a `_2`, `_3`…
 * suffix (the fabricator is the same, so it cannot tell them apart).
 *
 * Whether the fabricator or the referenced elements exist in the database is
 * decided by the importer, the reader cannot see it.
 *
 * @phpstan-type RecipeItemRecord array{element_id: string, amount: float, alternative_group: int|null}
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
        $occurrences = [];

        foreach ($this->export->json('recipe.json')['recipes'] ?? [] as $recipe) {
            $recipeId = (string) $recipe['id'];
            $occurrences[$recipeId] = ($occurrences[$recipeId] ?? 0) + 1;

            if ($occurrences[$recipeId] > 1) {
                $this->duplicateRecipes++;
                $recipeId .= '_'.$occurrences[$recipeId];
            }

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

    /** Number of ingredients, ingredient options and results dropped because they belong to an unsupported mod. */
    public function skippedReferences(): int
    {
        return $this->skippedReferences;
    }

    /** Number of recipes skipped because their fabricator belongs to an unsupported mod. */
    public function skippedRecipes(): int
    {
        return $this->skippedRecipes;
    }

    /** Number of recipes whose id repeats an earlier one and got a suffix. */
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

        foreach ($entries as $position => $entry) {
            $name = $entry['material']['Name'] ?? null;

            if (is_string($name) && $name !== '') {
                $this->addItem($items, $name, (float) $entry['amount'], null);

                continue;
            }

            $amounts = $entry['possibleMaterialAmounts'] ?? [];

            foreach ($entry['possibleMaterials'] ?? [] as $index => $option) {
                $optionName = $option['Name'] ?? null;

                if (is_string($optionName) && $optionName !== '') {
                    $this->addItem($items, $optionName, (float) ($amounts[$index] ?? $entry['amount']), $position + 1);
                }
            }
        }

        return $items;
    }

    /** @param  list<RecipeItemRecord>  $items */
    private function addItem(array &$items, string $elementId, float $amount, ?int $alternativeGroup): void
    {
        if ($this->catalog->isSkipped($elementId)) {
            $this->skippedReferences++;

            return;
        }

        $items[] = ['element_id' => $elementId, 'amount' => $amount, 'alternative_group' => $alternativeGroup];
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
