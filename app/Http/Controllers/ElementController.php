<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ElementData;
use App\Models\Element;
use Inertia\Inertia;
use Inertia\Response;

final class ElementController
{
    public function index(): Response
    {
        $elements = Element::query()
            ->where('is_disabled', false)
            ->orderBy('state')
            ->orderBy('element_id')
            ->get(['element_id', 'name', 'state', 'material_category'])
            ->map(fn (Element $el) => ElementData::from([
                'element_id' => $el->element_id,
                'name' => $el->getTranslations('name'),
                'state' => $el->state,
                'material_category' => $el->material_category,
            ]));

        return Inertia::render('Elements/Index', [
            'elements' => $elements,
        ]);
    }
}
