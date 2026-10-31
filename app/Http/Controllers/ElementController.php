<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ElementData;
use App\Data\ElementShowData;
use App\Data\ThermalPropertyData;
use App\Models\Element;
use App\Services\GameText;
use Inertia\Inertia;
use Inertia\Response;

final class ElementController
{
    public function show(Element $element, GameText $text): Response
    {
        $element->load('thermalProperties');

        $thermal = $element->thermalProperties
            ? ThermalPropertyData::from($element->thermalProperties)
            : null;

        return Inertia::render('Elements/Show', [
            'element' => ElementShowData::from([
                'element_id' => $element->element_id,
                'name' => $text->name($element->element_id, $element->getTranslation('name', 'en') ?: null),
                'description' => $text->description($element->element_id) ?? ($element->getTranslation('description', 'en') ?: null),
                'state' => $element->state,
                'material_category' => $element->material_category,
                'molar_mass' => $element->molar_mass,
                'toxicity' => $element->toxicity,
                'tags' => $element->tags ?? [],
                'low_temp_transition_target' => $element->low_temp_transition_target,
                'high_temp_transition_target' => $element->high_temp_transition_target,
                'thermal' => $thermal,
            ]),
        ]);
    }

    public function index(GameText $text): Response
    {
        $elements = Element::query()
            ->where('is_disabled', false)
            ->orderBy('state')
            ->orderBy('element_id')
            ->get(['element_id', 'name', 'state', 'material_category'])
            ->map(fn (Element $el): ElementData => ElementData::from([
                'element_id' => $el->element_id,
                'name' => $text->name($el->element_id, $el->getTranslation('name', 'en') ?: null),
                'state' => $el->state,
                'material_category' => $el->material_category,
            ]));

        return Inertia::render('Elements/Index', [
            'elements' => $elements,
        ]);
    }
}
