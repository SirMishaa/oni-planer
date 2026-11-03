<?php

declare(strict_types=1);

namespace App\Services\GameImport;

final class StringResolver
{
    /**
     * STRINGS sections searched (in priority order) when looking up a prefab
     * id: food items first, then other items, then creatures/plants. Seeds and
     * creature modifiers share segment names and are deliberately skipped.
     *
     * @var list<string>
     */
    private const array PREFAB_NAME_SECTIONS = [
        'STRINGS.ITEMS.FOOD.',
        'STRINGS.ITEMS.INDUSTRIAL_PRODUCTS.',
        'STRINGS.ITEMS.INGREDIENTS.',
        'STRINGS.CREATURES.SPECIES.',
    ];

    /** @var array<string, string> */
    private array $strings;

    /** @var array<string, string>|null normalized prefab segment → name key */
    private ?array $prefabNameKeys = null;

    /** @param  list<string>  $modPotPaths  supported mod templates; their strings override base ones */
    public function __construct(string $potFilePath, array $modPotPaths = [])
    {
        $strings = PoFile::read($potFilePath)->sources();

        foreach ($modPotPaths as $modPotPath) {
            $strings = [...$strings, ...ModStringKey::normalizeAll(PoFile::read($modPotPath)->sources())];
        }

        $this->strings = $strings;
    }

    /**
     * Canonical STRINGS key: the extractor may emit nested-type notation
     * (STRINGS.CREATURES/SPECIES/X::NAME) for STRINGS.CREATURES.SPECIES.X.NAME.
     */
    public static function normalizeKey(string $localizationId): string
    {
        return str_replace(['::', '/'], '.', $localizationId);
    }

    public function resolve(string $localizationId): ?string
    {
        return $this->strings[self::normalizeKey($localizationId)] ?? null;
    }

    /** @return array<string, string>|null */
    public function resolveToJson(string $localizationId): ?array
    {
        $value = $this->resolve($localizationId);

        return $value !== null ? ['en' => $value] : null;
    }

    public function has(string $localizationId): bool
    {
        return isset($this->strings[self::normalizeKey($localizationId)]);
    }

    /** Name key of a prefab id (e.g. DinosaurMeat → STRINGS.ITEMS.FOOD.DINOSAURMEAT.NAME). */
    public function findNameKey(string $prefabId): ?string
    {
        if ($this->prefabNameKeys === null) {
            $this->prefabNameKeys = $this->buildPrefabNameIndex();
        }

        return $this->prefabNameKeys[self::normalizeSegment($prefabId)] ?? null;
    }

    private static function normalizeSegment(string $value): string
    {
        return mb_strtolower(str_replace('_', '', $value));
    }

    /** @return array<string, string> */
    private function buildPrefabNameIndex(): array
    {
        $index = [];
        foreach (self::PREFAB_NAME_SECTIONS as $section) {
            foreach (array_keys($this->strings) as $key) {
                if (! str_starts_with($key, $section)) {
                    continue;
                }

                if (preg_match('/^([A-Z0-9_]+)\.NAME$/', mb_substr($key, mb_strlen($section)), $match) !== 1) {
                    continue;
                }

                $index[self::normalizeSegment($match[1])] ??= $key;
            }
        }

        return $index;
    }
}
