<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

/**
 * Turns the creatures of `database/entities.json` into critter records for
 * {@see \App\Services\GameImport\CritterImporter::importRecords()}.
 *
 * A creature is an entity tagged `CreatureBrain` with a temperature monitor
 * (this leaves out SweepBot, BeeHive and ShockWorm). Babies are morphs of
 * their adult: `is_base` means "not a baby", and a baby's lifespan and
 * calories come from its adult's `{adult}BaseTrait` in `db.json`, since babies
 * have no trait of their own. Incubation comes from the egg in `items.json`.
 *
 * Creatures owned by an unsupported mod are skipped, as are diet items,
 * diet products and eggs owned by one (counted in {@see self::skippedReferences()}).
 * Whether a referenced id exists in the database is the importer's concern.
 *
 * @phpstan-type CritterDietRecord array{consumed_element_id: string, amount_per_cycle: float, produced_element_id: string|null, conversion_ratio: float|null}
 * @phpstan-type CritterOutputRecord array{element_id: string, amount_per_cycle: float, output_type: 'egg'}
 * @phpstan-type CritterRecord array{
 *     critter_id: string,
 *     parent_critter_id: string|null,
 *     is_base: bool,
 *     species_tag: string|null,
 *     variant_family: string|null,
 *     min_temp: float,
 *     max_temp: float,
 *     calories_per_cycle: float,
 *     incubation_time: float,
 *     lifespan: float,
 *     overcrowding_threshold: int,
 *     name_localization_id: string|null,
 *     dlc_id: string|null,
 *     mod_id: int|null,
 *     diets: list<CritterDietRecord>,
 *     outputs: list<CritterOutputRecord>,
 * }
 */
final class CritterReader
{
    private const int SECONDS_PER_CYCLE = 600;

    /**
     * Species whose variant family differs from the species tag without its
     * `Species` suffix. The old extractor grouped by shared base config, which
     * Bee calls `BaseBeeConfig` and Glom, ParrotFish and PufferFish lack.
     *
     * @var array<string, string|null>
     */
    private const array VARIANT_FAMILY_EXCEPTIONS = [
        'BeetaSpecies' => 'Bee',
        'GlomSpecies' => null,
        'ParrotFishSpecies' => null,
        'PufferFishSpecies' => null,
    ];

    private int $skippedReferences = 0;

    public function __construct(private readonly OniExport $export, private readonly ModCatalog $catalog) {}

    /** @return list<CritterRecord> */
    public function records(): array
    {
        $this->skippedReferences = 0;

        $extras = $this->export->json('planner_extras.json')['entities'] ?? [];
        $traits = $this->baseTraits();
        $incubationRates = $this->incubationRates();
        $nameKeys = $this->creatureNameKeys();

        $records = [];

        foreach ($this->export->json('entities.json')['entities'] ?? [] as $entity) {
            $prefabId = (string) $entity['name'];

            if (! $this->isCreature($entity) || $this->catalog->isSkipped($prefabId)) {
                continue;
            }

            $adultId = $entity['babyMonitorDef']['adultPrefab']['Name'] ?? null;
            $trait = $traits[($adultId ?? $prefabId).'BaseTrait'] ?? ['calories' => 0.0, 'lifespan' => 0.0];
            $caloriesPerCycle = $trait['calories'];
            $speciesTag = $extras[$prefabId]['speciesTag'] ?? null;
            $fertility = $entity['fertilityMonitorDef'] ?? null;
            $eggId = $fertility['eggPrefab']['Name'] ?? null;
            $temperature = $entity['critterTemperatureMonitorDef'];

            $records[] = [
                'critter_id' => $prefabId,
                'parent_critter_id' => $adultId,
                'is_base' => $adultId === null,
                'species_tag' => $speciesTag,
                'variant_family' => $speciesTag !== null ? $this->variantFamily($speciesTag) : null,
                'min_temp' => (float) $temperature['temperatureColdUncomfortable'],
                'max_temp' => (float) $temperature['temperatureHotUncomfortable'],
                'calories_per_cycle' => $caloriesPerCycle,
                'incubation_time' => $eggId !== null && isset($incubationRates[$eggId])
                    ? $this->round(100 / ($incubationRates[$eggId] * self::SECONDS_PER_CYCLE))
                    : 0.0,
                'lifespan' => $trait['lifespan'],
                'overcrowding_threshold' => (int) ($entity['overcrowdingMonitorDef']['spaceRequiredPerCreature'] ?? 0),
                'name_localization_id' => $nameKeys[(string) ($entity['nameString'] ?? '')] ?? null,
                'dlc_id' => $entity['kPrefabID']['requiredDlcIds'][0] ?? null,
                'mod_id' => $this->catalog->modIdFor($prefabId),
                'diets' => $this->diets($entity['creatureCalorieMonitorDef']['diet']['infos'] ?? [], $caloriesPerCycle),
                'outputs' => $this->eggOutputs($eggId, (float) ($fertility['baseFertileCycles'] ?? 0)),
            ];
        }

        return $records;
    }

