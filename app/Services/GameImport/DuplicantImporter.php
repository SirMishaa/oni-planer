<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\DuplicantBaseStat;
use App\Models\DuplicantTrait;
use App\Models\DuplicantTraitEffect;

final class DuplicantImporter
{
    public function __construct(private readonly StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(file_get_contents($jsonPath), true);

        DuplicantBaseStat::create($data['base_stats']);

        foreach ($data['traits'] ?? [] as $raw) {
            $nameJson = isset($raw['name_localization_id'])
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['trait_id']])
                : ['en' => $raw['trait_id']];

            $trait = DuplicantTrait::create([
                'trait_id' => $raw['trait_id'],
                'is_positive' => $raw['is_positive'],
                'name' => $nameJson,
                'dlc_id' => ($raw['dlc_id'] ?? '') ?: null,
            ]);

            foreach ($raw['effects'] ?? [] as $effect) {
                DuplicantTraitEffect::create([
                    'duplicant_trait_id' => $trait->id,
                    'stat' => $effect['stat'],
                    'modifier' => $effect['modifier'],
                    'modifier_type' => $effect['modifier_type'],
                ]);
            }
        }
    }
}
