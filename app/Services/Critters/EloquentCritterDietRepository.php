<?php

declare(strict_types=1);

namespace App\Services\Critters;

use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;

/** Reads `critter_morph_diets` and `critter_morph_outputs` once per morph and keeps them for the request. */
final class EloquentCritterDietRepository implements CritterDietRepository
{
    /** @var array<string, array{diets: list<array{resource: string, kgPerCycle: float, produces: string|null, conversionRatio: float|null, kind: 'item'|'plant'|'prey'}>, outputs: list<array{resource: string, perCycle: float, kind: string}>}> */
    private array $morphs = [];

    public function diets(string $morphId): array
    {
        return $this->load($morphId)['diets'];
    }

    public function outputs(string $morphId): array
    {
        return $this->load($morphId)['outputs'];
    }

    /**
     * @return array{diets: list<array{resource: string, kgPerCycle: float, produces: string|null, conversionRatio: float|null, kind: 'item'|'plant'|'prey'}>, outputs: list<array{resource: string, perCycle: float, kind: string}>}
     */
    private function load(string $morphId): array
    {
        if (isset($this->morphs[$morphId])) {
            return $this->morphs[$morphId];
        }

        $morph = CritterMorph::query()
            ->where('morph_id', $morphId)
            ->with([
                'diets' => fn ($query) => $query->orderBy('id'),
                'outputs' => fn ($query) => $query->orderBy('id'),
            ])
            ->first(['id', 'morph_id']);

        return $this->morphs[$morphId] = [
            'diets' => $morph?->diets->map(static fn (CritterMorphDiet $diet): array => [
                'resource' => $diet->consumed_element_id,
                'kgPerCycle' => (float) $diet->amount_per_cycle,
                'produces' => $diet->produced_element_id,
                'conversionRatio' => $diet->conversion_ratio !== null ? (float) $diet->conversion_ratio : null,
                'kind' => $diet->food_kind,
            ])->values()->all() ?? [],
            'outputs' => $morph?->outputs->map(static fn (CritterMorphOutput $output): array => [
                'resource' => $output->element_id,
                'perCycle' => (float) $output->amount_per_cycle,
                'kind' => $output->output_type,
            ])->values()->all() ?? [],
        ];
    }
}
