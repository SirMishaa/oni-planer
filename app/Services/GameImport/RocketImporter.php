<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\RocketEngine;
use App\Models\RocketModule;

final class RocketImporter
{
    public function __construct(private readonly StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(file_get_contents($jsonPath), true);

        foreach ($data['engines'] ?? [] as $raw) {
            $nameJson = isset($raw['name_localization_id'])
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['engine_id']])
                : ['en' => $raw['engine_id']];

            RocketEngine::create([
                'engine_id' => $raw['engine_id'],
                'fuel_element_id' => $raw['fuel_element_id'] ?? null,
                'oxidizer_element_id' => $raw['oxidizer_element_id'] ?? null,
                'max_range' => $raw['max_range'],
                'fuel_consumption_rate' => $raw['fuel_consumption_rate'],
                'oxidizer_consumption_rate' => $raw['oxidizer_consumption_rate'] ?? null,
                'exhaust_temperature' => $raw['exhaust_temperature'],
                'name' => $nameJson,
                'dlc_id' => ($raw['dlc_id'] ?? '') ?: null,
            ]);
        }

        foreach ($data['modules'] ?? [] as $raw) {
            $nameJson = isset($raw['name_localization_id'])
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['module_id']])
                : ['en' => $raw['module_id']];

            RocketModule::create([
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
}
