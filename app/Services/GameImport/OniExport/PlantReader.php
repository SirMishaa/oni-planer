<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

/**
 * Turns the plants of `database/entities.json` (prefabs tagged `Plant`) into
 * plant records for {@see \App\Services\GameImport\PlantImporter::importRecords()}.
 *
 * A plant is its own and only variant (the database never had real variants).
 * Temperatures are the lethal pair, pressures the warning pair (null when the
 * plant is not pressure sensitive), and the growth time is the crop duration in
 * cycles. Inputs are the irrigation and fertilization consumption, outputs the
 * crop (food when it is in `food.json`, else a resource) and the seed.
 * `safe_atmospheres` is a list while the column holds one element, so
 * `atmosphere_element_id` stays null. The kanim comes from `planner_extras.json`.
 *
 * Plants owned by an unsupported mod are skipped, as are inputs and outputs
 * owned by one (counted in {@see self::skippedReferences()}). Whether a
 * referenced id exists in the database is the importer's concern.
 *
 * @phpstan-type PlantInputRecord array{element_id: string, amount_per_cycle: float, input_type: 'irrigation'|'fertilizer'}
 * @phpstan-type PlantOutputRecord array{element_id: string, amount_per_harvest: float, output_type: 'food'|'resource'}
 * @phpstan-type PlantRecord array{
 *     plant_id: string,
 *     variant_id: string,
 *     is_base: true,
 *     min_temp: float,
 *     max_temp: float,
 *     min_pressure: float|null,
 *     max_pressure: float|null,
 *     atmosphere_element_id: null,
 *     light_required: bool,
 *     growth_time: float,
 *     name_localization_id: string|null,
 *     dlc_id: string|null,
 *     mod_id: int|null,
 *     kanim: string|null,
 *     inputs: list<PlantInputRecord>,
 *     outputs: list<PlantOutputRecord>,
 * }
 */
final class PlantReader
{
    private const int SECONDS_PER_CYCLE = 600;

    /** The game stores "no limit" as float.MaxValue. */
    private const float UNBOUNDED_PRESSURE = 3.0e38;

    private int $skippedReferences = 0;

    public function __construct(private readonly OniExport $export, private readonly ModCatalog $catalog) {}

    /** @return list<PlantRecord> */
    public function records(): array
    {
        $this->skippedReferences = 0;

        $extras = $this->export->json('planner_extras.json')['entities'] ?? [];
        $foodIds = array_flip(array_column($this->export->json('food.json')['foodInfoList'] ?? [], 'Id'));
        $nameKeys = $this->nameKeysByText();

        $records = [];

        foreach ($this->export->json('entities.json')['entities'] ?? [] as $entity) {
            $prefabId = (string) $entity['name'];

            if (! $this->isPlant($entity) || $this->catalog->isSkipped($prefabId)) {
                continue;
            }

            $pressure = $entity['pressureVulnerable'] ?? null;
            $isPressureSensitive = (bool) ($pressure['pressure_sensitive'] ?? false);
            $temperature = $entity['temperatureVulnerable'];

            $records[] = [
                'plant_id' => $prefabId,
                'variant_id' => $prefabId,
                'is_base' => true,
                'min_temp' => (float) $temperature['temperatureLethalLow'],
                'max_temp' => (float) $temperature['temperatureLethalHigh'],
                'min_pressure' => $isPressureSensitive ? $this->boundedPressure($pressure['pressureWarning_Low'] ?? null) : null,
                'max_pressure' => $isPressureSensitive ? $this->boundedPressure($pressure['pressureWarning_High'] ?? null) : null,
                'atmosphere_element_id' => null,
                'light_required' => ($entity['illuminationVulnerable'] ?? null) !== null
                    && ! ($entity['illuminationVulnerable']['prefersDarkness'] ?? false),
                'growth_time' => $this->round((float) ($entity['cropVal']['cropDuration'] ?? 0) / self::SECONDS_PER_CYCLE),
                'name_localization_id' => $this->nameKey($nameKeys[(string) ($entity['nameString'] ?? '')] ?? [], $prefabId),
                'dlc_id' => $entity['kPrefabID']['requiredDlcIds'][0] ?? null,
                'mod_id' => $this->catalog->modIdFor($prefabId),
                'kanim' => $extras[$prefabId]['kanim'] ?? null,
                'inputs' => [
                    ...$this->inputs($entity['irrigationDef'] ?? null, 'irrigation'),
                    ...$this->inputs($entity['fertilizationDef'] ?? null, 'fertilizer'),
                ],
                'outputs' => [
                    ...$this->output($entity['cropVal']['cropId'] ?? null, (float) ($entity['cropVal']['numProduced'] ?? 0), isset($foodIds[$entity['cropVal']['cropId'] ?? '']) ? 'food' : 'resource'),
                    ...$this->output($entity['seedInfo']['seedId'] ?? null, (float) ($entity['seedInfo']['newSeedsProduced'] ?? 0), 'resource'),
                ],
            ];
        }

        return $records;
    }

