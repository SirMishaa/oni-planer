<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

/**
 * Turns the rocket module defs of `building.json` (the root `rocketModuleMenu`)
 * into engine and module records for
 * {@see \App\Services\GameImport\RocketImporter::importRecords()}.
 *
 * A def with a `rocketEngineCluster` is an engine, every other one a module.
 * Engines take the fuel tag, exhaust temperature and the fuel per distance of
 * `rocketModulePerformance`. `oxidizer_element_id` stays null (the export only
 * says whether an oxidizer is required, not which one), as do `max_range` (0)
 * and `oxidizer_consumption_rate`, which have no source. Modules take the
 * cargo bay storage type (else an id rule), the summed build mass, the cargo
 * or storage capacity and the energy consumer wattage.
 *
 * Defs owned by an unsupported mod are skipped, and a fuel owned by one is
 * dropped (counted in {@see self::skippedReferences()}). The database has no
 * `mod_id` for rockets. Whether a fuel exists as an element is the importer's
 * concern.
 *
 * @phpstan-type EngineRecord array{
 *     engine_id: string,
 *     fuel_element_id: string|null,
 *     oxidizer_element_id: null,
 *     max_range: float,
 *     fuel_consumption_rate: float,
 *     oxidizer_consumption_rate: null,
 *     exhaust_temperature: float,
 *     name_localization_id: string,
 *     dlc_id: string|null,
 * }
 * @phpstan-type ModuleRecord array{
 *     module_id: string,
 *     module_type: 'cargo_solid'|'cargo_liquid'|'cargo_gas'|'command'|'nosecone'|'oxidizer'|'fuel'|'utility',
 *     mass: float,
 *     capacity: float,
 *     power_consumption: float|null,
 *     name_localization_id: string,
 *     dlc_id: string|null,
 * }
 */
final class RocketReader
{
    /** `cargoBayCluster.storageType` → module type. */
    private const array CARGO_TYPES = [0 => 'cargo_solid', 1 => 'cargo_liquid', 2 => 'cargo_gas'];

    private int $skippedReferences = 0;

    public function __construct(private readonly OniExport $export, private readonly ModCatalog $catalog) {}

    /** @return array{engines: list<EngineRecord>, modules: list<ModuleRecord>} */
    public function records(): array
    {
        $this->skippedReferences = 0;

        $file = $this->export->json('building.json');
        $moduleIds = array_flip($file['rocketModuleMenu'] ?? []);

        $engines = [];
        $modules = [];

        foreach ($file['bBuildingDefList'] as $def) {
            $buildingId = (string) $def['name'];

            if (! isset($moduleIds[$buildingId]) || ($def['deprecated'] ?? false) || ($def['debugOnly'] ?? false) || $this->catalog->isSkipped($buildingId)) {
                continue;
            }

            if (($def['rocketEngineCluster'] ?? null) !== null) {
                $engines[] = $this->engine($def);
            } else {
                $modules[] = $this->module($def);
            }
        }

        return ['engines' => $engines, 'modules' => $modules];
    }

    /** Number of fuels dropped because they belong to an unsupported mod. */
    public function skippedReferences(): int
    {
        return $this->skippedReferences;
    }

    /**
     * @param  array<string, mixed>  $def
     * @return EngineRecord
     */
    private function engine(array $def): array
    {
        $engineId = (string) $def['name'];
        $engine = $def['rocketEngineCluster'];
        $fuel = $engine['fuelTag']['Name'] ?? null;

        if (is_string($fuel) && $this->catalog->isSkipped($fuel)) {
            $this->skippedReferences++;
            $fuel = null;
        }

        return [
            'engine_id' => $engineId,
            'fuel_element_id' => is_string($fuel) && $fuel !== '' ? $fuel : null,
            'oxidizer_element_id' => null,
            'max_range' => 0.0,
            'fuel_consumption_rate' => $this->round((float) ($def['rocketModulePerformance']['fuelKilogramPerDistance'] ?? 0)),
            'oxidizer_consumption_rate' => null,
            'exhaust_temperature' => (float) ($engine['exhaustTemperature'] ?? 0),
            'name_localization_id' => $this->nameKey($engineId),
            'dlc_id' => $this->dlcId($def),
        ];
    }

    /**
     * @param  array<string, mixed>  $def
     * @return ModuleRecord
     */
    private function module(array $def): array
    {
        $moduleId = (string) $def['name'];
        $wattage = $def['energyConsumer']['baseWattageRating'] ?? null;

        return [
            'module_id' => $moduleId,
            'module_type' => $this->moduleType($moduleId, $def['cargoBayCluster']['storageType'] ?? null),
            'mass' => (float) array_sum($def['materialMass'] ?? []),
            'capacity' => (float) ($def['cargoBayCluster']['MaxCapacity'] ?? $def['storage']['capacityKg'] ?? 0),
            'power_consumption' => $wattage !== null && $wattage > 0 ? (float) $wattage : null,
            'name_localization_id' => $this->nameKey($moduleId),
            'dlc_id' => $this->dlcId($def),
        ];
    }

    /**
     * The cargo bay storage type decides the cargo bays; the rest is told apart
     * by id, anything unrecognized being a utility module.
     *
     * @return 'cargo_solid'|'cargo_liquid'|'cargo_gas'|'command'|'nosecone'|'oxidizer'|'fuel'|'utility'
     */
    private function moduleType(string $moduleId, mixed $storageType): string
    {
        if (is_int($storageType) && isset(self::CARGO_TYPES[$storageType])) {
            return self::CARGO_TYPES[$storageType];
        }

        return match (true) {
            str_contains($moduleId, 'Nosecone') => 'nosecone',
            str_contains($moduleId, 'Oxidizer') => 'oxidizer',
            str_contains($moduleId, 'FuelTank') => 'fuel',
            str_contains($moduleId, 'Pilot') => 'command',
            default => 'utility',
        };
    }

    private function nameKey(string $buildingId): string
    {
        return 'STRINGS.BUILDINGS.PREFABS.'.mb_strtoupper($buildingId).'.NAME';
    }

    /** @param array<string, mixed> $def */
    private function dlcId(array $def): ?string
    {
        foreach ($def['kPrefabID']['requiredDlcIds'] ?? [] as $dlcId) {
            if ($dlcId !== '') {
                return (string) $dlcId;
            }
        }

        return null;
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
