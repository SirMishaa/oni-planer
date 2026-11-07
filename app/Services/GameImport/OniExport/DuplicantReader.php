<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

use Illuminate\Support\Str;

/**
 * Turns `db.json` traits and the `Minion` entity into duplicant records for
 * {@see \App\Services\GameImport\DuplicantImporter::importRecords()}.
 *
 * The base stats come from `MinionBaseTrait` (air, calories and bladder rates
 * per second, converted to the per-second / per-cycle units the database
 * stores) and the `Minion` prefab (CO2 conversion, mass). Duplicant traits are
 * the traits that are neither a critter `*BaseTrait`, a plant `*Original`, nor
 * a `StartWithBooster_*`; the export registers some twice, the first wins.
 * Effects keep the raw `SelfModifiers` value, so the sign and scale are the
 * game's. Names are `STRINGS.DUPLICANTS.TRAITS.{ID}.NAME` when the export
 * declares that key, else the key whose text is the trait's localized name.
 *
 * @phpstan-type TraitEffectRecord array{stat: string, modifier: float, modifier_type: 'add'|'multiply'}
 * @phpstan-type TraitRecord array{
 *     trait_id: string,
 *     is_positive: bool,
 *     name_localization_id: string|null,
 *     dlc_id: null,
 *     effects: list<TraitEffectRecord>,
 * }
 * @phpstan-type BaseStatsRecord array{
 *     oxygen_consumption_gs: float,
 *     co2_production_gs: float,
 *     calories_per_cycle: int,
 *     mass_kg: float,
 *     bladder_fill_per_cycle: float,
 * }
 */
final readonly class DuplicantReader
{
    private const int SECONDS_PER_CYCLE = 600;

    private const string BASE_TRAIT = 'MinionBaseTrait';

    /** Prefixes of the keys that can name a duplicant trait. */
    private const array NAME_KEY_PREFIXES = ['DUPLICANTS.TRAITS.', 'DUPLICANTS.CONGENITALTRAITS.'];

    public function __construct(private OniExport $export) {}

    /** @return array{base_stats: BaseStatsRecord, traits: list<TraitRecord>} */
    public function records(): array
    {
        $traits = $this->export->json('db.json')['traits'] ?? [];

        return [
            'base_stats' => $this->baseStats($traits),
            'traits' => $this->traits($traits),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $traits
     * @return BaseStatsRecord
     */
    private function baseStats(array $traits): array
    {
        $base = [];

        foreach ($traits as $trait) {
            if ($trait['Id'] === self::BASE_TRAIT) {
                foreach ($trait['SelfModifiers'] ?? [] as $modifier) {
                    $base[$modifier['AttributeId']] ??= (float) $modifier['Value'];
                }

                break;
            }
        }

        $minion = $this->minion();
        $airPerSecond = $base['AirConsumptionRate'] ?? 0.0;
        $conversion = (float) ($minion['oxygenBreather']['O2toCO2conversion'] ?? 0);

        return [
            'oxygen_consumption_gs' => $this->round($airPerSecond * 1000),
            'co2_production_gs' => $this->round($airPerSecond * $conversion * 1000),
            'calories_per_cycle' => (int) round(-($base['CaloriesDelta'] ?? 0.0) * self::SECONDS_PER_CYCLE / 1000),
            'mass_kg' => (float) ($minion['primaryElement']['Mass'] ?? 0),
            'bladder_fill_per_cycle' => $this->round(($base['BladderDelta'] ?? 0.0) * self::SECONDS_PER_CYCLE / 100),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $traits
     * @return list<TraitRecord>
     */
    private function traits(array $traits): array
    {
        $keys = $this->nameKeys();
        $records = [];

        foreach ($traits as $trait) {
            $traitId = (string) $trait['Id'];

            if (isset($records[$traitId]) || ! $this->isDuplicantTrait($traitId)) {
                continue;
            }

            $records[$traitId] = [
                'trait_id' => $traitId,
                'is_positive' => (bool) ($trait['PositiveTrait'] ?? false),
                'name_localization_id' => $this->nameKey($traitId, (string) ($trait['Name'] ?? ''), $keys),
                'dlc_id' => null,
                'effects' => array_map(fn (array $modifier): array => [
                    'stat' => Str::snake((string) $modifier['AttributeId']),
                    'modifier' => $this->round((float) $modifier['Value']),
                    'modifier_type' => ($modifier['IsMultiplier'] ?? false) ? 'multiply' : 'add',
                ], $trait['SelfModifiers'] ?? []),
            ];
        }

        return array_values($records);
    }

    private function isDuplicantTrait(string $traitId): bool
    {
        return ! str_ends_with($traitId, 'BaseTrait')
            && ! str_ends_with($traitId, 'Original')
            && ! str_starts_with($traitId, 'StartWithBooster_');
    }

    /** @return array<string, mixed> */
    private function minion(): array
    {
        foreach ($this->export->json('entities.json')['entities'] ?? [] as $entity) {
            if ($entity['name'] === 'Minion') {
                return $entity;
            }
        }

        return [];
    }

    /**
     * Name-bearing `*.NAME` keys of `po_string.json` that can name a trait,
     * by localized text (`byText`) and by key (`all`).
     *
     * @return array{all: array<string, true>, byText: array<string, list<string>>}
     */
    private function nameKeys(): array
    {
        $all = [];
        $byText = [];

        foreach ($this->export->json('po_string.json') as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group as $key => $text) {
                $key = (string) $key;

                if (! str_ends_with($key, '.NAME') || ! str_starts_with($key, self::NAME_KEY_PREFIXES[0]) && ! str_starts_with($key, self::NAME_KEY_PREFIXES[1])) {
                    continue;
                }

                $all[$key] = true;
                $byText[(string) $text][] = $key;
            }
        }

        return ['all' => $all, 'byText' => $byText];
    }

    /**
     * @param  array{all: array<string, true>, byText: array<string, list<string>>}  $keys
     */
    private function nameKey(string $traitId, string $name, array $keys): ?string
    {
        $direct = 'DUPLICANTS.TRAITS.'.mb_strtoupper($traitId).'.NAME';

        if (isset($keys['all'][$direct])) {
            return 'STRINGS.'.$direct;
        }

        $candidates = $keys['byText'][$name] ?? [];

        if ($candidates === []) {
            return null;
        }

        $score = static function (string $key) use ($traitId): int {
            $segments = explode('.', $key);

            return ($segments[count($segments) - 2] === mb_strtoupper($traitId) ? 2 : 0)
                + (str_starts_with($key, 'DUPLICANTS.TRAITS.') ? 1 : 0);
        };

        usort($candidates, static fn (string $a, string $b): int => $score($b) <=> $score($a));

        return 'STRINGS.'.$candidates[0];
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
