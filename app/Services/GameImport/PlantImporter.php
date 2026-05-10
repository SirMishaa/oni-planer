<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Plant;
use App\Models\PlantVariant;
use App\Models\PlantVariantInput;
use App\Models\PlantVariantOutput;

final readonly class PlantImporter
{
    public function __construct(private StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $plants */
        $plants = json_decode((string) file_get_contents($jsonPath), true);

        foreach ($plants as $raw) {
            $plant = Plant::query()->firstOrCreate(['plant_id' => $raw['plant_id']], ['name' => ['en' => $raw['plant_id']], 'dlc_id' => $raw['dlc_id'] ?? null]);

            $nameJson = is_string($raw['name_localization_id'] ?? null)
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['variant_id']])
                : ['en' => $raw['variant_id']];

            $variant = PlantVariant::query()->create([
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

            /** @var list<array<string, mixed>> $inputs */
            $inputs = is_array($raw['inputs'] ?? null) ? $raw['inputs'] : [];
            foreach ($inputs as $input) {
                PlantVariantInput::query()->create([
                    'plant_variant_id' => $variant->id,
                    'element_id' => $input['element_id'],
                    'amount_per_cycle' => $input['amount_per_cycle'],
                    'input_type' => $input['input_type'],
                ]);
            }

            /** @var list<array<string, mixed>> $plantOutputs */
            $plantOutputs = is_array($raw['outputs'] ?? null) ? $raw['outputs'] : [];
            foreach ($plantOutputs as $output) {
                PlantVariantOutput::query()->create([
                    'plant_variant_id' => $variant->id,
                    'element_id' => $output['element_id'],
                    'amount_per_harvest' => $output['amount_per_harvest'],
                    'output_type' => $output['output_type'],
                ]);
            }
        }
    }
}