    /** Number of inputs and outputs dropped because they belong to an unsupported mod. */
    public function skippedReferences(): int
    {
        return $this->skippedReferences;
    }

    /** @param  array<string, mixed>  $entity */
    private function isPlant(array $entity): bool
    {
        foreach ($entity['kPrefabID']['tags'] ?? [] as $tag) {
            if (($tag['Name'] ?? null) === 'Plant') {
                return true;
            }
        }

        return false;
    }

    private function boundedPressure(mixed $pressure): ?float
    {
        if (! is_numeric($pressure) || abs((float) $pressure) >= self::UNBOUNDED_PRESSURE) {
            return null;
        }

        return $this->round((float) $pressure);
    }

    /**
     * @param  array<string, mixed>|null  $definition
     * @param  'irrigation'|'fertilizer'  $type
     * @return list<PlantInputRecord>
     */
    private function inputs(?array $definition, string $type): array
    {
        $inputs = [];

        foreach ($definition['consumedElements'] ?? [] as $consumed) {
            $elementId = (string) $consumed['tag']['Name'];

            if ($this->catalog->isSkipped($elementId)) {
                $this->skippedReferences++;

                continue;
            }

            $inputs[] = [
                'element_id' => $elementId,
                'amount_per_cycle' => $this->round((float) $consumed['massConsumptionRate'] * self::SECONDS_PER_CYCLE),
                'input_type' => $type,
            ];
        }

        return $inputs;
    }

    /**
     * @param  'food'|'resource'  $type
     * @return list<PlantOutputRecord>
     */
    private function output(?string $elementId, float $amount, string $type): array
    {
        if ($elementId === null || $elementId === '' || $amount <= 0) {
            return [];
        }

        if ($this->catalog->isSkipped($elementId)) {
            $this->skippedReferences++;

            return [];
        }

        return [['element_id' => $elementId, 'amount_per_harvest' => $amount, 'output_type' => $type]];
    }

    /**
     * `*.NAME` keys of `po_string.json` by their localized text, which the
     * export's `nameString` reverses to.
     *
     * @return array<string, list<string>>
     */
    private function nameKeysByText(): array
    {
        $keysByText = [];

        foreach ($this->export->json('po_string.json') as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group as $key => $text) {
                if (str_ends_with((string) $key, '.NAME')) {
                    $keysByText[(string) $text][] = (string) $key;
                }
            }
        }

        return $keysByText;
    }

    /**
     * When several keys share a text, seed keys lose, then keys whose segment
     * is the upper-cased prefab id win, then `CREATURES.SPECIES.*` keys.
     *
     * @param  list<string>  $keys
     */
    private function nameKey(array $keys, string $prefabId): ?string
    {
        if ($keys === []) {
            return null;
        }

        $score = static function (string $key) use ($prefabId): int {
            $segments = explode('.', $key);

            return (str_contains($key, '.SEEDS.') ? 0 : 4)
                + ($segments[count($segments) - 2] === mb_strtoupper($prefabId) ? 2 : 0)
                + (str_starts_with($key, 'CREATURES.SPECIES.') ? 1 : 0);
        };

        usort($keys, static fn (string $a, string $b): int => $score($b) <=> $score($a));

        return 'STRINGS.'.$keys[0];
    }

    /** Rounds a value computed from the export's single-precision floats to 7 significant digits. */
    private function round(float $value): float
    {
        if ($value === 0.0) {
            return 0.0;
        }

        $digits = 7 - (int) ceil(log10(abs($value)));

        return round($value, max($digits, 0));
    }
}
