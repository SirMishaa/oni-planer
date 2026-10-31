<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Building;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

final readonly class BuildingImporter
{
    public function __construct(private StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $buildings */
        $buildings = json_decode((string) file_get_contents($jsonPath), true);

        foreach ($buildings as $raw) {
            $nameKey = is_string($raw['name_localization_id'] ?? null) ? $raw['name_localization_id'] : null;
            $nameJson = $nameKey !== null
                ? ($this->stringResolver->resolveToJson($nameKey) ?? ['en' => $raw['building_id']])
                : ['en' => $raw['building_id']];
            $descriptionJson = $this->resolveSibling($nameKey, 'DESC');
            $effectJson = $this->resolveSibling($nameKey, 'EFFECT');

            Building::query()->create([
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
                'description' => $descriptionJson,
                'effect' => $effectJson,
                'dlc_id' => ($raw['dlc_id'] ?? '') ?: null,
            ]);
        }

        foreach (File::glob(lang_path('game').'/*.json') as $catalog) {
            Cache::forget(Building::indexCacheKey(pathinfo($catalog, PATHINFO_FILENAME)));
        }

        Cache::forget(Building::indexCacheKey((string) config('app.locale')));
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
