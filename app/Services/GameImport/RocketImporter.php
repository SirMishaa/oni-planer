<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Element;
use App\Models\RocketEngine;
use App\Models\RocketModule;

final class RocketImporter
{
    private int $skippedReferences = 0;

    public function __construct(private readonly StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array{engines: list<array<string, mixed>>, modules: list<array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($jsonPath), true);

        $this->importRecords($data);
    }

    /**
     * Persists rocket records (json-cache shape). A fuel that is not an
     * imported element is stored as null and counted.
     *
     * @param  array{engines: list<array<string, mixed>>, modules: list<array<string, mixed>>}  $data
     */
    public function importRecords(array $data): void
    {
        $this->skippedReferences = 0;

        $validElementIds = Element::query()->pluck('element_id', 'element_id')->all();

        foreach ($data['engines'] as $raw) {
            $nameJson = is_string($raw['name_localization_id'] ?? null)
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['engine_id']])
                : ['en' => $raw['engine_id']];

            $fuelElementId = $raw['fuel_element_id'] ?? null;

            if ($fuelElementId !== null && ! isset($validElementIds[$fuelElementId])) {
                $this->skippedReferences++;
                $fuelElementId = null;
            }

            RocketEngine::query()->create([
                'engine_id' => $raw['engine_id'],
                'fuel_element_id' => $fuelElementId,
                'oxidizer_element_id' => $raw['oxidizer_element_id'] ?? null,
                'max_range' => $raw['max_range'],
                'fuel_consumption_rate' => $raw['fuel_consumption_rate'],
                'oxidizer_consumption_rate' => $raw['oxidizer_consumption_rate'] ?? null,
                'exhaust_temperature' => $raw['exhaust_temperature'],
                'name' => $nameJson,
                'dlc_id' => ($raw['dlc_id'] ?? '') ?: null,
            ]);
        }

        foreach ($data['modules'] as $raw) {
            $nameJson = is_string($raw['name_localization_id'] ?? null)
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['module_id']])
                : ['en' => $raw['module_id']];

            RocketModule::query()->create([
                'module_id' => $raw['module_id'],
                'module_type' => $raw['module_type'],
                'mass' => $raw['mass'],
                'capacity' => $raw['capacity'],
                'power_consumption' => $raw['power_consumption'] ?? null,
                'name' => $nameJson,
                'dlc_id' => ($raw['dlc_id'] ?? '') ?: null,
            ]);
        }
    }

    /** Number of fuels stored as null because they are not imported elements. */
    public function skippedReferences(): int
    {
        return $this->skippedReferences;
    }
}
