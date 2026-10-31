<?php

declare(strict_types=1);

namespace App\Services\GameImport;

/**
 * Reads a gettext .pot/.po file keyed by msgctxt (ONI uses the STRINGS.* path
 * as context), with multi-line continuations and ONI markup stripped.
 */
final readonly class PoFile
{
    /** @param array<string, array{id: string, str: string}> $entries */
    private function __construct(private array $entries) {}

    public static function read(string $path): self
    {
        return new self(self::parse((string) file_get_contents($path)));
    }

    public static function stripMarkup(string $value): string
    {
        // ONI link markup: <link="...">text</link> → text
        $value = (string) preg_replace('/<link=["\'][^"\']*["\']>(.*?)<\/link>/i', '$1', $value);

        // Remaining HTML-like tags (<b>, <i>, <color=#...>, etc.)
        return strip_tags($value);
    }

    /**
     * Source (msgid) strings, e.g. the English text of the .pot template.
     *
     * @return array<string, string>
     */
    public function sources(): array
    {
        return $this->column('id');
    }

    /**
     * Translated (msgstr) strings; untranslated entries are omitted.
     *
     * @return array<string, string>
     */
    public function translations(): array
    {
        return $this->column('str');
    }

    /** @return array<string, array{id: string, str: string}> */
    private static function parse(string $content): array
    {
        $entries = [];
        $context = null;
        $current = ['id' => '', 'str' => ''];
        $field = null;

        $flush = static function () use (&$entries, &$context, &$current): void {
            if ($context !== null) {
                $entries[$context] = $current;
            }
        };

        foreach (explode("\n", $content) as $rawLine) {
            $line = mb_rtrim($rawLine, "\r");

            if (str_starts_with($line, 'msgctxt "')) {
                $flush();
                $context = self::unquote(mb_substr($line, 8));
                $current = ['id' => '', 'str' => ''];
                $field = null;

                continue;
            }

            if (str_starts_with($line, 'msgid "')) {
                $field = 'id';
                $current['id'] = self::unquote(mb_substr($line, 6));

                continue;
            }

            if (str_starts_with($line, 'msgstr "')) {
                $field = 'str';
                $current['str'] = self::unquote(mb_substr($line, 7));

                continue;
            }

            if ($field !== null && str_starts_with($line, '"')) {
                $current[$field] .= self::unquote($line);

                continue;
            }

            $field = null;
        }

        $flush();

        return $entries;
    }

    private static function unquote(string $token): string
    {
        $token = mb_trim($token);
        if (str_starts_with($token, '"')) {
            $token = mb_substr($token, 1);
        }

        if (str_ends_with($token, '"')) {
            $token = mb_substr($token, 0, -1);
        }

        return str_replace(['\\"', '\\n', '\\\\'], ['"', "\n", '\\'], $token);
    }

    /** @return array<string, string> */
    private function column(string $field): array
    {
        $values = [];
        foreach ($this->entries as $context => $entry) {
            if ($entry[$field] !== '') {
                $values[$context] = self::stripMarkup($entry[$field]);
            }
        }

        return $values;
    }
}
