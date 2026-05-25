<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Element;
use Inertia\Inertia;
use Inertia\Response;

final class ElementController extends Controller
{
    public function index(): Response
    {
        $elements = Element::query()
            ->orderBy('state')
            ->orderBy('element_id')
            ->get(['element_id', 'name', 'state', 'material_category', 'is_disabled']);

        return Inertia::render('Elements/Index', [
            'elements' => $elements,
        ]);
    }
}
