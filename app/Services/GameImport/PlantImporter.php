<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Element;
use App\Models\Plant;
use App\Models\PlantVariant;
use App\Models\PlantVariantInput;
use App\Models\PlantVariantOutput;

final class PlantImporter
{
    private int $skippedReferences = 0;

    public function __construct(private readonly StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $plants */
        $plants = json_decode((string) file_get_contents($jsonPath), true);

        $this->importRecords($plants);
    }

    /**
     * Persists plant records (reader record shape, optionally with `mod_id`).
     * A null pressure means "not pressure sensitive" and is stored as 0, since
     * the columns are not nullable. Inputs and outputs that are not imported
     * elements (foods and seeds have no element row) are skipped.
     *
     * @param  array<int, array<string, mixed>>  $plants
     */
    public function importRecords(array $plants): void
    {
        $this->skippedReferences = 0;

        $validElementIds = Element::query()->pluck('element_id', 'element_id')->all();

        foreach ($plants as $raw) {
            $modId = $raw['mod_id'] ?? null;
            $plant = Plant::query()->firstOrCreate(
                ['plant_id' => $raw['plant_id']],
                ['name' => ['en' => $raw['plant_id']], 'dlc_id' => $raw['dlc_id'] ?? null, 'mod_id' => $modId],
            );

            $nameJson = is_string($raw['name_localization_id'] ?? null)
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['variant_id']])
                : ['en' => $raw['variant_id']];

            $variant = PlantVariant::query()->create([
                'variant_id' => $raw['variant_id'],
                'plant_id' => $plant->id,
                'is_base' => $raw['is_base'] ?? false,
                'min_temp' => $raw['min_temp'],
                'max_temp' => $raw['max_temp'],
                'min_pressure' => $raw['min_pressure'] ?? 0.0,
                'max_pressure' => $raw['max_pressure'] ?? 0.0,
                'atmosphere_element_id' => $raw['atmosphere_element_id'] ?? null,
                'light_required' => $raw['light_required'] ?? false,
                'growth_time' => $raw['growth_time'],
                'name' => $nameJson,
                'mod_id' => $modId,
            ]);

            if (($raw['is_base'] ?? false) === true) {
                $descKey = is_string($raw['name_localization_id'] ?? null) ? $raw['name_localization_id'] : null;
                $description = $this->resolveSibling($descKey, 'DESC');
                if ($description !== null) {
                    $plant->update(['description' => $description]);
                }
            }

            /** @var list<array<string, mixed>> $inputs */
            $inputs = is_array($raw['inputs'] ?? null) ? $raw['inputs'] : [];
            foreach ($inputs as $input) {
                if (! isset($validElementIds[$input['element_id']])) {
                    $this->skippedReferences++;

                    continue;
                }

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
                if (! isset($validElementIds[$output['element_id']])) {
                    $this->skippedReferences++;

                    continue;
                }

                PlantVariantOutput::query()->create([
                    'plant_variant_id' => $variant->id,
                    'element_id' => $output['element_id'],
                    'amount_per_harvest' => $output['amount_per_harvest'],
                    'output_type' => $output['output_type'],
                ]);
            }
        }
    }

    /** Number of inputs and outputs skipped because they are not imported elements. */
    public function skippedReferences(): int
    {
        return $this->skippedReferences;
    }

    /** @return array<string, string>|null */
    private function resolveSibling(?string $nameKey, string $suffix): ?array
    {
        $nameKey = $nameKey !== null ? StringResolver::normalizeKey($nameKey) : null;
        if ($nameKey === null || ! str_ends_with($nameKey, '.NAME')) {
            return null;
        }

        $key = mb_substr($nameKey, 0, -mb_strlen('.NAME')).'.'.$suffix;

        return $this->stringResolver->resolveToJson($key);
    }
}
