<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;

final readonly class CritterImporter
{
    public function __construct(private StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $critters */
        $critters = json_decode((string) file_get_contents($jsonPath), true);

        $this->importRecords($critters);
    }

    /**
     * Persists critter records (reader record shape, optionally with `mod_id`).
     * A baby is stored as a morph of its adult's critter, which takes the
     * adult's mod.
     *
     * @param  array<int, array<string, mixed>>  $critters
     */
    public function importRecords(array $critters): void
    {
        foreach ($critters as $raw) {
            $parentId = $raw['parent_critter_id'] ?? $raw['critter_id'];

            $dlcId = is_string($raw['dlc_id'] ?? null) ? $raw['dlc_id'] : null;
            $modId = $raw['mod_id'] ?? null;

            $critter = Critter::query()->firstOrCreate(
                ['critter_id' => $parentId],
                ['name' => ['en' => $parentId], 'dlc_id' => $dlcId, 'mod_id' => $modId],
            );

            if ($dlcId !== null && $critter->dlc_id !== $dlcId) {
                $critter->update(['dlc_id' => $dlcId]);
            }

            if (is_string($raw['variant_family'] ?? null) && $critter->variant_family !== $raw['variant_family']) {
                $critter->update(['variant_family' => $raw['variant_family']]);
            }

            if (is_string($raw['species_tag'] ?? null) && $critter->species_tag !== $raw['species_tag']) {
                $critter->update(['species_tag' => $raw['species_tag']]);
            }

            $nameJson = is_string($raw['name_localization_id'] ?? null)
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['critter_id']])
                : ['en' => $raw['critter_id']];

            $morph = CritterMorph::query()->create([
                'morph_id' => $raw['critter_id'],
                'critter_id' => $critter->id,
                'is_base' => $raw['is_base'] ?? false,
                'min_temp' => $raw['min_temp'],
                'max_temp' => $raw['max_temp'],
                'calories_per_cycle' => $raw['calories_per_cycle'],
                'incubation_time' => $raw['incubation_time'],
                'lifespan' => $raw['lifespan'],
                'overcrowding_threshold' => $raw['overcrowding_threshold'] ?? 0,
                'name' => $nameJson,
                'mod_id' => $modId,
            ]);

            if (($raw['is_base'] ?? false) === true) {
                $descKey = is_string($raw['name_localization_id'] ?? null) ? $raw['name_localization_id'] : null;
                $description = $this->resolveSibling($descKey, 'DESC');
                if ($description !== null) {
                    $critter->update(['description' => $description]);
                }
            }

            /** @var list<array<string, mixed>> $diets */
            $diets = is_array($raw['diets'] ?? null) ? $raw['diets'] : [];
            foreach ($diets as $diet) {
                CritterMorphDiet::query()->create([
                    'critter_morph_id' => $morph->id,
                    'consumed_element_id' => $diet['consumed_element_id'],
                    'amount_per_cycle' => $diet['amount_per_cycle'],
                    'produced_element_id' => $diet['produced_element_id'] ?? null,
                    'conversion_ratio' => $diet['conversion_ratio'] ?? null,
                ]);
            }

            /** @var list<array<string, mixed>> $outputs */
            $outputs = is_array($raw['outputs'] ?? null) ? $raw['outputs'] : [];
            foreach ($outputs as $output) {
                CritterMorphOutput::query()->create([
                    'critter_morph_id' => $morph->id,
                    'element_id' => $output['element_id'],
                    'amount_per_cycle' => $output['amount_per_cycle'],
                    'output_type' => $output['output_type'],
                ]);
            }
        }
    }

    /** @return array<string, string>|null */
    private function resolveSibling(?string $nameKey, string $suffix): ?array
    {
        if ($nameKey === null || ! str_ends_with($nameKey, '.NAME')) {
            return null;
        }

        $key = mb_substr($nameKey, 0, -mb_strlen('.NAME')).'.'.$suffix;

        return $this->stringResolver->resolveToJson($key);
    }
}
