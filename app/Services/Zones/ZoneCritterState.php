<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Data\ZoneCritterData;
use App\Data\ZoneCrowdingRegionData;
use App\Data\ZoneEggData;
use App\Models\Zone;
use App\Services\Critters\CritterStatusText;
use App\Services\GameText;

/**
 * The critters, eggs and crowding regions the mod sent for a zone: `critters` and `crowding`
 * in the fast state, `eggs` in the slow state. A section the game did not send is null
 * (unknown), an empty one is an empty list. Malformed rows are skipped, never thrown.
 */
final class ZoneCritterState
{
    /**
     * @return list<ZoneCritterData>|null
     */
    public static function critters(Zone $zone): ?array
    {
        $rows = self::rows($zone->fast_state['critters'] ?? null);

        return $rows === null ? null : array_values(array_filter(array_map(self::critter(...), $rows)));
    }

    /**
     * @return list<ZoneEggData>|null
     */
    public static function eggs(Zone $zone): ?array
    {
        $rows = self::rows($zone->slow_state['eggs'] ?? null);

        return $rows === null ? null : array_values(array_filter(array_map(self::egg(...), $rows)));
    }

    /**
     * @return list<ZoneCrowdingRegionData>|null
     */
    public static function regions(Zone $zone): ?array
    {
        $rows = self::rows($zone->fast_state['crowding'] ?? null);

        return $rows === null ? null : array_values(array_filter(array_map(self::region(...), $rows)));
    }

    /**
     * @return list<mixed>|null
     */
    private static function rows(mixed $section): ?array
    {
        return is_array($section) ? array_values($section) : null;
    }

