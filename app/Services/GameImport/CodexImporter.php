<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Building;
use App\Models\CodexEntry;
use App\Models\Critter;
use App\Models\Element;
use App\Models\GeyserType;
use App\Models\Plant;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

final readonly class CodexImporter
{
    public function __construct(private StringResolver $stringResolver) {}

    public function import(string $codexDir): void
    {
        foreach (File::allFiles($codexDir) as $file) {
            if ($file->getExtension() !== 'yaml') {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = Yaml::parseFile($file->getRealPath(), Yaml::PARSE_CUSTOM_TAGS);

            $codexId = $data['id'] ?? null;
            if (! is_string($codexId)) {
                continue;
            }

            if ($codexId === '') {
                continue;
            }

            $category = $this->categoryFor((string) $file->getRelativePath());
            $titleKey = is_string($data['title'] ?? null) ? $data['title'] : null;
            $title = ($titleKey !== null ? $this->stringResolver->resolveToJson($titleKey) : null) ?? ['en' => $codexId];

            // The game yaml uses both keys: hand-written entries say parentId,
            // generated ones (creatures, element types) say parentEntryID.
            $parentId = $data['parentEntryID'] ?? $data['parentId'] ?? null;

            CodexEntry::query()->updateOrCreate(
                ['codex_id' => $codexId],
                [
                    'category' => $category,
                    'parent_codex_id' => is_string($parentId) ? $parentId : null,
                    'title' => $title,
                    'title_key' => $titleKey !== null ? StringResolver::normalizeKey($titleKey) : null,
                    'icon_asset' => is_string($data['iconAssetName'] ?? null) ? $data['iconAssetName'] : null,
                    'content' => $this->buildContent($data['contentContainers'] ?? []),
                    'dlc_id' => null,
                ],
            );
        }

        $this->linkEntities();
    }

    /**
     * Attach codex entries to the entities they document. Codex ids do not
     * reliably equal entity ids (Oilfloater vs OilFloater, Bean_Plant vs
     * BeanPlant, STATERPILLARSPECIES), so matching is done on a normalized
     * (lowercase, alphanumeric-only) key. Creature "<X>Species" family entries
     * additionally fall back to the critters' variant_family so e.g.
     * HatchSpecies links every hatch morph.
     */
    public function linkEntities(): void
    {
        $buildings = $this->normalizedIdMap(Building::query()->pluck('id', 'building_id')->all());
        $elements = $this->normalizedIdMap(Element::query()->pluck('id', 'element_id')->all());
        $plants = $this->normalizedIdMap(Plant::query()->pluck('id', 'plant_id')->all());
        $geysers = $this->normalizedIdMap(GeyserType::query()->pluck('id', 'geyser_id')->all());

        $critterIds = [];
        $familyIds = [];
        foreach (Critter::query()->get(['id', 'critter_id', 'variant_family']) as $critter) {
            $critterIds[$this->normalizeKey($critter->critter_id)][] = $critter->id;
            if ($critter->variant_family !== null) {
                $familyIds[$this->normalizeKey($critter->variant_family)][] = $critter->id;
            }
        }

        CodexEntry::query()->each(function (CodexEntry $entry) use ($buildings, $elements, $plants, $geysers, $critterIds, $familyIds): void {
            $key = $this->normalizeKey($entry->codex_id);
            match ($entry->category) {
                'Buildings' => $entry->buildings()->sync($buildings[$key] ?? []),
                'ElementTypes' => $entry->elements()->sync($elements[$key] ?? []),
                'Creatures' => $entry->critters()->sync($this->critterIdsFor($key, $critterIds, $familyIds)),
                'Plants' => $entry->plants()->sync($plants[$key] ?? []),
                'Geysers' => $entry->geyserTypes()->sync($geysers[$key] ?? []),
                default => null,
            };
        });
    }

    /**
     * @param  array<string, list<int>>  $critterIds
     * @param  array<string, list<int>>  $familyIds
     * @return list<int>
     */
    private function critterIdsFor(string $key, array $critterIds, array $familyIds): array
    {
        if (isset($critterIds[$key])) {
            return $critterIds[$key];
        }

        if (! str_ends_with($key, 'species')) {
            return [];
        }

        $base = mb_substr($key, 0, -mb_strlen('species'));

        return $familyIds[$base] ?? $critterIds[$base] ?? [];
    }

    /**
     * @param  array<string, int>  $idsByNaturalKey
     * @return array<string, list<int>>
     */
    private function normalizedIdMap(array $idsByNaturalKey): array
    {
        $map = [];
        foreach ($idsByNaturalKey as $naturalKey => $id) {
            $map[$this->normalizeKey((string) $naturalKey)][] = $id;
        }

        return $map;
    }

    private function normalizeKey(string $key): string
    {
        return mb_strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $key));
    }

    private function categoryFor(string $relativePath): string
    {
        if ($relativePath === '') {
            return 'Root';
        }

        return explode(DIRECTORY_SEPARATOR, $relativePath)[0];
    }

    /**
     * @return list<array{layout: string, lock_id: string|null, blocks: list<array<string, mixed>>}>
     */
    private function buildContent(mixed $containers): array
    {
        if (! is_array($containers)) {
            return [];
        }

        $result = [];

        foreach ($containers as $container) {
            if (! is_array($container)) {
                continue;
            }

            $items = $container['content'] ?? [];
            $blocks = [];
            foreach (is_array($items) ? $items : [] as $item) {
                $block = $item instanceof TaggedValue ? $this->buildBlock($item) : null;
                if ($block !== null) {
                    $blocks[] = $block;
                }
            }

            if ($blocks !== []) {
                $result[] = [
                    'layout' => is_string($container['contentLayout'] ?? null) ? $container['contentLayout'] : 'Vertical',
                    'lock_id' => is_string($container['lockID'] ?? null) ? $container['lockID'] : null,
                    'blocks' => $blocks,
                ];
            }
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function buildBlock(TaggedValue $item): ?array
    {
        /** @var array<string, mixed> $value */
        $value = is_array($item->getValue()) ? $item->getValue() : [];

        return match ($item->getTag()) {
            'CodexText' => $this->textBlock($value),
            'CodexImage' => [
                'type' => 'image',
                'sprite' => is_string($value['spriteName'] ?? null) ? $value['spriteName'] : null,
                'height' => is_int($value['preferredHeight'] ?? null) ? $value['preferredHeight'] : null,
            ],
            'CodexDividerLine' => ['type' => 'divider'],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|null
     */
    private function textBlock(array $value): ?array
    {
        $key = $value['stringKey'] ?? null;
        if (! is_string($key)) {
            return null;
        }

        $text = $this->stringResolver->resolveToJson($key);
        if ($text === null) {
            return null;
        }

        return [
            'type' => 'text',
            'style' => is_string($value['style'] ?? null) ? $value['style'] : 'Body',
            'key' => StringResolver::normalizeKey($key),
            'text' => $text,
        ];
    }
}
