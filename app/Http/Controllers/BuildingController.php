<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\BuildingData;
use App\Data\BuildingMaterialData;
use App\Data\BuildingShowData;
use App\Models\Building;
use App\Services\GameText;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

final class BuildingController
{
    public function index(GameText $text): Response
    {
        /** @var list<array<string, mixed>> $buildings */
        $buildings = Cache::rememberForever(Building::indexCacheKey(app()->getLocale()), fn (): array => $this->buildArray($text));

        return Inertia::render('Buildings/Index', [
            'buildings' => $buildings,
        ]);
    }

    public function show(Building $building, GameText $text): JsonResponse
    {
        $building->load('constructionMaterials');

        $materials = $building->constructionMaterials
            ->map(fn ($m): BuildingMaterialData => BuildingMaterialData::from([
                'element_id' => $m->element_id ?? null,
                'material_category' => $m->material_category ?? null,
                'amount' => $m->amount,
            ]))
            ->values()
            ->all();

        $data = BuildingShowData::from([
            'building_id' => $building->building_id,
            'name' => $this->displayName($text, $building),
            'category' => $building->category,
            'sub_group' => $this->subcategoryLabel($text, $building->subcategory),
            'width' => $building->width,
            'height' => $building->height,
            'power_consumption' => $building->power_consumption,
            'power_generation' => $building->power_generation,
            'heat_generation' => $building->heat_generation,
            'construction_time' => $building->construction_time,
            'description' => $text->description($building->building_id) ?? ($building->getTranslation('description', 'en') ?: null),
            'effect' => $text->effect($building->building_id) ?? ($building->getTranslation('effect', 'en') ?: null),
            'dlc_id' => $building->dlc_id ?: null,
            'materials' => $materials,
        ]);

        return response()->json($data);
    }

    private function displayName(GameText $text, Building $building): string
    {
        $stored = $this->splitCamelCase((string) $building->getTranslation('name', 'en'));

        return $text->name($building->building_id, $stored !== '' ? $stored : null);
    }

    /** Build menu subcategory title (e.g. `generators` → "Generators"), the raw key when untranslated. */
    private function subcategoryLabel(GameText $text, ?string $subcategory): ?string
    {
        if ($subcategory === null) {
            return null;
        }

        return $text->translate('STRINGS.UI.NEWBUILDCATEGORIES.'.mb_strtoupper($subcategory).'.BUILDMENUTITLE') ?? $subcategory;
    }

    private function splitCamelCase(string $name): string
    {
        if (str_contains($name, ' ')) {
            return $name;
        }

        return mb_trim((string) preg_replace(
            ['/([A-Z]+)([A-Z][a-z])/', '/([a-z])([A-Z])/'],
            '$1 $2',
            $name,
        ));
    }

    /** @return list<array<string, mixed>> */
    private function buildArray(GameText $text): array
    {
        $categoryOrder = ['Base', 'Oxygen', 'Power', 'Food', 'Plumbing', 'HVAC', 'Refining', 'Medical', 'Furniture', 'Equipment', 'Utilities', 'Automation', 'Conveyance', 'Rocketry', 'HEP', 'Other'];
        $caseExpr = 'CASE category '.implode(' ', array_map(
            fn (string $cat, int $i): string => sprintf("WHEN '%s' THEN %s", $cat, $i),
            $categoryOrder,
            array_keys($categoryOrder),
        )).' ELSE 99 END';

        $buildings = Building::query()
            ->orderByRaw($caseExpr) // @phpstan-ignore argument.type (CASE expression built from a hardcoded category whitelist, no user input)
            ->orderBy('building_id')
            ->get(['building_id', 'name', 'category', 'subcategory', 'width', 'height', 'power_consumption', 'power_generation', 'dlc_id']);

        /** @var list<array<string, mixed>> $result */
        $result = $buildings->map(fn (Building $b): BuildingData => BuildingData::from([
            'building_id' => $b->building_id,
            'name' => $this->displayName($text, $b),
            'category' => $b->category,
            'sub_group' => $this->subcategoryLabel($text, $b->subcategory),
            'width' => $b->width,
            'height' => $b->height,
            'power_consumption' => $b->power_consumption,
            'power_generation' => $b->power_generation,
            'dlc_id' => $b->dlc_id,
        ]))->values()->jsonSerialize();

        return $result;
    }
}
