<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use App\Models\Element;
use App\Models\PrefabName;
use App\Services\GameImport\OniExport\ModCatalog;

/**
 * Records the STRINGS name key of every prefab id the UI displays: elements,
 * buildings, critter morphs and their eggs, plants, geysers, and the
 * non-element items critters eat or produce. Runs after the entity importers.
 */
final readonly class PrefabNameImporter
{
    public function __construct(private StringResolver $stringResolver, private ?ModCatalog $mods = null) {}

    /**
     * @param  array{buildings?: list<array<string, mixed>>, critters?: list<array<string, mixed>>, plants?: list<array<string, mixed>>, geysers?: list<array<string, mixed>>}  $recordsByDomain  reader records per domain
     */
    public function import(array $recordsByDomain): void
    {
        $buildings = $recordsByDomain['buildings'] ?? [];
        $critters = $recordsByDomain['critters'] ?? [];
        $plants = $recordsByDomain['plants'] ?? [];
        $geysers = $recordsByDomain['geysers'] ?? [];

        $keys = [
            ...$this->elementKeys(),
            ...$this->idToKey($buildings, 'building_id'),
            ...$this->critterKeys($critters),
            ...$this->idToKey($plants, 'plant_id'),
            ...$this->idToKey($geysers, 'geyser_id'),
        ];

        $modIds = [
            ...Element::query()->whereNotNull('mod_id')->pluck('mod_id', 'element_id')->all(),
            ...$this->recordModIds($buildings, 'building_id'),
            ...$this->recordModIds($critters, 'critter_id'),
            ...$this->recordModIds($plants, 'plant_id'),
            ...$this->recordModIds($geysers, 'geyser_id'),
        ];

        $keys = [...$keys, ...$this->referencedItemKeys($keys)];

        $rows = [];
        foreach ($keys as $prefabId => $nameKey) {
            if ($this->stringResolver->has($nameKey)) {
                $rows[] = [
                    'prefab_id' => $prefabId,
                    'name_key' => StringResolver::normalizeKey($nameKey),
                    'mod_id' => $modIds[$prefabId] ?? $this->mods?->modIdFor((string) $prefabId),
                ];
            }
        }

        PrefabName::query()->upsert($rows, ['prefab_id'], ['name_key', 'mod_id']);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, int>
     */
    private function recordModIds(array $entries, string $idField): array
    {
        $modIds = [];
        foreach ($entries as $raw) {
            if (is_string($raw[$idField] ?? null) && is_int($raw['mod_id'] ?? null)) {
                $modIds[$raw[$idField]] = $raw['mod_id'];
            }
        }

        return $modIds;
    }

    /** @return array<string, string> */
    private function elementKeys(): array
    {
        return Element::query()->pluck('element_id')
            ->mapWithKeys(fn (string $id): array => [$id => sprintf('STRINGS.ELEMENTS.%s.NAME', mb_strtoupper($id))])
            ->all();
    }

    /**
     * Morph name keys, plus each egg output named by the EGG_NAME sibling of
     * its layer's species key (a baby's key ends in .BABY.NAME).
     *
     * @param  list<array<string, mixed>>  $critters
     * @return array<string, string>
     */
    private function critterKeys(array $critters): array
    {
        $keys = [];
        foreach ($critters as $raw) {
            $nameKey = $raw['name_localization_id'] ?? null;
            if (! is_string($nameKey) || ! str_ends_with($nameKey, '.NAME')) {
                continue;
            }

            $keys[(string) $raw['critter_id']] = $nameKey;
            $speciesKey = preg_replace('/(\.BABY)?\.NAME$/', '', StringResolver::normalizeKey($nameKey));

            /** @var list<array<string, mixed>> $outputs */
            $outputs = is_array($raw['outputs'] ?? null) ? $raw['outputs'] : [];
            foreach ($outputs as $output) {
                if (($output['output_type'] ?? null) === 'egg') {
                    $keys[(string) $output['element_id']] = $speciesKey.'.EGG_NAME';
                }
            }
        }

        return $keys;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, string>
     */
    private function idToKey(array $entries, string $idField): array
    {
        $keys = [];
        foreach ($entries as $raw) {
            $nameKey = $raw['name_localization_id'] ?? null;
            $id = $raw[$idField] ?? null;
            if (is_string($nameKey) && is_string($id)) {
                $keys[$id] = $nameKey;
            }
        }

        return $keys;
    }

    /**
     * Ids eaten or produced by critters that no importer named: a known id
     * differing only by case (OilfloaterDecor vs OilFloaterDecor), a baby laid
     * directly, else an item (food, industrial product...) looked up by its
     * STRINGS segment.
     *
     * @param  array<string, string>  $known
     * @return array<string, string>
     */
    private function referencedItemKeys(array $known): array
    {
        $referenced = CritterMorphDiet::query()->pluck('consumed_element_id')
            ->merge(CritterMorphDiet::query()->whereNotNull('produced_element_id')->pluck('produced_element_id'))
            ->merge(CritterMorphOutput::query()->pluck('element_id'))
            ->unique()
            ->reject(fn (string $id): bool => isset($known[$id]));

        $knownByLowercase = [];
        foreach ($known as $prefabId => $nameKey) {
            $knownByLowercase[mb_strtolower($prefabId)] = $nameKey;
        }

        $keys = [];
        foreach ($referenced as $prefabId) {
            $nameKey = $knownByLowercase[mb_strtolower($prefabId)]
                ?? $this->babyKey($prefabId, $knownByLowercase)
                ?? $this->stringResolver->findNameKey($prefabId);
            if ($nameKey !== null) {
                $keys[$prefabId] = $nameKey;
            }
        }

        return $keys;
    }

    /**
     * A baby laid directly (e.g. DivergentWormBaby) is named by the BABY.NAME
     * sibling of its adult's key.
     *
     * @param  array<string, string>  $knownByLowercase
     */
    private function babyKey(string $prefabId, array $knownByLowercase): ?string
    {
        if (! str_ends_with($prefabId, 'Baby')) {
            return null;
        }

        $adultKey = $knownByLowercase[mb_strtolower(mb_substr($prefabId, 0, -4))] ?? null;

        return $adultKey !== null
            ? preg_replace('/\.NAME$/', '.BABY.NAME', StringResolver::normalizeKey($adultKey))
            : null;
    }
}
