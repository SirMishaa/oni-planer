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
        $capturing = false;
        $buffer = '';

        foreach (explode("\n", $content) as $rawLine) {
            $line = mb_rtrim($rawLine, "\r");

            if (str_starts_with($line, 'msgctxt "')) {
                if ($capturing && $currentCtxt !== null && $buffer !== '') {
                    $strings[$currentCtxt] = $buffer;
                }

                $currentCtxt = $this->unquote(mb_substr($line, 8));
                $capturing = false;
                $buffer = '';

                continue;
            }

            if (str_starts_with($line, 'msgid "')) {
                $capturing = true;
                $buffer = $this->unquote(mb_substr($line, 6));

                continue;
            }

            if ($capturing && str_starts_with($line, '"')) {
                $buffer .= $this->unquote($line);

                continue;
            }

            if ($capturing) {
                if ($currentCtxt !== null && $buffer !== '') {
                    $strings[$currentCtxt] = $buffer;
                }

                $capturing = false;
                $buffer = '';
            }
        }

        if ($capturing && $currentCtxt !== null && $buffer !== '') {
            $strings[$currentCtxt] = $buffer;
        }

        return $strings;
    }

    private function unquote(string $token): string
    {
        $token = mb_trim($token);
        if (str_starts_with($token, '"')) {
            $token = mb_substr($token, 1);
        }

        if (str_ends_with($token, '"')) {
            $token = mb_substr($token, 0, -1);
        }

        return str_replace(['\\"', '\\\\'], ['"', '\\'], $token);
    }
}
