<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Data\BuildingSettingsData;
use App\Services\GameText;
use App\Services\Zones\ZoneBuildingSettings;

/** The filter and capacity of a building as one MCP line. */
final class McpBuildingSettings
{
    /**
     * "filter: Algae, Kelp; capacity 340/500 kg (max 2000)", "filter: nothing ticked", or null when the building has neither.
     *
     * @param  array<string, mixed>  $building  one item of the fast `buildings` section
     */
    public static function line(array $building, GameText $text): ?string
    {
        $settings = ZoneBuildingSettings::of($building);
        if (! $settings instanceof BuildingSettingsData) {
            return null;
        }

        $parts = [];
        if ($settings->filter !== null) {
            $parts[] = 'filter: '.($settings->filter === []
                ? 'nothing ticked'
                : implode(', ', array_map(fn (string $id): string => self::label($id, $text), $settings->filter)));
        }

        $capacity = $settings->capacity;
        if ($capacity !== null) {
            $number = fn (?float $value): string => $value === null ? '?' : McpFormat::number($value);
            $parts[] = sprintf('capacity %s/%s %s%s', $number($capacity->stored), $number($capacity->limit), $capacity->unit, $capacity->max === null ? '' : ' (max '.McpFormat::number($capacity->max).')');
        }

        return implode('; ', $parts);
    }

    /** The display name, with the id when it differs. */
    private static function label(string $id, GameText $text): string
    {
        $name = $text->name($id);

        return $name === $id ? $id : "{$name} ({$id})";
    }
}
