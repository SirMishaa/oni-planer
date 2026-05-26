<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\BuildingData;
use App\Models\Building;
use Inertia\Inertia;
use Inertia\Response;

final class BuildingController
{
    public function index(): Response
    {
        $buildings = Building::query()
            ->orderBy('category')
            ->orderBy('building_id')
            ->get(['building_id', 'name', 'category', 'width', 'height', 'power_consumption', 'power_generation', 'dlc_id'])
            ->map(fn (Building $b): BuildingData => BuildingData::from([
                'building_id' => $b->building_id,
                'name' => $b->getTranslations('name'),
                'category' => $b->category,
                'width' => $b->width,
                'height' => $b->height,
                'power_consumption' => $b->power_consumption,
                'power_generation' => $b->power_generation,
                'dlc_id' => $b->dlc_id,
            ]));

        return Inertia::render('Buildings/Index', [
            'buildings' => $buildings,
        ]);
    }
}
