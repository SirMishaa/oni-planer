<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Building;
use App\Models\CodexEntry;
use App\Models\Critter;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use App\Models\Element;
use App\Models\Food;
use App\Models\Plant;
use App\Services\Mcp\GameObjectSearch;
use App\Services\Mcp\McpFormat;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('lookup')]
#[Description('Find any game object (element, building, critter, plant, food, codex entry) by id or name, French or English, accents optional. Several matches: a short list. One match: its key data (state and phase changes, size and materials and power, morphs and diets and outputs, growth needs, kcal...) and related ids for the next lookup. image: true adds its sprite (WebP).')]
#[IsReadOnly]
final class LookupTool extends Tool
{
    private const float KELVIN = 273.15;

    /** @var array<string, string> sprite folder under game.assets_path per kind */
    private const array SPRITE_FOLDERS = [
        'element' => 'elements',
        'building' => 'buildings',
        'critter' => 'critters',
        'plant' => 'plants',
        'food' => 'foods',
    ];

    public function handle(Request $request, GameObjectSearch $search): Response|ResponseFactory
    {
        $request->validate([
            'query' => ['required', 'string'],
            'kind' => ['nullable', 'string', 'in:'.implode(',', GameObjectSearch::KINDS)],
            'image' => ['nullable', 'boolean'],
        ]);

        $kind = $request->get('kind');
        $matches = $search->search((string) $request->get('query'), is_string($kind) ? $kind : null);

        if ($matches === []) {
            return Response::error(sprintf('No game object matches "%s"%s.', $request->get('query'), is_string($kind) ? ' among '.$kind.'s' : ''));
        }

        if (count($matches) > 1) {
            return Response::text(implode("\n", [
                sprintf('%d matches%s (narrow with an exact id or kind):', count($matches), count($matches) === GameObjectSearch::MAX_RESULTS ? ', first '.GameObjectSearch::MAX_RESULTS : ''),
                ...array_map(fn (array $match): string => sprintf(' %s %s · %s / %s', $match['kind'], $match['id'], $match['nameFr'], $match['nameEn']), $matches),
            ]));
        }

        $match = $matches[0];
        $sprite = isset(self::SPRITE_FOLDERS[$match['kind']]) ? sprintf('%s/%s/%s.webp', config()->string('game.assets_path'), self::SPRITE_FOLDERS[$match['kind']], $match['id']) : null;
        $hasSprite = $sprite !== null && is_file($sprite);

        $lines = [
            sprintf('%s %s / %s (%s)', $match['kind'], $match['nameFr'], $match['nameEn'], $match['id']),
            ...match ($match['kind']) {
                'element' => $this->element($match['id']),
                'building' => $this->building($match['id']),
                'critter' => $this->critter($match['id']),
                'plant' => $this->plant($match['id']),
                'food' => $this->food($match['id']),
                default => $this->codex($match['id']),
            },
            $sprite === null ? 'sprite: none for this kind' : ($hasSprite ? 'sprite available (image: true)' : 'sprite missing'),
        ];
        $text = Response::text(implode("\n", $lines));

        if ($hasSprite && $request->get('image') === true) {
            return Response::make([$text, Response::image((string) file_get_contents($sprite), 'image/webp')]);
        }

        return $text;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Id (Oxygen, FarmStation, Hatch…) or name in French or English.')->required(),
            'kind' => $schema->string()->enum(GameObjectSearch::KINDS)->description('Restrict the search to one kind.'),
            'image' => $schema->boolean()->description('Add the sprite as a WebP image; costs tokens.'),
        ];
    }

    /** @return list<string> */
    private function element(string $id): array
    {
        $element = Element::query()->with('thermalProperties')->where('element_id', $id)->first();
        if (! $element instanceof Element) {
            return ['details unavailable'];
        }

        $thermal = $element->thermalProperties;
        $lines = [sprintf('state %s · category %s · molar mass %s', $element->state, $element->material_category ?? 'unavailable', $element->molar_mass ?? 'unavailable')];
        if ($thermal !== null) {
            $lines[] = sprintf('specific heat %s · conductivity %s', $thermal->specific_heat_capacity ?? '?', $thermal->thermal_conductivity ?? '?');
            if ($element->low_temp_transition_target !== null && $thermal->low_temp !== null) {
                $lines[] = sprintf('below %s → %s', McpFormat::celsius($thermal->low_temp - self::KELVIN), $element->low_temp_transition_target);
            }
            if ($element->high_temp_transition_target !== null && $thermal->high_temp !== null) {
                $lines[] = sprintf('above %s → %s', McpFormat::celsius($thermal->high_temp - self::KELVIN), $element->high_temp_transition_target);
            }
        }
        if (is_array($element->tags) && $element->tags !== []) {
            $lines[] = 'tags: '.implode(', ', $element->tags);
        }

        return $lines;
    }

    /** @return list<string> */
    private function building(string $id): array
    {
        $building = Building::query()->with('constructionMaterials')->where('building_id', $id)->first();
        if (! $building instanceof Building) {
            return ['details unavailable'];
        }

        return [
            sprintf('category %s · size %dx%d · build time %s s', $building->category, $building->width, $building->height, $building->construction_time ?? '?'),
            sprintf('power %s · generates %s · heat %s', $building->power_consumption === null ? 'none' : $building->power_consumption.' W', $building->power_generation === null ? 'none' : $building->power_generation.' W', $building->heat_generation ?? 'unavailable'),
            'materials: '.($building->constructionMaterials->isEmpty() ? 'unavailable' : $building->constructionMaterials->map(
                fn ($material): string => sprintf('%s %s kg', $material->element_id ?? $material->material_category, McpFormat::number((float) $material->amount)),
            )->implode(', ')),
        ];
    }

    /** @return list<string> */
    private function critter(string $id): array
    {
        $critter = Critter::query()->where('critter_id', $id)->first();
        if (! $critter instanceof Critter) {
            return ['details unavailable'];
        }

        $lines = [];
        $morphs = $critter->morphs()->with(['diets', 'outputs'])->orderByDesc('is_base')->orderBy('morph_id')->get();
        foreach ($morphs as $morph) {
            /** @var \App\Models\CritterMorph $morph */
            $lines[] = sprintf(
                'morph %s%s · %s..%s · %s kcal/cycle · lifespan %s cycles · overcrowding %s',
                $morph->morph_id,
                $morph->is_base ? ' (base)' : '',
                $morph->min_temp === null ? '?' : McpFormat::celsius($morph->min_temp - self::KELVIN),
                $morph->max_temp === null ? '?' : McpFormat::celsius($morph->max_temp - self::KELVIN),
                $morph->calories_per_cycle === null ? '?' : McpFormat::number($morph->calories_per_cycle / 1000),
                $morph->lifespan ?? '?',
                $morph->overcrowding_threshold ?? '?',
            );
            foreach ($morph->diets as $diet) {
                /** @var CritterMorphDiet $diet */
                $lines[] = sprintf('  eats %s %s kg/cycle → %s ×%s', $diet->consumed_element_id, McpFormat::number((float) $diet->amount_per_cycle), $diet->produced_element_id ?? 'nothing', McpFormat::number((float) $diet->conversion_ratio, 2));
            }
            foreach ($morph->outputs as $output) {
                /** @var CritterMorphOutput $output */
                $lines[] = sprintf('  %s %s %s/cycle', $output->output_type, $output->element_id, McpFormat::number((float) $output->amount_per_cycle, 4));
            }
        }

        return $lines === [] ? ['morphs unavailable'] : $lines;
    }

    /** @return list<string> */
    private function plant(string $id): array
    {
        $plant = Plant::query()->where('plant_id', $id)->first();
        if (! $plant instanceof Plant) {
            return ['details unavailable'];
        }

        $lines = [];
        $variants = $plant->variants()->with(['inputs', 'outputs'])->orderByDesc('is_base')->get();
        foreach ($variants as $variant) {
            $lines[] = sprintf(
                'variant %s%s · %s..%s · pressure %s..%s kg · atmosphere %s · light %s · growth %s s',
                $variant->variant_id,
                $variant->is_base ? ' (base)' : '',
                $variant->min_temp === null ? '?' : McpFormat::celsius($variant->min_temp - self::KELVIN),
                $variant->max_temp === null ? '?' : McpFormat::celsius($variant->max_temp - self::KELVIN),
                $variant->min_pressure ?? '?',
                $variant->max_pressure ?? '?',
                $variant->atmosphere_element_id ?? 'any',
                $variant->light_required ? 'required' : 'not required',
                $variant->growth_time ?? '?',
            );
            foreach ($variant->inputs as $input) {
                $lines[] = sprintf('  needs %s %s %s/cycle', $input->input_type, $input->element_id, McpFormat::number((float) $input->amount_per_cycle, 2));
            }
            foreach ($variant->outputs as $output) {
                $lines[] = sprintf('  gives %s %s %s/harvest', $output->output_type, $output->element_id, McpFormat::number((float) $output->amount_per_harvest, 2));
            }
        }

        return $lines === [] ? ['variants unavailable'] : $lines;
    }

    /** @return list<string> */
    private function food(string $id): array
    {
        $food = Food::query()->where('food_id', $id)->first();

        return $food instanceof Food
            ? [sprintf('%s kcal per unit · quality %d · %s', McpFormat::number($food->calories_per_unit / 1000), $food->quality, $food->can_rot ? 'rots' : 'does not rot')]
            : ['details unavailable'];
    }

    /** @return list<string> */
    private function codex(string $id): array
    {
        $entry = CodexEntry::query()->where('codex_id', $id)->first(['codex_id', 'category', 'parent_codex_id']);

        return $entry instanceof CodexEntry
            ? [sprintf('category %s · parent %s', $entry->category, $entry->parent_codex_id ?? 'none')]
            : ['details unavailable'];
    }
}