    private static function critter(mixed $row): ?ZoneCritterData
    {
        $instanceId = self::id($row['instanceId'] ?? null);
        $prefabId = self::string($row['prefabId'] ?? null);
        if (! is_array($row) || $instanceId === null || $prefabId === null || ! is_int($row['cell'] ?? null)
            || ! is_numeric($row['x'] ?? null) || ! is_numeric($row['y'] ?? null)) {
            return null;
        }

        $age = self::measure($row['age'] ?? null);
        $calories = self::measure($row['calories'] ?? null);
        $fertility = self::measure($row['fertility'] ?? null);
        $happiness = is_array($row['happiness'] ?? null) ? $row['happiness'] : [];
        $statuses = [];
        foreach ([...self::rows($row['statuses'] ?? null) ?? [], ...self::rows($row['effects'] ?? null) ?? []] as $status) {
            $id = self::string($status['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $name = CritterStatusText::name($id);
            $statuses[$name] ??= ['id' => $id, 'name' => $name, 'bad' => ($status['bad'] ?? false) === true];
        }

        return new ZoneCritterData(
            instanceId: $instanceId,
            prefabId: $prefabId,
            name: app(GameText::class)->name($prefabId),
            sprite: self::sprite('critters', $prefabId),
            cell: $row['cell'],
            x: (float) $row['x'],
            y: (float) $row['y'],
            facing: ($row['facing'] ?? null) === 'right' ? 'right' : 'left',
            navType: self::string($row['navType'] ?? null) ?? '',
            moving: ($row['moving'] ?? false) === true,
            speed: is_numeric($row['speed'] ?? null) ? (float) $row['speed'] : 0.0,
            path: self::path($row['path'] ?? null),
            aquatic: ($row['aquatic'] ?? false) === true,
            tame: ($row['tame'] ?? false) === true,
            baby: ($row['baby'] ?? false) === true,
            ageCycles: $age['value'] ?? null,
            lifespanCycles: $age['max'] ?? null,
            caloriesRatio: self::ratio($calories),
            fertilityRatio: self::ratio($fertility),
            breeding: self::breeding($row['breeding'] ?? null),
            happiness: is_numeric($happiness['value'] ?? null) ? (int) $happiness['value'] : null,
            happinessState: self::string($happiness['state'] ?? null),
            stomach: self::stomach($row['stomach'] ?? null),
            nextPoop: self::string($row['nextPoop'] ?? null),
            hungry: ($row['hungry'] ?? false) === true,
            statuses: array_values($statuses),
            regionId: self::string($row['regionId'] ?? null),
        );
    }

    private static function egg(mixed $row): ?ZoneEggData
    {
        $instanceId = self::id($row['instanceId'] ?? null);
        $prefabId = self::string($row['prefabId'] ?? null);
        if (! is_array($row) || $instanceId === null || $prefabId === null || ! is_int($row['cell'] ?? null)) {
            return null;
        }

        return new ZoneEggData(
            instanceId: $instanceId,
            prefabId: $prefabId,
            name: app(GameText::class)->name($prefabId),
            sprite: self::sprite('eggs', $prefabId),
            cell: $row['cell'],
            incubation: is_numeric($row['incubation'] ?? null) ? (float) $row['incubation'] : null,
            viability: is_numeric($row['viability'] ?? null) ? (float) $row['viability'] : null,
            tame: ($row['tame'] ?? false) === true,
            spawns: self::string($row['spawns'] ?? null),
            location: self::string($row['location'] ?? null),
            holderId: self::id($row['holderId'] ?? null),
            regionId: self::string($row['regionId'] ?? null),
        );
    }

    private static function region(mixed $row): ?ZoneCrowdingRegionData
    {
        $id = self::string($row['id'] ?? null);
        if (! is_array($row) || $id === null) {
            return null;
        }

        $counts = [];
        foreach (is_array($row['counts'] ?? null) ? $row['counts'] : [] as $species => $count) {
            if (is_numeric($count)) {
                $counts[(string) $species] = (int) $count;
            }
        }

        $states = [];
        foreach (is_array($row['states'] ?? null) ? $row['states'] : [] as $species => $state) {
            if (is_array($state)) {
                $states[(string) $species] = [
                    'overcrowded' => ($state['overcrowded'] ?? false) === true,
                    'cramped' => ($state['cramped'] ?? false) === true,
                    'confined' => ($state['confined'] ?? false) === true,
                    'modifier' => is_numeric($state['modifier'] ?? null) ? (int) $state['modifier'] : 0,
                ];
            }
        }

        return new ZoneCrowdingRegionData(
            id: $id,
            kind: self::string($row['kind'] ?? null) ?? 'cavity',
            cellCount: is_numeric($row['cellCount'] ?? null) ? (int) $row['cellCount'] : 0,
            occupied: is_numeric($row['occupied'] ?? null) ? (int) $row['occupied'] : 0,
            eggOccupied: is_numeric($row['eggOccupied'] ?? null) ? (int) $row['eggOccupied'] : 0,
            counts: $counts,
            states: $states,
        );
    }

    /**
     * @return array{value: float, max: float}|null
     */
    private static function measure(mixed $measure): ?array
    {
        return is_array($measure) && is_numeric($measure['value'] ?? null) && is_numeric($measure['max'] ?? null)
            ? ['value' => (float) $measure['value'], 'max' => (float) $measure['max']]
            : null;
    }

    /**
     * @param  array{value: float, max: float}|null  $measure
     */
    private static function ratio(?array $measure): ?float
    {
        return $measure !== null && $measure['max'] > 0 ? $measure['value'] / $measure['max'] : null;
    }

    /**
     * @return list<array{cell: int, navType: string}>
     */
    private static function path(mixed $path): array
    {
        $steps = [];
        foreach (self::rows($path) ?? [] as $step) {
            if (is_array($step) && is_int($step['cell'] ?? null)) {
                $steps[] = ['cell' => $step['cell'], 'navType' => self::string($step['navType'] ?? null) ?? ''];
            }
        }

        return $steps;
    }

    /**
     * The next-egg odds, each egg named from the game strings.
     *
     * @return list<array{egg: string, name: string, weight: float}>
     */
    private static function breeding(mixed $breeding): array
    {
        $odds = [];
        foreach (self::rows($breeding) ?? [] as $row) {
            $egg = self::string($row['egg'] ?? null);
            if ($egg !== null && is_numeric($row['weight'] ?? null)) {
                $odds[] = ['egg' => $egg, 'name' => app(GameText::class)->name($egg), 'weight' => (float) $row['weight']];
            }
        }

        return $odds;
    }

    /**
     * @return list<array{food: string, calories: float}>
     */
    private static function stomach(mixed $stomach): array
    {
        $meals = [];
        foreach (self::rows($stomach) ?? [] as $row) {
            $food = self::string($row['food'] ?? null);
            if ($food !== null && is_numeric($row['calories'] ?? null)) {
                $meals[] = ['food' => $food, 'calories' => (float) $row['calories']];
            }
        }

        return $meals;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Instance ids are strings on the wire; a number is accepted. */
    private static function id(mixed $value): ?string
    {
        return is_int($value) ? (string) $value : self::string($value);
    }

    /** Root-relative URL of an imported game sprite, or null when the asset is missing. */
    private static function sprite(string $category, string $id): ?string
    {
        $relative = sprintf('%s/%s.webp', $category, $id);

        return $id !== '' && file_exists(config()->string('game.assets_path').'/'.$relative) ? '/assets/game/'.$relative : null;
    }
}
