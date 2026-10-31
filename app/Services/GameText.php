<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PrefabName;
use Illuminate\Support\Facades\Lang;

/**
 * Display text of game prefabs (elements, buildings, critters, plants, geysers,
 * items...) in the current locale, from the STRINGS catalogs written by
 * `game:import`. Descriptions and effects are the DESC / EFFECT siblings of the
 * prefab's NAME key. Bound per request (scoped) so the key map never leaks
 * across Octane requests.
 */
final class GameText
{
    /** @var array<string, string>|null prefab_id => STRINGS name key */
    private ?array $nameKeys = null;

    public function nameKey(string $prefabId): ?string
    {
        $this->nameKeys ??= PrefabName::query()->pluck('name_key', 'prefab_id')->all();

        return $this->nameKeys[$prefabId] ?? null;
    }

    public function name(string $prefabId, ?string $fallback = null): string
    {
        return $this->translate($this->nameKey($prefabId)) ?? $fallback ?? $prefabId;
    }

    public function description(string $prefabId): ?string
    {
        return $this->translate($this->siblingKey($prefabId, 'DESC'));
    }

    public function effect(string $prefabId): ?string
    {
        return $this->translate($this->siblingKey($prefabId, 'EFFECT'));
    }

    /** Translate a raw STRINGS key, or null when the catalogs do not know it. */
    public function translate(?string $key): ?string
    {
        if ($key === null || ! Lang::has($key)) {
            return null;
        }

        return __($key);
    }

    private function siblingKey(string $prefabId, string $suffix): ?string
    {
        $nameKey = $this->nameKey($prefabId);
        if ($nameKey === null || ! str_ends_with($nameKey, '.NAME')) {
            return null;
        }

        return mb_substr($nameKey, 0, -mb_strlen('NAME')).$suffix;
    }
}
