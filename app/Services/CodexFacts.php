<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CodexEntry;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Services\GameImport\PoFile;

/**
 * @phpstan-type FactPart array{text: string, sprite: ?string}
 * @phpstan-type FactBlock array{type: string, style: string, text: string, parts?: list<FactPart>}
 * @phpstan-type FactContainer array{layout: string, lock_id: null, section_title: string, section_sprite?: string, blocks: list<FactBlock>}
 */
final readonly class CodexFacts
{
    public function __construct(private GameText $text) {}

    /** @return list<FactContainer> */
    public function containers(CodexEntry $entry): array
    {
        $containers = [];
        $consumerIds = [...$entry->critters->pluck('critter_id'), ...$entry->plants->pluck('plant_id')];
        $consumers = $consumerIds === [] ? collect() : CritterMorphDiet::query()
            ->join('critter_morphs', 'critter_morphs.id', '=', 'critter_morph_diets.critter_morph_id')
            ->whereIn('consumed_element_id', $consumerIds)->where('critter_morphs.is_base', true)
            ->select(['consumed_element_id', 'morph_id'])->distinct()->orderBy('morph_id')->toBase()->get()
            ->groupBy('consumed_element_id');

        foreach ($entry->critters->sortBy('critter_id') as $critter) {
            $morph = $critter->baseMorph;
            if (! $morph instanceof CritterMorph) {
                continue;
            }
            $blocks = [];
            if ($morph->diets->isNotEmpty()) {
                $blocks[] = $this->block('Régime', 'Subtitle');
                foreach ($morph->diets as $diet) {
                    $blocks[] = $this->diet($diet);
                }
            }
            if (! empty($morph->codex_data['butcher_drops'])) {
                $blocks[] = $this->block('Produits de découpe', 'Subtitle');
                foreach ($morph->codex_data['butcher_drops'] as $drop) {
                    $amounts = [];
                    if (isset($drop['mass_kg'])) {
                        $amounts[] = $this->number($drop['mass_kg']).' kg';
                    }
                    if (isset($drop['kcal'])) {
                        $amounts[] = $this->number($drop['kcal']).' kcal';
                    }
                    $blocks[] = $this->illustrated($this->part(
                        $this->name($drop['id']).' : '.($amounts === [] ? 'quantité non renseignée' : implode(', ', $amounts).' par découpe'),
                        $drop['id'], 'foods', 'items', 'elements',
                    ));
                }
            }
            foreach ($morph->codex_data['conversions'] ?? [] as $conversion) {
                $blocks[] = $this->block('Conversion (débit nominal) : '.$this->rates($conversion['inputs']).' → '.$this->rates($conversion['outputs']).($conversion['unresolved'] ? ' (données partielles)' : ''));
            }
            $blocks = [...$blocks, ...$this->applications($consumers->get($morph->morph_id)?->pluck('morph_id')->all() ?? [])];
            if ($blocks !== []) {
                $containers[] = $this->container(
                    $this->name($morph->morph_id, $morph->getTranslation('name', 'en')).' ('.$morph->morph_id.')',
                    $blocks,
                    $this->sprite($morph->morph_id, 'critters'),
                );
            }
        }

        foreach ($entry->plants as $plant) {
            $variant = $plant->baseVariant;
            if ($variant === null) {
                continue;
            }
            $blocks = [];
            if ($variant->growth_time > 0) {
                $blocks[] = $this->block('Croissance : '.$this->number($variant->growth_time).' cycles');
            }
            if (! empty($variant->codex_data['safe_media'])) {
                $blocks[] = $this->block('Milieux compatibles : '.implode(', ', array_map($this->name(...), $variant->codex_data['safe_media'])));
            }
            foreach ($variant->inputs as $input) {
                $label = $input->input_type === 'irrigation' ? 'Irrigation' : 'Fertilisation';
                $blocks[] = $this->block($label.' : '.$this->name($input->element_id).' '.$this->number($input->amount_per_cycle).' kg/cycle');
            }
            $data = $variant->codex_data;
            foreach ($data['crops'] ?? (isset($data['crop']) ? [$data['crop']] : []) as $crop) {
                $unit = ($crop['unit'] ?? null) === 'units' ? 'unités' : ($crop['unit'] ?? null);
                $amount = $unit === null || ! isset($crop['amount']) ? 'quantité non renseignée' : $this->number($crop['amount']).' '.$unit.'/récolte';
                $blocks[] = $this->block('Récolte : '.$this->name($crop['id']).' '.$amount);
            }
            $blocks = [...$blocks, ...$this->applications($consumers->get($plant->plant_id)?->pluck('morph_id')->all() ?? [])];
            if ($blocks !== []) {
                $containers[] = $this->container($this->name($plant->plant_id, $plant->getTranslation('name', 'en')), $blocks);
            }
        }

        foreach ($entry->buildings as $building) {
            $blocks = [];
            foreach ([
                $this->text->description($building->building_id) ?? $building->getTranslation('description', 'en'),
                $this->text->effect($building->building_id) ?? $building->getTranslation('effect', 'en'),
            ] as $description) {
                if (is_string($description) && $description !== '') {
                    $blocks[] = $this->block(PoFile::stripMarkup($description));
                }
            }
            $data = $building->codex_data;
            if (isset($data['room'])) {
                $blocks[] = $this->block('Descripteur de pièce : '.$data['room']['id'].' ('.$data['room']['mode'].')');
            }
            if (isset($data['tinker'])) {
                $tinker = $data['tinker'];
                $blocks[] = $this->block(sprintf(
                    'Opération manuelle : %s %s kg par utilisation → 1 %s',
                    $this->name($tinker['input_id']), $this->number($tinker['input_kg']), $this->name($tinker['output_id']),
                ));
                if (isset($tinker['skill_perk'])) {
                    $blocks[] = $this->block('Compétence requise : '.$tinker['skill_perk']);
                }
            }
            foreach ($data['manual_conversions'] ?? [] as $conversion) {
                $input = isset($conversion['input_id']) ? $this->name($conversion['input_id']) : null;
                $output = isset($conversion['output_id']) ? $this->name($conversion['output_id']) : null;
                $label = PoFile::stripMarkup($conversion['label'] ?? '');
                $summary = implode(' → ', array_filter([$input, $output]));
                if ($label !== '' || $summary !== '') {
                    $blocks[] = $this->block(implode(' : ', array_filter([$label, $summary])));
                }
            }
            if ($blocks !== []) {
                $containers[] = $this->container($this->name($building->building_id, $building->getTranslation('name', 'en')), $blocks);
            }
        }

        return $containers;
    }

    /**
     * @param  list<string>  $consumers
     * @return list<FactBlock>
     */
    private function applications(array $consumers): array
    {
        return $consumers === [] ? [] : [
            $this->block('Applications', 'Subtitle'),
            ...array_map(fn (string $id): array => $this->illustrated(
                $this->part('Consommé par '),
                $this->part($this->name($id), $id, 'critters'),
            ), $consumers),
        ];
    }

    /** @return FactBlock */
    private function diet(CritterMorphDiet $diet): array
    {
        $data = $diet->codex_data;
        $unit = match ($data['unit'] ?? null) {
            'percent_growth_per_cycle' => '% de croissance/cycle',
            'prey_per_cycle' => 'proie(s)/cycle',
            'units_per_cycle' => 'unités/cycle',
            default => null,
        };
        $amount = $unit !== null
            ? $this->number($data['amount']).' '.$unit
            : ($diet->food_kind === 'item' ? $this->number($diet->amount_per_cycle).' kg/cycle' : 'taux non renseigné');
        $input = $diet->consumed_element_id;
        $folders = match ($diet->food_kind) {
            'plant' => ['plants'],
            'prey' => ['critters'],
            default => ['elements', 'foods', 'items', 'plants'],
        };
        $consumed = $this->part($this->name($input).' '.$amount, $input, ...$folders);
        $output = $diet->produced_element_id;
        if ($output === null) {
            return $this->illustrated($consumed);
        }
        $outputMass = $data['output_kg_per_cycle'] ?? ($diet->food_kind === 'item' && $diet->conversion_ratio !== null ? $diet->amount_per_cycle * $diet->conversion_ratio : null);

        return $this->illustrated(
            $consumed,
            $this->part(' → '),
            $this->part($this->name($output).($outputMass === null ? '' : ' '.$this->number($outputMass).' kg/cycle'), $output, 'elements', 'foods', 'items'),
        );
    }

    /** @param array<string, float> $rates */
    private function rates(array $rates): string
    {
        $parts = [];
        foreach ($rates as $id => $rate) {
            $parts[] = $this->name($id).' '.$this->number($rate).' kg/s';
        }

        return $parts === [] ? 'non renseigné' : implode(', ', $parts);
    }

    private function name(string $id, ?string $fallback = null): string
    {
        return $this->text->name($id, $fallback === '' ? null : $fallback);
    }

    private function number(float $amount): string
    {
        return mb_rtrim(mb_rtrim(number_format($amount, 4, ',', ' '), '0'), ',');
    }

    /** @return FactPart */
    private function part(string $text, ?string $id = null, string ...$folders): array
    {
        return ['text' => $text, 'sprite' => $this->sprite($id, ...$folders)];
    }

    private function sprite(?string $id, string ...$folders): ?string
    {
        if ($id !== null && $id !== '' && basename($id) === $id) {
            foreach ($folders as $folder) {
                $relative = $folder.'/'.$id.'.webp';
                if (is_file(config()->string('game.assets_path').'/'.$relative)) {
                    return '/assets/game/'.$folder.'/'.rawurlencode($id).'.webp';
                }
            }
        }

        return null;
    }

    /** @param FactPart ...$parts
     * @return FactBlock
     */
    private function illustrated(array ...$parts): array
    {
        return $this->block(implode('', array_column($parts, 'text'))) + ['parts' => $parts];
    }

    /** @return FactBlock */
    private function block(string $text, string $style = 'Body'): array
    {
        return ['type' => 'text', 'style' => $style, 'text' => $text];
    }

    /**
     * @param  list<FactBlock>  $blocks
     * @return FactContainer
     */
    private function container(string $title, array $blocks, ?string $sprite = null): array
    {
        return [
            'layout' => 'Vertical',
            'lock_id' => null,
            'section_title' => $title,
            ...($sprite === null ? [] : ['section_sprite' => $sprite]),
            'blocks' => $blocks,
        ];
    }
}
