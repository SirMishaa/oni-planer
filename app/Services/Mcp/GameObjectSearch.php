<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Models\Building;
use App\Models\CodexEntry;
use App\Models\Critter;
use App\Models\Element;
use App\Models\Food;
use App\Models\Plant;
use App\Models\PrefabName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Finds game objects (elements, buildings, critters, plants, foods, codex entries) by id or
 * by name in French or English, accents and case ignored. The user plays in French; ids and
 * English names are what the game data uses.
 */
final class GameObjectSearch
{
    public const array KINDS = ['element', 'building', 'critter', 'plant', 'food', 'codex'];

    public const int MAX_RESULTS = 20;

    /** @var array<string, string>|null prefab id => STRINGS name key, for one search() call */
    private ?array $nameKeys = null;

    /**
     * Best matches first: exact id or name, then prefix, then fragment.
     *
     * @return list<array{kind: string, id: string, nameFr: string, nameEn: string}>
     */
    public function search(string $query, ?string $kind = null): array
    {
        $this->nameKeys = null;
        $needle = self::normalise($query);
        if ($needle === '') {
            return [];
        }

        $matches = [];
        foreach ($this->candidates($kind) as $candidate) {
            $score = null;
            foreach ([$candidate['id'], $candidate['nameFr'], $candidate['nameEn'], ...$candidate['aliases']] as $text) {
                $haystack = self::normalise($text);
                $score = min($score ?? 3, match (true) {
                    $haystack === $needle => 0,
                    str_starts_with($haystack, $needle) => 1,
                    str_contains($haystack, $needle) => 2,
                    default => 3,
                });
            }
            if ($score < 3) {
                $matches[] = [$score, ['kind' => $candidate['kind'], 'id' => $candidate['id'], 'nameFr' => $candidate['nameFr'], 'nameEn' => $candidate['nameEn']]];
            }
        }

        usort($matches, fn (array $a, array $b): int => [$a[0], $a[1]['id']] <=> [$b[0], $b[1]['id']]);
        $exact = array_filter($matches, fn (array $match): bool => $match[0] === 0);

        return array_column(array_slice($exact !== [] && count($exact) === 1 ? $exact : $matches, 0, self::MAX_RESULTS), 1);
    }

    /** The name of a prefab in a locale from the game catalogs, or null. */
    public function catalogName(string $prefabId, string $locale): ?string
    {
        $this->nameKeys ??= PrefabName::query()->pluck('name_key', 'prefab_id')->all();
        $key = $this->nameKeys[$prefabId] ?? null;

        return $key !== null && Lang::hasForLocale($key, $locale) ? Lang::get($key, [], $locale) : null;
    }

    /** The English value of a stored (translatable or plain) name. */
    private static function english(Model $row, string $attribute): ?string
    {
        $value = $row->getAttribute($attribute);
        if (method_exists($row, 'getTranslation') && in_array($attribute, $row->translatable ?? [], true)) {
            $value = $row->getTranslation($attribute, 'en', false);
        }
        if (is_array($value)) {
            $value = $value['en'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function normalise(string $text): string
    {
        return Str::lower(mb_trim(Str::ascii($text)));
    }

    /**
     * @return iterable<array{kind: string, id: string, nameFr: string, nameEn: string, aliases: list<string>}>
     */
    private function candidates(?string $kind): iterable
    {
        $sources = [
            'element' => fn () => Element::query()->get(['element_id', 'name'])->map(fn (Element $row): array => [$row->element_id, self::english($row, 'name'), []]),
            'building' => fn () => Building::query()->get(['building_id', 'name'])->map(fn (Building $row): array => [$row->building_id, self::english($row, 'name'), []]),
            'critter' => fn () => Critter::query()->with('morphs:id,critter_id,morph_id')->get(['id', 'critter_id', 'name'])->map(fn (Critter $row): array => [
                $row->critter_id,
                self::english($row, 'name'),
                $row->morphs->pluck('morph_id')->all(),
            ]),
            'plant' => fn () => Plant::query()->get(['plant_id', 'name'])->map(fn (Plant $row): array => [$row->plant_id, self::english($row, 'name'), []]),
            'food' => fn () => Food::query()->get(['food_id'])->map(fn (Food $row): array => [$row->food_id, null, []]),
            'codex' => fn () => CodexEntry::query()->get(['codex_id', 'title'])->map(fn (CodexEntry $row): array => [$row->codex_id, self::english($row, 'title'), []]),
        ];

        foreach ($sources as $sourceKind => $rows) {
            if ($kind !== null && $kind !== $sourceKind) {
                continue;
            }
            foreach ($rows() as [$id, $storedName, $aliases]) {
                $nameEn = $this->catalogName($id, 'en') ?? (is_string($storedName) && $storedName !== '' ? $storedName : $id);
                yield [
                    'kind' => $sourceKind,
                    'id' => $id,
                    'nameFr' => $this->catalogName($id, 'fr') ?? $nameEn,
                    'nameEn' => $nameEn,
                    'aliases' => $aliases,
                ];
            }
        }
    }
}
