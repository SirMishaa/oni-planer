<?php

declare(strict_types=1);

namespace App\Services\Mcp;

/**
 * Reads a zone's slow state (per-cell arrays over its box, row-major from the bottom-left
 * cell, element as an index into the palette) without ever handing the raw arrays out.
 */
final class ZoneTerrainSummary
{
    private const float KELVIN = 273.15;

    /**
     * Cells, total mass and temperature range per element, most cells first.
     *
     * @param  array<mixed>  $slowState
     * @return list<array{elementId: string, cells: int, mass: float, minC: float, maxC: float}>
     */
    public static function byElement(array $slowState): array
    {
        $palette = array_values((array) ($slowState['palette'] ?? []));
        $mass = array_values((array) ($slowState['mass'] ?? []));
        $temperature = array_values((array) ($slowState['temperature'] ?? []));
        $rows = [];

        foreach (array_values((array) ($slowState['element'] ?? [])) as $index => $paletteIndex) {
            $elementId = is_int($paletteIndex) && is_string($palette[$paletteIndex] ?? null) ? $palette[$paletteIndex] : null;
            if ($elementId === null) {
                continue;
            }

            $celsius = is_numeric($temperature[$index] ?? null) ? (float) $temperature[$index] - self::KELVIN : null;
            $row = $rows[$elementId] ?? ['elementId' => $elementId, 'cells' => 0, 'mass' => 0.0, 'minC' => INF, 'maxC' => -INF];
            $row['cells']++;
            $row['mass'] += is_numeric($mass[$index] ?? null) ? (float) $mass[$index] : 0.0;
            if ($celsius !== null) {
                $row['minC'] = min($row['minC'], $celsius);
                $row['maxC'] = max($row['maxC'], $celsius);
            }
            $rows[$elementId] = $row;
        }

        usort($rows, fn (array $a, array $b): int => $b['cells'] <=> $a['cells']);

        return $rows;
    }

    /**
     * The cell at world (x, y), or null outside the box or when nothing was read there.
     *
     * @param  array<mixed>  $slowState
     * @return array{elementId: string, mass: float|null, celsius: float|null}|null
     */
    public static function cellAt(array $slowState, int $x, int $y): ?array
    {
        $box = $slowState['box'] ?? null;
        if (! is_array($box)) {
            return null;
        }

        $localX = $x - (int) ($box['x'] ?? 0);
        $localY = $y - (int) ($box['y'] ?? 0);
        $width = (int) ($box['width'] ?? 0);
        if ($localX < 0 || $localY < 0 || $localX >= $width || $localY >= (int) ($box['height'] ?? 0)) {
            return null;
        }

        $index = $localY * $width + $localX;
        $paletteIndex = $slowState['element'][$index] ?? null;
        $elementId = is_int($paletteIndex) ? ($slowState['palette'][$paletteIndex] ?? null) : null;
        if (! is_string($elementId)) {
            return null;
        }

        $mass = $slowState['mass'][$index] ?? null;
        $temperature = $slowState['temperature'][$index] ?? null;

        return [
            'elementId' => $elementId,
            'mass' => is_numeric($mass) ? (float) $mass : null,
            'celsius' => is_numeric($temperature) ? (float) $temperature - self::KELVIN : null,
        ];
    }
}
