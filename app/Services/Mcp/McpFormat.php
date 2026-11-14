<?php

declare(strict_types=1);

namespace App\Services\Mcp;

/** Number formats shared by the MCP tools: short, unit-suffixed, "unavailable" when unread. */
final class McpFormat
{
    /** "1.5 kg", "0 kg", "1234.6 kg". */
    public static function kg(?float $kilograms): string
    {
        return $kilograms === null ? 'unavailable' : self::number($kilograms, $kilograms < 0.01 && $kilograms > 0 ? 4 : 1).' kg';
    }

    /** "22.0 °C". */
    public static function celsius(?float $celsius): string
    {
        return $celsius === null ? 'unavailable' : number_format($celsius, 1, '.', '').' °C';
    }

    /** Trims trailing zeros: 12.5, 100, 0.0648. */
    public static function number(float $value, int $decimals = 1): string
    {
        $formatted = number_format($value, $decimals, '.', '');

        return str_contains($formatted, '.') ? mb_rtrim(mb_rtrim($formatted, '0'), '.') : $formatted;
    }

    /** "w(105,201)". */
    public static function world(int $x, int $y): string
    {
        return sprintf('w(%d,%d)', $x, $y);
    }
}
