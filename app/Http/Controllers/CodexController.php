<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\CodexArticleData;
use App\Data\CodexListItemData;
use App\Models\CodexEntry;
use App\Services\GameText;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class CodexController
{
    public function index(): JsonResponse
    {
        $entries = CodexEntry::query()
            ->with([
                'buildings:id,building_id',
                'elements:id,element_id',
                'critters:id,critter_id',
                'plants:id,plant_id',
                'geyserTypes:id,geyser_id',
            ])
            ->orderBy('category')
            ->orderBy('codex_id')
            ->get(['id', 'codex_id', 'category', 'parent_codex_id', 'title', 'title_key'])
            ->map(fn (CodexEntry $entry): CodexListItemData => CodexListItemData::from([
                'codex_id' => $entry->codex_id,
                'category' => $entry->category,
                'parent_codex_id' => $entry->parent_codex_id,
                'title' => $this->title($entry),
                'image' => $this->imageFor($entry),
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
            'title' => $this->title($codexEntry),
            'icon_asset' => $codexEntry->icon_asset,
            'content' => $this->translatedContent($codexEntry->content ?? []),
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
                'label' => $this->label($building->building_id, $building->getTranslations('name')),
                'url' => route('buildings.show', $building->building_id),
            ];
        }

        foreach ($entry->elements as $element) {
            $links[] = [
                'type' => 'element',
                'key' => $element->element_id,
                'label' => $this->label($element->element_id, $element->getTranslations('name')),
                'url' => route('elements.show', Str::kebab($element->element_id)),
            ];
        }

        foreach ($entry->critters as $critter) {
            $links[] = [
                'type' => 'critter',
                'key' => $critter->critter_id,
                'label' => $this->label($critter->critter_id, $critter->getTranslations('name')),
                'url' => null,
            ];
        }

        foreach ($entry->plants as $plant) {
            $links[] = [
                'type' => 'plant',
                'key' => $plant->plant_id,
                'label' => $this->label($plant->plant_id, $plant->getTranslations('name')),
                'url' => null,
            ];
        }

        foreach ($entry->geyserTypes as $geyser) {
            $links[] = [
                'type' => 'geyser',
                'key' => $geyser->geyser_id,
                'label' => $this->label($geyser->geyser_id, $geyser->getTranslations('name')),
                'url' => null,
            ];
        }

        return $links;
    }

    private function title(CodexEntry $entry): string
    {
        $stored = $entry->getTranslation('title', 'en');

        return resolve(GameText::class)->translate($entry->title_key)
            ?? (is_string($stored) && $stored !== '' ? $stored : $entry->codex_id);
    }

    /**
     * Text blocks keep their STRINGS key; serve them in the current locale as plain
     * strings, falling back to the English text stored at import.
     *
     * @param  array<int, array<string, mixed>>  $containers
     * @return array<int, array<string, mixed>>
     */
    private function translatedContent(array $containers): array
    {
        $text = resolve(GameText::class);

        return array_map(function (array $container) use ($text): array {
            /** @var list<array<string, mixed>> $blocks */
            $blocks = is_array($container['blocks'] ?? null) ? $container['blocks'] : [];
            $container['blocks'] = array_map(function (array $block) use ($text): array {
                if (($block['type'] ?? null) !== 'text') {
                    return $block;
                }

                $key = is_string($block['key'] ?? null) ? $block['key'] : null;
                $stored = is_array($block['text'] ?? null) ? ($block['text']['en'] ?? '') : (string) ($block['text'] ?? '');
                unset($block['key']);
                $block['text'] = $text->translate($key) ?? $stored;

                return $block;
            }, $blocks);

            return $container;
        }, $containers);
    }

    /**
     * Translated entity name, else its stored English name, else the id.
     *
     * @param  array<array-key, mixed>  $names
     */
    private function label(string $id, array $names): string
    {
        $stored = $names['en'] ?? null;

        return resolve(GameText::class)->name($id, is_string($stored) && $stored !== '' ? $stored : null);
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

        // Base variant first (shortest id, then alphabetical) so a family
        // entry shows the base sprite, not a morph's.
        $critters = $entry->critters->sortBy(fn ($critter): array => [mb_strlen((string) $critter->critter_id), $critter->critter_id]);
        foreach ($critters as $critter) {
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
        $relative = sprintf('assets/game/%s/%s.webp', $category, $key);

        return file_exists(public_path($relative)) ? '/'.$relative : null;
    }
}
