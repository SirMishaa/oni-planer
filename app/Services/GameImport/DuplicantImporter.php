<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\DuplicantBaseStat;
use App\Models\DuplicantTrait;
use App\Models\DuplicantTraitEffect;

final readonly class DuplicantImporter
{
    public function __construct(private StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array{base_stats: array<string, mixed>, traits: list<array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($jsonPath), true);

        DuplicantBaseStat::query()->create((array) $data['base_stats']);

        foreach ($data['traits'] as $raw) {
            $nameJson = is_string($raw['name_localization_id'] ?? null)
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['trait_id']])
                : ['en' => $raw['trait_id']];

            $trait = DuplicantTrait::query()->create([
                'trait_id' => $raw['trait_id'],
                'is_positive' => $raw['is_positive'],
                'name' => $nameJson,
                'dlc_id' => ($raw['dlc_id'] ?? '') ?: null,
            ]);

            /** @var list<array<string, mixed>> $effects */
            $effects = is_array($raw['effects'] ?? null) ? $raw['effects'] : [];
            foreach ($effects as $effect) {
                DuplicantTraitEffect::query()->create([
                    'duplicant_trait_id' => $trait->id,
                    'stat' => $effect['stat'],
                    'modifier' => $effect['modifier'],
                    'modifier_type' => $effect['modifier_type'],
                ]);
            }
        }
    }
}
