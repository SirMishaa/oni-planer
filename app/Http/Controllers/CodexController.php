<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\CodexArticleData;
use App\Data\CodexListItemData;
use App\Models\CodexEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class CodexController
{
    public function index(): JsonResponse
    {
        $entries = CodexEntry::query()
            ->orderBy('category')
            ->orderBy('codex_id')
            ->get(['id', 'codex_id', 'category', 'parent_codex_id', 'title'])
            ->map(fn (CodexEntry $entry): CodexListItemData => CodexListItemData::from([
                'codex_id' => $entry->codex_id,
                'category' => $entry->category,
                'parent_codex_id' => $entry->parent_codex_id,
                'title' => $entry->getTranslations('title'),
            ]));

        return response()->json($entries);
    }

    public function show(CodexEntry $codexEntry): JsonResponse
    {
        $codexEntry->load([
            'buildings:id,building_id,name',
            'elements:id,element_id,name',
            'critters:id,critter_id,name',
            'plants:id,plant_id,name',
            'geyserTypes:id,geyser_id,name',
        ]);

        return response()->json(CodexArticleData::from([
            'codex_id' => $codexEntry->codex_id,
            'category' => $codexEntry->category,
            'parent_codex_id' => $codexEntry->parent_codex_id,
            'title' => $codexEntry->getTranslations('title'),
            'icon_asset' => $codexEntry->icon_asset,
            'content' => $codexEntry->content ?? [],
            'image' => $this->imageFor($codexEntry),
            'links' => $this->links($codexEntry),
        ]));
    }

    /**
     * Build deep-link descriptors for every entity attached to the entry.
     * Only buildings and elements have detail pages today; the others render
     * as non-navigable chips (url null) until their pages ship.
     *
     * @return list<array<string, mixed>>
     */
    private function links(CodexEntry $entry): array
    {
        $links = [];

        foreach ($entry->buildings as $building) {
            $links[] = [
                'type' => 'building',
                'key' => $building->building_id,
                'label' => $this->enLabel($building->getTranslations('name'), $building->building_id),
                'url' => route('buildings.show', $building->building_id),
            ];
        }

        foreach ($entry->elements as $element) {
            $links[] = [
                'type' => 'element',
                'key' => $element->element_id,
                'label' => $this->enLabel($element->getTranslations('name'), $element->element_id),
                'url' => route('elements.show', Str::kebab($element->element_id)),
            ];
        }

        foreach ($entry->critters as $critter) {
            $links[] = [
                'type' => 'critter',
                'key' => $critter->critter_id,
                'label' => $this->enLabel($critter->getTranslations('name'), $critter->critter_id),
                'url' => null,
            ];
        }

        foreach ($entry->plants as $plant) {
            $links[] = [
                'type' => 'plant',
                'key' => $plant->plant_id,
                'label' => $this->enLabel($plant->getTranslations('name'), $plant->plant_id),
                'url' => null,
            ];
        }

        foreach ($entry->geyserTypes as $geyser) {
            $links[] = [
                'type' => 'geyser',
                'key' => $geyser->geyser_id,
                'label' => $this->enLabel($geyser->getTranslations('name'), $geyser->geyser_id),
                'url' => null,
            ];
        }

        return $links;
    }

    /**
     * @param  array<array-key, mixed>  $names
     */
    private function enLabel(array $names, mixed $fallback): string
    {
        $en = $names['en'] ?? null;
        if (is_string($en)) {
            return $en;
        }

        return is_string($fallback) ? $fallback : '';
    }

    /**
     * First linked entity that has an extracted asset on disk, in display priority order.
     */
    private function imageFor(CodexEntry $entry): ?string
    {
        foreach ($entry->buildings as $building) {
            $url = $this->assetUrl('buildings', $building->building_id);
            if ($url !== null) {
                return $url;
            }
        }
        foreach ($entry->elements as $element) {
            $url = $this->assetUrl('elements', $element->element_id);
            if ($url !== null) {
                return $url;
            }
        }
        foreach ($entry->critters as $critter) {
            $url = $this->assetUrl('critters', $critter->critter_id);
            if ($url !== null) {
                return $url;
            }
        }
        foreach ($entry->plants as $plant) {
            $url = $this->assetUrl('plants', $plant->plant_id);
            if ($url !== null) {
                return $url;
            }
        }
        foreach ($entry->geyserTypes as $geyser) {
            $url = $this->assetUrl('geysers', $geyser->geyser_id);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function assetUrl(string $category, string $key): ?string
    {
        $relative = "assets/game/{$category}/{$key}.webp";

        return file_exists(public_path($relative)) ? "/{$relative}" : null;
    }
}
