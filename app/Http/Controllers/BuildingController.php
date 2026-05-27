<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\BuildingData;
use App\Models\Building;
use App\Services\BuildingSubGroupResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

final class BuildingController
{
    public const string CACHE_KEY = 'buildings.index';

    public function index(): Response
    {
        /** @var list<array<string, mixed>> $buildings */
        $buildings = Cache::rememberForever(self::CACHE_KEY, fn () => $this->buildArray());

        return Inertia::render('Buildings/Index', [
            'buildings' => $buildings,
        ]);
    }

    private static function splitCamelCase(string $name): string
    {
        if (mb_strpos($name, ' ') !== false) {
            return $name;
        }

        return mb_trim((string) preg_replace(
            ['/([A-Z]+)([A-Z][a-z])/', '/([a-z])([A-Z])/'],
            '$1 $2',
            $name,
        ));
    }

    /** @return list<array<string, mixed>> */
    private function buildArray(): array
    {
        $categoryOrder = ['Base', 'Oxygen', 'Power', 'Food', 'Plumbing', 'HVAC', 'Refining', 'Medical', 'Furniture', 'Equipment', 'Utilities', 'Automation', 'Conveyance', 'Rocketry', 'HEP', 'Other'];
        $caseExpr = 'CASE category '.implode(' ', array_map(
            fn (string $cat, int $i): string => "WHEN '{$cat}' THEN {$i}",
            $categoryOrder,
            array_keys($categoryOrder),
        )).' ELSE 99 END';

        $buildings = Building::query()
            ->orderByRaw($caseExpr)
            ->orderBy('building_id')
            ->get(['building_id', 'name', 'category', 'width', 'height', 'power_consumption', 'power_generation', 'dlc_id']);

        $resolver = new BuildingSubGroupResolver;
        $subGroups = $buildings
            ->groupBy('category')
            ->flatMap(fn (Collection $group) => $resolver->resolve($group));

        return $buildings->map(fn (Building $b): BuildingData => BuildingData::from([
            'building_id' => $b->building_id,
            'name' => array_map(self::splitCamelCase(...), $b->getTranslations('name')),
            'category' => $b->category,
            'sub_group' => $subGroups[$b->building_id] ?? null,
            'width' => $b->width,
            'height' => $b->height,
            'power_consumption' => $b->power_consumption,
            'power_generation' => $b->power_generation,
            'dlc_id' => $b->dlc_id,
        ]))->values()->jsonSerialize();
    }
}
