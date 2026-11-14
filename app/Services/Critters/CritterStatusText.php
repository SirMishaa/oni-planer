<?php

declare(strict_types=1);

namespace App\Services\Critters;

use App\Services\GameText;
use Illuminate\Support\Str;

/** Display name of a critter status item or effect id, from the game strings. */
final class CritterStatusText
{
    /**
     * Overcrowding effects whose game string lives under MODIFIERS.OVERCROWDED.
     *
     * @var array<string, string>
     */
    private const array OVERCROWDING = [
        'OVERCROWDED' => 'CROWDED',
        'FUTUREOVERCROWDED' => 'CRAMPED',
        'CRAMPED' => 'CRAMPED',
        'CONFINED' => 'CONFINED',
    ];

    public static function name(string $id): string
    {
        $key = Str::upper($id);
        $text = app(GameText::class);
        $overcrowding = self::OVERCROWDING[$key] ?? null;

        return $text->translate("STRINGS.CREATURES.STATUSITEMS.{$key}.NAME")
            ?? $text->translate("STRINGS.CREATURES.MODIFIERS.{$key}.NAME")
            ?? ($overcrowding === null ? null : $text->translate("STRINGS.CREATURES.MODIFIERS.OVERCROWDED.{$overcrowding}.NAME"))
            ?? $id;
    }
}
