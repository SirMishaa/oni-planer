<?php

declare(strict_types=1);

namespace App\Services\GameImport;

final class StringResolver
{
    /** @var array<string, string> */
    private array $strings = [];

    public function __construct(string $potFilePath)
    {
        $this->strings = $this->parsePot($potFilePath);
    }

    public function resolve(string $localizationId): ?string
    {
        $value = $this->strings[$localizationId] ?? null;

        return $value !== null ? $this->stripMarkup($value) : null;
    }

    /** @return array<string, string>|null */
    public function resolveToJson(string $localizationId): ?array
    {
        $value = $this->resolve($localizationId);

        return $value !== null ? ['en' => $value] : null;
    }

    private function stripMarkup(string $value): string
    {
        // Strip ONI link markup: <link="...">text</link> → text
        $value = (string) preg_replace('/<link=["\'][^"\']*["\']>(.*?)<\/link>/i', '$1', $value);

        // Strip remaining HTML-like tags (<b>, <i>, <color=#...>, etc.)
        return strip_tags($value);
    }

    /** @return array<string, string> */
    private function parsePot(string $path): array
    {
        $content = (string) file_get_contents($path);
        $strings = [];
        $currentCtxt = null;

        foreach (explode("\n", $content) as $line) {
            if (str_starts_with($line, 'msgctxt "')) {
                $currentCtxt = mb_trim(mb_substr($line, 9), '"');
            } elseif (str_starts_with($line, 'msgid "') && $currentCtxt !== null) {
                $value = mb_trim(mb_substr($line, 7), '"');
                if ($value !== '') {
                    $strings[$currentCtxt] = $value;
                }

                $currentCtxt = null;
            }
        }

        return $strings;
    }
}
