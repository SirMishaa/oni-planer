<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Building;

final readonly class BuildingImporter
{
    public function __construct(private StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var list<array<string, mixed>> $buildings */
        $buildings = json_decode((string) file_get_contents($jsonPath), true);

        $this->importRecords($buildings);
    }

    /**
     * Persists building records (reader record shape, with the editor
     * columns and `materials` produced by the OniExtract building reader).
     *
     * @param  list<array<string, mixed>>  $records
     */
    public function importRecords(array $records): void
    {
        foreach ($records as $raw) {
            $nameKey = is_string($raw['name_localization_id'] ?? null) ? $raw['name_localization_id'] : null;
            $nameJson = $nameKey !== null
                ? ($this->stringResolver->resolveToJson($nameKey) ?? ['en' => $raw['building_id']])
                : ['en' => $raw['building_id']];
            $descriptionJson = $this->resolveSibling($nameKey, 'DESC');
            $effectJson = $this->resolveSibling($nameKey, 'EFFECT');

            $building = Building::query()->create([
                'building_id' => $raw['building_id'],
                'category' => $raw['category'],
                'subcategory' => $raw['subcategory'] ?? null,
                'power_consumption' => $raw['power_consumption'] ?? null,
                'power_generation' => $raw['power_generation'] ?? null,
                'heat_generation' => $raw['heat_generation'] ?? 0,
                'width' => $raw['width'] ?? 1,
                'height' => $raw['height'] ?? 1,
                'construction_time' => $raw['construction_time'] ?? 0,
                'tags' => $raw['tags'] ?? [],
                'name' => $nameJson,
                'description' => $descriptionJson,
                'effect' => $effectJson,
                'dlc_id' => ($raw['dlc_id'] ?? '') ?: null,
                'ports' => $raw['ports'] ?? null,
                'build_location_rule' => $raw['build_location_rule'] ?? null,
                'permitted_rotations' => $raw['permitted_rotations'] ?? null,
                'object_layer' => $raw['object_layer'] ?? null,
                'scene_layer' => $raw['scene_layer'] ?? null,
                'overlay' => $raw['overlay'] ?? null,
                'is_foundation' => $raw['is_foundation'] ?? false,
                'is_tile' => $raw['is_tile'] ?? false,
                'is_utility' => $raw['is_utility'] ?? false,
                'drag_build' => $raw['drag_build'] ?? false,
                'replaceable' => $raw['replaceable'] ?? false,
                'placement_rect' => $raw['placement_rect'] ?? null,
                'mod_id' => $raw['mod_id'] ?? null,
            ]);

            /** @var list<array{material_category: string, amount: float}> $materials */
            $materials = $raw['materials'] ?? [];
            $building->constructionMaterials()->createMany($materials);
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
