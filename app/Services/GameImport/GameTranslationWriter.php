<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use Illuminate\Support\Facades\File;

/**
 * Turns the game's strings into Laravel JSON translation catalogs keyed by
 * their STRINGS.* path: en.json from the official .pot template, and one
 * {locale}.json per {locale}.po found in the translations directory. Laravel
 * does not fall back across locales for JSON keys, so every locale catalog
 * starts from the English strings and overrides what is translated.
 */
final class GameTranslationWriter
{
    public function write(string $potPath, string $translationsDir, string $outputDir): void
    {
        File::ensureDirectoryExists($outputDir);

        $english = PoFile::read($potPath)->sources();
        $this->writeCatalog($outputDir.'/en.json', $english);

        foreach (File::glob($translationsDir.'/*.po') as $poPath) {
            $locale = pathinfo($poPath, PATHINFO_FILENAME);
            $this->writeCatalog(sprintf('%s/%s.json', $outputDir, $locale), [...$english, ...PoFile::read($poPath)->translations()]);
        }
    }

    /** @param array<string, string> $strings */
    private function writeCatalog(string $path, array $strings): void
    {
        ksort($strings);

        File::put($path, (string) json_encode($strings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
