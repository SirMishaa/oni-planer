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

            CodexEntry::query()->updateOrCreate(
                ['codex_id' => $codexId],
                [
                    'category' => $category,
                    'parent_codex_id' => is_string($data['parentId'] ?? null) ? $data['parentId'] : null,
                    'title' => $title,
                    'icon_asset' => is_string($data['iconAssetName'] ?? null) ? $data['iconAssetName'] : null,
                    'content' => $this->buildContent($data['contentContainers'] ?? []),
                    'dlc_id' => null,
                ],
            );
        }

        $this->linkEntities();
    }

    private function linkEntities(): void
    {
        CodexEntry::query()->each(function (CodexEntry $entry): void {
            match ($entry->category) {
                'Buildings' => $entry->buildings()->attach(
                    Building::query()->where('building_id', $entry->codex_id)->pluck('id'),
                ),
                'ElementTypes' => $entry->elements()->attach(
                    Element::query()->where('element_id', $entry->codex_id)->pluck('id'),
                ),
                'Creatures' => $entry->critters()->attach(
                    Critter::query()->where('critter_id', $entry->codex_id)->pluck('id'),
                ),
                'Plants' => $entry->plants()->attach(
                    Plant::query()->where('plant_id', $entry->codex_id)->pluck('id'),
                ),
                'Geysers' => $entry->geyserTypes()->attach(
                    GeyserType::query()->where('geyser_id', $entry->codex_id)->pluck('id'),
                ),
                default => null,
            };
        });
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
            'text' => $text,
        ];
    }
}
