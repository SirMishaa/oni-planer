<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Element;
use App\Models\GeyserType;

final class GeyserImporter
{
    private int $skippedGeysers = 0;

    public function __construct(private readonly StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $geysers */
        $geysers = json_decode((string) file_get_contents($jsonPath), true);

        $this->importRecords($geysers);
    }

    /**
     * Persists geyser records (reader record shape, optionally with `mod_id`).
     *
     * Geysers whose element is not an imported element are skipped.
     *
     * @param  array<int, array<string, mixed>>  $geysers
     */
    public function importRecords(array $geysers): void
    {
        $this->skippedGeysers = 0;
        $validElementIds = Element::query()->pluck('element_id', 'element_id')->all();

        foreach ($geysers as $raw) {
            if (! isset($validElementIds[$raw['element_id']])) {
                $this->skippedGeysers++;

                continue;
            }

            $nameJson = is_string($raw['name_localization_id'] ?? null)
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['geyser_id']])
                : ['en' => $raw['geyser_id']];

            GeyserType::query()->create([
                'geyser_id' => $raw['geyser_id'],
                'type' => $raw['type'],
                'element_id' => $raw['element_id'],
                'temperature' => $raw['temperature'],
                'max_pressure' => $raw['max_pressure'],
                'min_yield_rate' => $raw['min_yield_rate'],
                'max_yield_rate' => $raw['max_yield_rate'],
                'min_eruption_duration' => $raw['min_eruption_duration'],
                'max_eruption_duration' => $raw['max_eruption_duration'],
                'min_eruption_period' => $raw['min_eruption_period'],
                'max_eruption_period' => $raw['max_eruption_period'],
                'dormancy_min_cycles' => $raw['dormancy_min_cycles'],
                'dormancy_max_cycles' => $raw['dormancy_max_cycles'],
                'name' => $nameJson,
                'dlc_id' => ($raw['dlc_id'] ?? '') ?: null,
                'mod_id' => $raw['mod_id'] ?? null,
            ]);
        }
    }

    /** Number of geysers skipped because their element is not an imported element. */
    public function skippedGeysers(): int
    {
        return $this->skippedGeysers;
    }
}
