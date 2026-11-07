<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

/**
 * Turns `database/geyser.json` into geyser records for
 * {@see \App\Services\GameImport\GeyserImporter::importRecords()}.
 *
 * Ids are the `geyserType.id`. The element comes from the `SimHashes` map of
 * `tags.json` (reversed, then the mod elements of `planner_extras.json`), the eruption duration is the iteration percent and
 * the eruption period the iteration length. `dormancy_*_cycles` keep the year
 * length in seconds, as the database has always stored them. The export has no
 * owner for geysers, so the mod is the supported mod whose `.pot` declares the
 * geyser's name key.
 *
 * Geysers owned by an unsupported mod are skipped.
 *
 * @phpstan-type GeyserRecord array{
 *     geyser_id: string,
 *     type: 'geyser'|'volcano'|'vent',
 *     element_id: string,
 *     temperature: float,
 *     max_pressure: float,
 *     min_yield_rate: float,
 *     max_yield_rate: float,
 *     min_eruption_duration: float,
 *     max_eruption_duration: float,
 *     min_eruption_period: float,
 *     max_eruption_period: float,
 *     dormancy_min_cycles: float,
 *     dormancy_max_cycles: float,
 *     name_localization_id: string|null,
 *     dlc_id: string|null,
 *     mod_id: int|null,
 *     kanim: string|null,
 * }
 */
final readonly class GeyserReader
{
    public function __construct(private OniExport $export, private ModCatalog $catalog) {}

    /** @return list<GeyserRecord> */
    public function records(): array
    {
        $elementIds = $this->elementIdsByHash();

        $records = [];

        foreach ($this->export->json('geyser.json')['geysers'] ?? [] as $geyser) {
            $geyserType = $geyser['geyserType'];
            $geyserId = (string) $geyserType['id'];
            $elementId = $elementIds[$geyserType['element']] ?? null;

            if ($this->catalog->isSkipped((string) $geyser['id']) || $elementId === null) {
                continue;
            }

            $nameKey = $geyser['nameStringKey']['String'] ?? null;

            $records[] = [
                'geyser_id' => $geyserId,
                'type' => $this->type($geyserId),
                'element_id' => (string) $elementId,
                'temperature' => (float) $geyserType['temperature'],
                'max_pressure' => (float) $geyserType['maxPressure'],
                'min_yield_rate' => (float) $geyserType['minRatePerCycle'],
                'max_yield_rate' => (float) $geyserType['maxRatePerCycle'],
                'min_eruption_duration' => (float) $geyserType['minIterationPercent'],
                'max_eruption_duration' => (float) $geyserType['maxIterationPercent'],
                'min_eruption_period' => (float) $geyserType['minIterationLength'],
                'max_eruption_period' => (float) $geyserType['maxIterationLength'],
                'dormancy_min_cycles' => (float) $geyserType['minYearLength'],
                'dormancy_max_cycles' => (float) $geyserType['maxYearLength'],
                'name_localization_id' => is_string($nameKey) ? $nameKey : null,
                'dlc_id' => $geyserType['requiredDlcIds'][0] ?? $geyserType['DlcID'] ?? null,
                'mod_id' => is_string($nameKey) ? $this->catalog->modIdForStringKey($nameKey) : null,
                'kanim' => $geyser['anim'] ?? null,
            ];
        }

        return $records;
    }

    /**
     * Element ids by SimHash. The game enum covers the base elements; elements
     * added by a mod only appear in the extension's `elements` (`hash`).
     *
     * @return array<int, string>
     */
    private function elementIdsByHash(): array
    {
        $elementIds = [];

        foreach ($this->export->json('planner_extras.json')['elements'] ?? [] as $elementId => $element) {
            $elementIds[(int) $element['hash']] = (string) $elementId;
        }

        foreach ($this->export->json('tags.json')['SimHashes'] ?? [] as $elementId => $hash) {
            $elementIds[(int) $hash] = (string) $elementId;
        }

        return $elementIds;
    }

    /** @return 'geyser'|'volcano'|'vent' */
    private function type(string $geyserId): string
    {
        $lowerId = mb_strtolower($geyserId);

        return match (true) {
            str_contains($lowerId, 'volcano'), str_starts_with($lowerId, 'molten_') => 'volcano',
            str_contains($lowerId, 'vent') => 'vent',
            default => 'geyser',
        };
    }
}
