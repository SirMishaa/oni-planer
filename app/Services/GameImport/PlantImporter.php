<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Plant;
use App\Models\PlantVariant;
use App\Models\PlantVariantInput;
use App\Models\PlantVariantOutput;

final class PlantImporter
{
    public function __construct(private readonly StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $plants */
        $plants = json_decode(file_get_contents($jsonPath), true);

        foreach ($plants as $raw) {
            $plant = Plant::firstOrCreate(
                ['plant_id' => $raw['plant_id']],
                ['name' => ['en' => $raw['plant_id']], 'dlc_id' => $raw['dlc_id'] ?? null],
            );

            $nameJson = isset($raw['name_localization_id'])
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['variant_id']])
                : ['en' => $raw['variant_id']];

            $variant = PlantVariant::create([
                'variant_id' => $raw['variant_id'],
                'plant_id' => $plant->id,
                'is_base' => $raw['is_base'] ?? false,
                'min_temp' => $raw['min_temp'],
                'max_temp' => $raw['max_temp'],
                'min_pressure' => $raw['min_pressure'],
                'max_pressure' => $raw['max_pressure'],
                'atmosphere_element_id' => $raw['atmosphere_element_id'] ?? null,
                'light_required' => $raw['light_required'] ?? false,
                'growth_time' => $raw['growth_time'],
                'name' => $nameJson,
            ]);

            foreach ($raw['inputs'] ?? [] as $input) {
                PlantVariantInput::create([
                    'plant_variant_id' => $variant->id,
                    'element_id' => $input['element_id'],
                    'amount_per_cycle' => $input['amount_per_cycle'],
                    'input_type' => $input['input_type'],
                ]);
            }

            foreach ($raw['outputs'] ?? [] as $output) {
                PlantVariantOutput::create([
                    'plant_variant_id' => $variant->id,
                    'element_id' => $output['element_id'],
                    'amount_per_harvest' => $output['amount_per_harvest'],
                    'output_type' => $output['output_type'],
                ]);
            }
        }
    }
}
