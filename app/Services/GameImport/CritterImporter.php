<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;

final class CritterImporter
{
    public function __construct(private readonly StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $critters */
        $critters = json_decode(file_get_contents($jsonPath), true);

        foreach ($critters as $raw) {
            $parentId = $raw['parent_critter_id'] ?? $raw['critter_id'];

            $critter = Critter::firstOrCreate(
                ['critter_id' => $parentId],
                ['name' => ['en' => $parentId]],
            );

            $nameJson = isset($raw['name_localization_id'])
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['critter_id']])
                : ['en' => $raw['critter_id']];

            $morph = CritterMorph::create([
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
            ]);

            foreach ($raw['diets'] ?? [] as $diet) {
                CritterMorphDiet::create([
                    'critter_morph_id' => $morph->id,
                    'consumed_element_id' => $diet['consumed_element_id'],
                    'amount_per_cycle' => $diet['amount_per_cycle'],
                    'produced_element_id' => $diet['produced_element_id'] ?? null,
                    'conversion_ratio' => $diet['conversion_ratio'] ?? null,
                ]);
            }

            foreach ($raw['outputs'] ?? [] as $output) {
                CritterMorphOutput::create([
                    'critter_morph_id' => $morph->id,
                    'element_id' => $output['element_id'],
                    'amount_per_cycle' => $output['amount_per_cycle'],
                    'output_type' => $output['output_type'],
                ]);
            }
        }
    }
}
