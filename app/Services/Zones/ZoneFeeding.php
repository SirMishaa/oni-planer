<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Data\GameRules\AcceptedFoodData;
use App\Data\GameRules\ResourceFlowData;
use App\Data\ZoneCritterData;
use App\Data\ZoneFeedingData;
use App\Data\ZoneFeedingLineData;
use App\Data\ZonePopulationData;
use App\Models\Zone;
use App\Services\Critters\CritterGroupKey;
use App\Services\GameRules\Critters\CritterFeeding;
use App\Services\GameText;

/**
 * The feeding of each flock band of a zone: the {@see CritterFeeding} flows of the band against the
 * zone's {@see ZoneInventory}, with display names, the matching plants or prey in the zone, and the
 * foods trimmed to what the band shows. Shared by the zone page and the MCP server.
 */
final readonly class ZoneFeeding
{
    /** Item foods, and plant or prey foods, listed per band; the rest are counted in `otherFoods`. */
    public const int SHOWN_FOODS = 3;

    public function __construct(private CritterFeeding $feeding, private GameText $text) {}

    /**
     * One entry per flock band whose morph has a diet or an output, by group key. The diet rows are
     * read once per morph (the repository keeps them for the request).
     *
     * Each critter eats at its own metabolism ({@see CritterFeeding::metabolismRatio()}) and lays at its own
     * fertility ({@see CritterFeeding::fertilityRatio()}): a band of babies can mix tame and wild ones. A
     * critter is wild only when the wire sent `tame: false`; one whose tameness is unknown (an older mod) is
     * counted as tame. Its fertility is frozen in a cramped or confined region of `$population`, the rule of
     * `fertilityFrozen` in `resources/js/components/zones/zoneFlock.ts`.
     *
     * @param  list<ZoneCritterData>  $critters
     * @param  list<ZonePopulationData>|null  $population  the zone's crowding regions, null when unknown
     * @return list<ZoneFeedingData>
     */
    public function of(Zone $zone, array $critters, ?array $population = null): array
    {
        $traits = $this->sentTraits($zone);
        $frozen = [];
        foreach ($population ?? [] as $region) {
            if ($region->cramped || $region->confined) {
                $frozen[$region->regionId] = true;
            }
        }

        $groups = [];
        foreach ($critters as $critter) {
            $key = CritterGroupKey::of($critter);
            $wild = $traits[$critter->instanceId]['wild'] ?? false;
            $groups[$key] ??= ['prefabId' => $critter->prefabId, 'count' => 0, 'eaters' => 0.0, 'layers' => 0.0];
            $groups[$key]['count']++;
            $groups[$key]['eaters'] += CritterFeeding::metabolismRatio($wild, $critter->happinessState);
            $groups[$key]['layers'] += CritterFeeding::fertilityRatio(
                $wild,
                $traits[$critter->instanceId]['happiness'] ?? null,
                $critter->happinessState,
                $critter->regionId !== null && isset($frozen[$critter->regionId]),
            );
        }
        ksort($groups);

        $inventory = ZoneInventory::of($zone);
        $storageKnown = ZoneInventory::storageKnown($zone);
        $plants = $this->plantCounts($zone);
        $prey = array_count_values(array_map(fn (ZoneCritterData $critter): string => $critter->prefabId, $critters));

        $filters = ZoneBuildingSettings::feederFilters($zone);

        $feeding = [];
        foreach ($groups as $key => $group) {
            $flows = $this->feeding->flows((string) $key, $group['prefabId'], $group['count'], $zone->id, $group['eaters'], $group['layers']);
            if ($flows === []) {
                continue;
            }

            $consumed = array_values(array_filter($flows, fn (ResourceFlowData $flow): bool => $flow->direction === 'consumed'))[0] ?? null;
            $balance = $consumed === null ? null : $this->feeding->balance($consumed, $inventory);
            $accepts = $consumed->accepts ?? [];
            [$foods, $otherFoods] = $this->foods($accepts, $balance?->mainResource, $plants, $prey);
            $unticked = $this->untickedFoods($accepts, $filters);

            $feeding[] = new ZoneFeedingData(
                groupKey: (string) $key,
                count: $group['count'],
                foods: $foods,
                otherFoods: $otherFoods,
                produces: $this->produces($accepts, $balance?->mainResource, $flows),
                availableKg: $storageKnown ? $balance?->availableKg : null,
                deficitKgPerCycle: $storageKnown ? $balance?->deficitKgPerCycle : null,
                autonomyCycles: $storageKnown ? $balance?->autonomyCycles : null,
                blockedByFilter: $this->blockedByFilter($accepts, $unticked, $filters),
                untickedFoods: $unticked,
            );
        }

        return $feeding;
    }

    /**
     * The item foods (the main one first), then the plants and prey (the most present in the zone first),
     * each list cut to SHOWN_FOODS.
     *
     * @param  list<AcceptedFoodData>  $accepts
     * @param  array<string, int>|null  $plants  plants of the zone by prefab, null when unread
     * @param  array<string, int>  $prey  critters of the zone by prefab
     * @return array{0: list<ZoneFeedingLineData>, 1: int}
     */
    private function foods(array $accepts, ?string $mainResource, ?array $plants, array $prey): array
    {
        $inZone = fn (AcceptedFoodData $food): ?int => match ($food->kind) {
            'plant' => $plants === null ? null : $plants[$food->resource] ?? 0,
            'prey' => $prey[$food->resource] ?? 0,
            default => null,
        };

        $items = array_values(array_filter($accepts, fn (AcceptedFoodData $food): bool => $food->kind === CritterFeeding::STOCKABLE_KIND));
        usort($items, fn (AcceptedFoodData $a, AcceptedFoodData $b): int => ($b->resource === $mainResource) <=> ($a->resource === $mainResource));
        $grazed = array_values(array_filter($accepts, fn (AcceptedFoodData $food): bool => $food->kind !== CritterFeeding::STOCKABLE_KIND));
        usort($grazed, fn (AcceptedFoodData $a, AcceptedFoodData $b): int => ($inZone($b) ?? -1) <=> ($inZone($a) ?? -1));

        $shown = [...array_slice($items, 0, self::SHOWN_FOODS), ...array_slice($grazed, 0, self::SHOWN_FOODS)];

        return [
            array_map(fn (AcceptedFoodData $food): ZoneFeedingLineData => new ZoneFeedingLineData(
                $food->resource,
                $this->text->name($food->resource),
                $food->kgPerCycle,
                $food->kind,
                $inZone($food),
            ), $shown),
            count($accepts) - count($shown),
        ];
    }

    /**
     * The item foods of the diet that no feeder accepts. A feeder without filter (null) accepts every food, one
     * with nothing ticked (an empty list) none; empty when the filters are unknown.
     *
     * @param  list<AcceptedFoodData>  $accepts
     * @param  array<int|string, list<string>|null>|null  $filters  the filter of each feeder, null when unknown
     * @return list<ZoneFeedingLineData>
     */
    private function untickedFoods(array $accepts, ?array $filters): array
    {
        if ($filters === null) {
            return [];
        }

        $unticked = [];
        foreach ($accepts as $food) {
            if ($food->kind !== CritterFeeding::STOCKABLE_KIND) {
                continue;
            }

            $ticked = false;
            foreach ($filters as $filter) {
                if ($filter === null || in_array($food->resource, $filter, true)) {
                    $ticked = true;
                    break;
                }
            }

            if (! $ticked) {
                $unticked[] = new ZoneFeedingLineData($food->resource, $this->text->name($food->resource), $food->kgPerCycle, $food->kind);
            }
        }

        return $unticked;
    }

    /**
     * Blocked when the filters are known, the band eats item foods and no feeder accepts any of them.
     *
     * @param  list<AcceptedFoodData>  $accepts
     * @param  list<ZoneFeedingLineData>  $unticked
     * @param  array<int|string, list<string>|null>|null  $filters
     */
    private function blockedByFilter(array $accepts, array $unticked, ?array $filters): ?bool
    {
        $itemFoods = count(array_filter($accepts, fn (AcceptedFoodData $food): bool => $food->kind === CritterFeeding::STOCKABLE_KIND));

        return $filters === null || $itemFoods === 0 ? null : count($unticked) === $itemFoods;
    }

    /**
     * The dropping of the main item food (else the first item food, else the first food), then every
     * produced flow: eggs counted, anything else in kg.
     *
     * @param  list<AcceptedFoodData>  $accepts
     * @param  list<ResourceFlowData>  $flows
     * @return list<ZoneFeedingLineData>
     */
    private function produces(array $accepts, ?string $mainResource, array $flows): array
    {
        $items = array_values(array_filter($accepts, fn (AcceptedFoodData $food): bool => $food->kind === CritterFeeding::STOCKABLE_KIND));
        $eaten = array_values(array_filter($items, fn (AcceptedFoodData $food): bool => $food->resource === $mainResource))[0]
            ?? $items[0]
            ?? $accepts[0]
            ?? null;

        $lines = [];
        if ($eaten?->produces !== null && $eaten->producedKgPerCycle !== null) {
            $lines[] = new ZoneFeedingLineData($eaten->produces, $this->text->name($eaten->produces), $eaten->producedKgPerCycle, 'dropping');
        }
        foreach ($flows as $flow) {
            if ($flow->direction === 'produced') {
                $lines[] = new ZoneFeedingLineData($flow->resource, $this->text->name($flow->resource), $flow->perCycle, $flow->unit === 'count' ? 'egg' : 'dropping');
            }
        }

        return $lines;
    }

    /**
     * Per critter, from the raw wire rows: wild only when it sent `tame: false` (`ZoneCritterData::tame` reads
     * a missing value as false), and the happiness value unrounded (`ZoneCritterData::happiness` is an int).
     *
     * @return array<string, array{wild: bool, happiness: float|null}>
     */
    private function sentTraits(Zone $zone): array
    {
        $rows = $zone->fast_state['critters'] ?? null;

        $traits = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_scalar($row['instanceId'] ?? null)) {
                $happiness = is_array($row['happiness'] ?? null) ? ($row['happiness']['value'] ?? null) : null;
                $traits[(string) $row['instanceId']] = [
                    'wild' => ($row['tame'] ?? null) === false,
                    'happiness' => is_numeric($happiness) ? (float) $happiness : null,
                ];
            }
        }

        return $traits;
    }

    /** @return array<string, int>|null plants of the zone by prefab, null when the plants were not read */
    private function plantCounts(Zone $zone): ?array
    {
        $plants = $zone->fast_state['plants'] ?? null;
        if (! is_array($plants)) {
            return null;
        }

        $counts = [];
        foreach ($plants as $plant) {
            if (is_array($plant) && is_string($plant['id'] ?? null)) {
                $counts[$plant['id']] = ($counts[$plant['id']] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