    /** Number of diet items, diet products and eggs dropped because they belong to an unsupported mod. */
    public function skippedReferences(): int
    {
        return $this->skippedReferences;
    }

    /** @param  array<string, mixed>  $entity */
    private function isCreature(array $entity): bool
    {
        if (($entity['critterTemperatureMonitorDef'] ?? null) === null) {
            return false;
        }

        foreach ($entity['kPrefabID']['tags'] ?? [] as $tag) {
            if (($tag['Name'] ?? null) === 'CreatureBrain') {
                return true;
            }
        }

        return false;
    }

    private function variantFamily(string $speciesTag): ?string
    {
        if (array_key_exists($speciesTag, self::VARIANT_FAMILY_EXCEPTIONS)) {
            return self::VARIANT_FAMILY_EXCEPTIONS[$speciesTag];
        }

        return str_ends_with($speciesTag, 'Species') ? mb_substr($speciesTag, 0, -mb_strlen('Species')) : $speciesTag;
    }

    /**
     * @param  list<array<string, mixed>>  $infos
     * @return list<CritterDietRecord>
     */
    private function diets(array $infos, float $caloriesPerCycle): array
    {
        $diets = [];

        foreach ($infos as $info) {
            $producedId = $info['producedElement']['Name'] ?? null;
            $caloriesPerKg = (float) ($info['caloriesPerKg'] ?? 0);

            if (is_string($producedId) && $this->catalog->isSkipped($producedId)) {
                $this->skippedReferences++;
                $producedId = null;
            }

            foreach ($info['consumedTags'] ?? [] as $tag) {
                $consumedId = (string) $tag['Name'];

                if ($this->catalog->isSkipped($consumedId)) {
                    $this->skippedReferences++;

                    continue;
                }

                $diets[] = [
                    'consumed_element_id' => $consumedId,
                    'amount_per_cycle' => $caloriesPerKg > 0 ? $this->round($caloriesPerCycle / $caloriesPerKg) : 0.0,
                    'produced_element_id' => is_string($producedId) && $producedId !== '' ? $producedId : null,
                    'conversion_ratio' => isset($info['producedConversionRate']) ? $this->round((float) $info['producedConversionRate']) : null,
                ];
            }
        }

        return $diets;
    }

    /** @return list<CritterOutputRecord> */
    private function eggOutputs(?string $eggId, float $fertileCycles): array
    {
        if ($eggId === null || $fertileCycles <= 0) {
            return [];
        }

        if ($this->catalog->isSkipped($eggId)) {
            $this->skippedReferences++;

            return [];
        }

        return [['element_id' => $eggId, 'amount_per_cycle' => $this->round(1 / $fertileCycles), 'output_type' => 'egg']];
    }

    /**
     * Calories per cycle and lifespan by trait id. Traits registered twice
     * (adult and baby) hold the same values, so the first one wins.
     *
     * @return array<string, array{calories: float, lifespan: float}>
     */
    private function baseTraits(): array
    {
        $traits = [];

        foreach ($this->export->json('db.json')['traits'] ?? [] as $trait) {
            $traitId = (string) $trait['Id'];

            if (isset($traits[$traitId])) {
                continue;
            }

            $calories = 0.0;
            $lifespan = 0.0;

            foreach ($trait['SelfModifiers'] ?? [] as $modifier) {
                match ($modifier['AttributeId'] ?? null) {
                    'CaloriesDelta' => $calories = $this->round(-(float) $modifier['Value'] * self::SECONDS_PER_CYCLE),
                    'AgeMax' => $lifespan = $this->round((float) $modifier['Value']),
                    default => null,
                };
            }

            $traits[$traitId] = ['calories' => $calories, 'lifespan' => $lifespan];
        }

        return $traits;
    }

    /** @return array<string, float> egg prefab id => base incubation rate (% per second) */
    private function incubationRates(): array
    {
        $rates = [];

        foreach ($this->export->json('items.json')['eggs'] ?? [] as $egg) {
            $rate = (float) ($egg['incubatorMonitorDef']['baseIncubationRate'] ?? 0);

            if ($rate > 0) {
                $rates[(string) $egg['name']] = $rate;
            }
        }

        return $rates;
    }

    /**
     * Name keys of creatures by their localized name: the export's
     * `nameString` reversed through `po_string.json`. Only
     * `CREATURES.SPECIES.*.NAME` keys qualify, which leaves out egg names and
     * buildings sharing a creature's name.
     *
     * @return array<string, string>
     */
    private function creatureNameKeys(): array
    {
        $keys = [];

        foreach ($this->export->json('po_string.json')['CREATURES'] ?? [] as $key => $text) {
            $key = (string) $key;

            if (str_starts_with($key, 'CREATURES.SPECIES.') && str_ends_with($key, '.NAME')) {
                $keys[(string) $text] ??= 'STRINGS.'.$key;
            }
        }

        return $keys;
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
