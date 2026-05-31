<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Building;
use Illuminate\Support\Collection;

/**
 * Detects sub-groups within a category by finding common camelCase word fragments.
 *
 * Two-pass strategy:
 *   1. Last word (highest specificity): "ManualPressureDoor" → "Door"
 *   2. First word (prefix fallback):    "LadderFast" → "Ladder" (since last word "Fast" is unique)
 *
 * A word must appear in ≥ MIN_GROUP_SIZE buildings to become a sub-group.
 * Override specific building IDs with OVERRIDES to handle edge cases.
 */
final class BuildingSubGroupResolver
{
    private const int MIN_GROUP_SIZE = 2;

    /** @var array<string, string> building_id → forced sub-group label */
    private const array OVERRIDES = [];

    /**
     * @param  Collection<int, Building>  $buildings  All buildings for a single category.
     * @return array<string, string|null> building_id → sub-group label (null = ungrouped)
     */
    public function resolve(Collection $buildings): array
    {
        $lastGroups = array_filter(
            $this->wordFrequency($buildings, 'last'),
            fn (int $n): bool => $n >= self::MIN_GROUP_SIZE,
        );

        // Pass 1: assign by last word
        $result = [];
        $unassigned = [];
        foreach ($buildings as $building) {
            if (isset(self::OVERRIDES[$building->building_id])) { // @phpstan-ignore isset.offset (OVERRIDES is an intentionally empty extension point for edge-case building IDs)
                $result[$building->building_id] = self::OVERRIDES[$building->building_id];

                continue;
            }

            $name = $building->getTranslation('name', 'en');
            $words = $this->splitCamelCase(is_string($name) ? $name : '');
            $last = end($words);

            if (isset($lastGroups[$last])) {
                $result[$building->building_id] = $last;
            } else {
                $unassigned[$building->building_id] = reset($words);
            }
        }

        // Pass 2: assign remaining by first word (frequency computed only over unassigned)
        $firstFreq = array_count_values($unassigned);
        foreach ($unassigned as $buildingId => $first) {
            $result[$buildingId] = ($firstFreq[$first] ?? 0) >= self::MIN_GROUP_SIZE ? $first : null;
        }

        return $result;
    }

    /**
     * @param  Collection<int, Building>  $buildings
     * @return array<string, int>
     */
    private function wordFrequency(Collection $buildings, string $position): array
    {
        $counts = [];
        foreach ($buildings as $building) {
            $name = $building->getTranslation('name', 'en');
            $words = $this->splitCamelCase(is_string($name) ? $name : '');
            $word = $position === 'last' ? end($words) : reset($words);
            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return non-empty-list<string> */
    private function splitCamelCase(string $name): array
    {
        $trimmed = mb_trim($name);

        // Space-separated names (proper English): take words as-is
        if (mb_strpos($trimmed, ' ') !== false) {
            return preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [$trimmed];
        }

        // CamelCase: split on lowercase→uppercase and uppercase→uppercase+lowercase boundaries
        $parts = preg_split('/(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);

        return ($parts && count($parts) > 1) ? $parts : [$trimmed];
    }
}
