<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Building;

final class BuildingImporter
{
    public function __construct(private readonly StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $buildings */
        $buildings = json_decode(file_get_contents($jsonPath), true);

        foreach ($buildings as $raw) {
            $nameJson = isset($raw['name_localization_id'])
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['building_id']])
                : ['en' => $raw['building_id']];

            Building::create([
                'building_id' => $raw['building_id'],
                'category' => $raw['category'],
                'power_consumption' => $raw['power_consumption'] ?? null,
                'power_generation' => $raw['power_generation'] ?? null,
                'heat_generation' => $raw['heat_generation'] ?? 0,
                'width' => $raw['width'] ?? 1,
                'height' => $raw['height'] ?? 1,
                'construction_time' => $raw['construction_time'] ?? 0,
                'tags' => $raw['tags'] ?? [],
                'name' => $nameJson,
                'dlc_id' => ($raw['dlc_id'] ?? '') ?: null,
            ]);
        }
    }
}
