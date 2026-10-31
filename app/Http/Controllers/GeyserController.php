<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GeyserType;
use Inertia\Inertia;

final class GeyserController
{
    public function index()
    {
        $geysers = GeyserType::query()->orderBy('element_id', 'asc')->get();

        return Inertia::render('Geysers/Index', [
            'geysers' => $geysers,
        ]);
    }
}
