<?php

declare(strict_types=1);

namespace App\Services\GameImport;

/**
 * Mod gettext keys are prefixed with the mod namespace
 * (`Namespace.STRINGS.X.NAME`); the game catalogs use the bare `STRINGS.*` path.
 */
final class ModStringKey
{
    public static function normalize(string $key): string
    {
        $position = mb_strpos($key, 'STRINGS.');

        return $position === false ? $key : mb_substr($key, $position);
    }

    /**
     * @param  array<string, string>  $strings
     * @return array<string, string>
     */
    public static function normalizeAll(array $strings): array
    {
        $normalized = [];

        foreach ($strings as $key => $value) {
            $normalized[self::normalize($key)] = $value;
        }

        return $normalized;
    }
}
