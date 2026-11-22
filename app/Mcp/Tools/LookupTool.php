<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Building;
use App\Models\CodexEntry;
use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use App\Models\Element;
use App\Models\Food;
use App\Models\Plant;
use App\Models\RecipeItem;
use App\Services\GameImport\PoFile;
use App\Services\GameText;
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
#[Description('Find a game object (element, building, critter, plant, food, codex entry) by id or French or English name. Several matches: a short list. One match: its key data. details: true adds available descriptions, Codex text and linked entity ids. image: true adds its sprite (WebP).')]
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
            'details' => ['nullable', 'boolean'],
        ]);

        $kind = $request->get('kind');
        ['matches' => $matches, 'total' => $total] = $search->search((string) $request->get('query'), is_string($kind) ? $kind : null);

        if ($matches === []) {
            return Response::error(sprintf('No game object matches "%s"%s.', $request->get('query'), is_string($kind) ? ' among '.$kind.'s' : ''));
        }

        if (count($matches) > 1) {
            $exact = self::isExact((string) $request->get('query'), $matches);

            return Response::text(implode("\n", [
                $exact
                    ? sprintf('%d exact matches of different kinds: pass kind to pick one.', count($matches))
                    : sprintf('%d matches%s (narrow with an exact id or kind):', $total, $total > count($matches) ? ', first '.count($matches) : ''),
                ...array_map(fn (array $match): string => sprintf(' %s %s · %s / %s', $match['kind'], $match['id'], $match['nameFr'], $match['nameEn']), $matches),
            ]));
        }

        $match = $matches[0];
        $sprite = isset(self::SPRITE_FOLDERS[$match['kind']]) ? sprintf('%s/%s/%s.webp', config()->string('game.assets_path'), self::SPRITE_FOLDERS[$match['kind']], $match['id']) : null;
        $hasSprite = $sprite !== null && is_file($sprite);
        $details = $request->get('details') === true;

        $lines = [
            sprintf('%s %s / %s (%s)', $match['kind'], $match['nameFr'], $match['nameEn'], $match['id']),
            ...match ($match['kind']) {
                'element' => $this->element($match['id']),
                'building' => $this->building($match['id'], $details),
                'critter' => $this->critter($match['id'], $details),
                'plant' => $this->plant($match['id'], $details),
                'food' => $this->food($match['id']),
                default => $this->codex($match['id'], $details),
            },
            ...($details && in_array($match['kind'], ['element', 'food', 'plant', 'critter'], true) ? $this->recipeReferences($match['id']) : []),
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
            'details' => $schema->boolean()->description('Include available descriptions, Codex text and linked entity ids.'),
        ];
    }

    /**
     * Whether every match names the query exactly (by id or name), i.e. the search kept only exact matches.
     *
     * @param  list<array{kind: string, id: string, nameFr: string, nameEn: string}>  $matches
     */
    private static function isExact(string $query, array $matches): bool
    {
        $needle = mb_strtolower(mb_trim(\Illuminate\Support\Str::ascii($query)));

        foreach ($matches as $match) {
            $names = array_map(fn (string $text): string => mb_strtolower(\Illuminate\Support\Str::ascii($text)), [$match['id'], $match['nameFr'], $match['nameEn']]);
            if (! in_array($needle, $names, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, float> $rates */
    private static function conversionSide(array $rates): string
    {
        $parts = [];
        foreach ($rates as $id => $rate) {
            $parts[] = $id.' '.McpFormat::number($rate, 4).' kg/s';
        }

        return $parts === [] ? 'not specified' : implode(', ', $parts);
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
    private function building(string $id, bool $details): array
    {
        $building = Building::query()->with('constructionMaterials')->where('building_id', $id)->first();
        if (! $building instanceof Building) {
            return ['details unavailable'];
        }

        $lines = [
            sprintf('category %s · size %dx%d · build time %s s', $building->category, $building->width, $building->height, $building->construction_time ?? '?'),
            sprintf('power %s · generates %s · heat %s', $building->power_consumption === null ? 'none' : $building->power_consumption.' W', $building->power_generation === null ? 'none' : $building->power_generation.' W', $building->heat_generation ?? 'unavailable'),
            'materials: '.($building->constructionMaterials->isEmpty() ? 'unavailable' : $building->constructionMaterials->map(
                fn ($material): string => sprintf('%s %s kg', $material->element_id ?? $material->material_category, McpFormat::number((float) $material->amount)),
            )->implode(', ')),
        ];

        if ($details) {
            $gameText = resolve(GameText::class);
            foreach ([
                'description' => $gameText->description($id) ?? $building->getTranslation('description', 'en'),
                'effect' => $gameText->effect($id) ?? $building->getTranslation('effect', 'en'),
            ] as $label => $value) {
                if (is_string($value) && $value !== '') {
                    $lines[] = $label.': '.PoFile::stripMarkup($value);
                }
            }
            if (isset($building->codex_data['skill_perk'])) {
                $lines[] = 'exported skill perk: '.$building->codex_data['skill_perk'];
            }
            if ($building->build_location_rule !== null) {
                $lines[] = 'placement: '.$building->build_location_rule;
            }
            $room = $building->codex_data['room'] ?? null;
            if ($room !== null) {
                $lines[] = sprintf('room descriptor: %s (%s)', $room['id'], $room['mode']);
            }
            $tinker = $building->codex_data['tinker'] ?? null;
            if ($tinker !== null) {
                $lines[] = sprintf(
                    'staffed operation: %s %s kg/use → %s 1 unit/use%s',
                    $tinker['input_id'],
                    McpFormat::number((float) $tinker['input_kg'], 4),
                    $tinker['output_id'],
                    isset($tinker['skill_perk']) ? ' · skill '.$tinker['skill_perk'] : '',
                );
            }
            foreach ($building->codex_data['manual_conversions'] ?? [] as $conversion) {
                $lines[] = sprintf(
                    'manual Codex conversion: %s → %s%s',
                    $conversion['input_id'] ?? 'not specified',
                    $conversion['output_id'] ?? 'not specified',
                    isset($conversion['label']) ? ' ('.PoFile::stripMarkup($conversion['label']).')' : '',
                );
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private function critter(string $id, bool $details): array
    {
        $critter = Critter::query()->with('mod')->where('critter_id', $id)->first();
        if (! $critter instanceof Critter) {
            return ['details unavailable'];
        }

        $lines = [];
        $morphs = $critter->morphs()->with(['diets', 'outputs'])->orderByDesc('is_base')->orderBy('morph_id')->get();
        foreach ($morphs as $morph) {
            /** @var CritterMorph $morph */
            $lines[] = sprintf(
                'morph %s%s · comfort %s..%s · %s kcal/cycle · lifespan %s cycles · overcrowding %s',
                $morph->morph_id,
                $morph->is_base ? ' (base)' : '',
                $morph->min_temp === null ? '?' : McpFormat::celsius($morph->min_temp - self::KELVIN),
                $morph->max_temp === null ? '?' : McpFormat::celsius($morph->max_temp - self::KELVIN),
                $morph->calories_per_cycle === null ? '?' : McpFormat::number($morph->calories_per_cycle / 1000),
                $morph->lifespan ?? '?',
                $morph->overcrowding_threshold ?? '?',
            );
            if (isset($morph->codex_data['survival_min_kelvin'], $morph->codex_data['survival_max_kelvin'])) {
                $lines[] = sprintf(
                    '  survival %s..%s',
                    McpFormat::celsius($morph->codex_data['survival_min_kelvin'] - self::KELVIN),
                    McpFormat::celsius($morph->codex_data['survival_max_kelvin'] - self::KELVIN),
                );
            }
            foreach ($morph->diets as $diet) {
                /** @var CritterMorphDiet $diet */
                $lines[] = $this->dietLine($diet);
            }
            foreach ($morph->outputs as $output) {
                /** @var CritterMorphOutput $output */
                $lines[] = sprintf('  %s %s %s%s/cycle', $output->output_type, $output->element_id, McpFormat::number((float) $output->amount_per_cycle, 4), $output->output_type === 'egg' ? ' eggs' : '');
            }
            if ($details) {
                foreach ($morph->codex_data['butcher_drops'] ?? [] as $drop) {
                    $amounts = [];
                    if (isset($drop['mass_kg'])) {
                        $amounts[] = McpFormat::number($drop['mass_kg'], 4).' kg';
                    }
                    if (isset($drop['kcal'])) {
                        $amounts[] = McpFormat::number($drop['kcal'], 4).' kcal';
                    }
                    $lines[] = '  butchering: '.$drop['id'].' '.($amounts === [] ? 'quantity unavailable' : implode(', ', $amounts).' per event');
                }
                foreach ($morph->codex_data['conversions'] ?? [] as $conversion) {
                    $lines[] = sprintf(
                        '  conversion (nominal): %s → %s%s',
                        self::conversionSide($conversion['inputs']),
                        self::conversionSide($conversion['outputs']),
                        $conversion['unresolved'] ? ' (partial data)' : '',
                    );
                }
            }
        }

        return [
            ...($details ? $this->descriptionLines($id, $critter->getTranslation('description', 'en')) : []),
            ...($details ? $this->critterReferences($critter) : []),
            ...($lines === [] ? ['morphs unavailable'] : $lines),
        ];
    }

    private function dietLine(CritterMorphDiet $diet): string
    {
        $data = $diet->codex_data;
        $unit = match ($data['unit'] ?? null) {
            'percent_growth_per_cycle' => '% growth/cycle',
            'prey_per_cycle' => 'prey/cycle',
            'units_per_cycle' => 'units/cycle',
            default => null,
        };

        if ($unit !== null) {
            $output = $diet->produced_element_id;
            if ($output !== null && isset($data['output_kg_per_cycle'])) {
                $output .= ' '.McpFormat::number($data['output_kg_per_cycle'], 4).' kg/cycle';
            }

            return sprintf('  eats %s %s %s%s', $diet->consumed_element_id, McpFormat::number($data['amount'], 4), $unit, $output === null ? '' : ' → '.$output);
        }

        return $diet->food_kind === 'item'
            ? sprintf('  eats %s %s kg/cycle → %s ×%s', $diet->consumed_element_id, McpFormat::number((float) $diet->amount_per_cycle), $diet->produced_element_id ?? 'nothing', McpFormat::number((float) $diet->conversion_ratio, 2))
            : sprintf('  eats %s (%s; rate unavailable)%s', $diet->consumed_element_id, $diet->food_kind, $diet->produced_element_id === null ? '' : ' → '.$diet->produced_element_id);
    }

    /** @return list<string> */
    private function critterReferences(Critter $critter): array
    {
        $lines = [];
        if ($critter->mod !== null) {
            $lines[] = 'source mod: '.$critter->mod->title.' ('.$critter->mod->workshop_id.')';
        } elseif ($critter->dlc_id !== null) {
            $lines[] = 'source DLC: '.$critter->dlc_id;
        }
        if ($critter->variant_family !== null) {
            $family = Critter::query()->where('variant_family', $critter->variant_family)
                ->whereHas('baseMorph')->orderBy('critter_id')->pluck('critter_id');
            $lines[] = 'family adults: '.$family->implode(', ');
        }

        return [...$lines, ...$this->consumerReferences($critter->critter_id)];
    }

    /** @return list<string> */
    private function consumerReferences(string $id): array
    {
        $consumers = CritterMorphDiet::query()->join('critter_morphs', 'critter_morphs.id', '=', 'critter_morph_diets.critter_morph_id')
            ->where('consumed_element_id', $id)->where('critter_morphs.is_base', true)
            ->select(['morph_id', 'food_kind'])->distinct()->orderBy('morph_id')->orderBy('food_kind')->limit(21)->toBase()->get();
        $lines = $consumers->take(20)->map(fn ($row): string => sprintf('consumed by %s (%s)', $row->morph_id, $row->food_kind))->all();
        if ($consumers->count() > 20) {
            $lines[] = 'More consumers not shown.';
        }

        return $lines;
    }

    /** @return list<string> */
    private function recipeReferences(string $id): array
    {
        $rows = RecipeItem::query()->join('recipes', 'recipes.id', '=', 'recipe_items.recipe_id')
            ->join('buildings', 'buildings.id', '=', 'recipes.building_id')
            ->where('recipe_items.element_id', $id)->select(['recipe_items.role', 'buildings.building_id'])
            ->distinct()->orderBy('buildings.building_id')->orderBy('recipe_items.role')->limit(21)->toBase()->get();
        $lines = $rows->take(20)->map(fn ($row): string => sprintf(
            'recipe %s at %s',
            $row->role === 'input' ? 'consumed' : 'produced',
            $row->building_id,
        ))->all();
        if ($rows->count() > 20) {
            $lines[] = 'More recipe references not shown.';
        }

        return $lines;
    }

    /** @return list<string> */
    private function plant(string $id, bool $details): array
    {
        $plant = Plant::query()->where('plant_id', $id)->first();
        if (! $plant instanceof Plant) {
            return ['details unavailable'];
        }

        $lines = [];
        $variants = $plant->variants()->with(['inputs', 'outputs'])->orderByDesc('is_base')->get();
        foreach ($variants as $variant) {
            $data = $variant->codex_data;
            $pressure = match (true) {
                ($data['pressure_sensitive'] ?? null) === false => 'ignored',
                ($data['pressure_sensitive'] ?? null) === true => sprintf('%s..%s kg', $data['min_pressure_kg'] ?? 'unbounded', $data['max_pressure_kg'] ?? 'unbounded'),
                (float) $variant->min_pressure === 0.0 && (float) $variant->max_pressure === 0.0 => 'not specified',
                default => sprintf('%s..%s kg', $variant->min_pressure, $variant->max_pressure),
            };
            $lines[] = sprintf(
                'variant %s%s · survival %s..%s · pressure %s · atmosphere %s · light %s · growth %s',
                $variant->variant_id,
                $variant->is_base ? ' (base)' : '',
                $variant->min_temp === null ? '?' : McpFormat::celsius($variant->min_temp - self::KELVIN),
                $variant->max_temp === null ? '?' : McpFormat::celsius($variant->max_temp - self::KELVIN),
                $pressure,
                empty($data['safe_media']) ? ($variant->atmosphere_element_id ?? 'not specified') : implode(', ', $data['safe_media']),
                ($data['prefers_darkness'] ?? false) ? 'darkness preferred' : ($variant->light_required ? 'required' : 'not required'),
                $variant->growth_time > 0 ? McpFormat::number((float) $variant->growth_time).' cycles' : 'not specified',
            );
            if (isset($data['growing_min_kelvin'], $data['growing_max_kelvin'])) {
                $lines[] = sprintf('  growing temperature %s..%s', McpFormat::celsius($data['growing_min_kelvin'] - self::KELVIN), McpFormat::celsius($data['growing_max_kelvin'] - self::KELVIN));
            }
            foreach ($variant->inputs as $input) {
                $lines[] = sprintf('  needs %s %s %s kg/cycle', $input->input_type, $input->element_id, McpFormat::number((float) $input->amount_per_cycle, 2));
            }
            if ($data === null) {
                foreach ($variant->outputs as $output) {
                    $lines[] = sprintf('  gives %s %s %s per harvest', $output->output_type, $output->element_id, McpFormat::number((float) $output->amount_per_harvest, 2));
                }
            } else {
                foreach ($data['crops'] ?? (isset($data['crop']) ? [$data['crop']] : []) as $crop) {
                    $yield = isset($crop['amount'], $crop['unit']) ? McpFormat::number($crop['amount'], 2).' '.$crop['unit'].'/harvest' : 'yield unavailable';
                    $lines[] = sprintf('  crop %s %s', $crop['id'], $yield);
                }
            }
            if ($details && isset($data['seed'])) {
                $seed = $data['seed'];
                $lines[] = '  seed '.$seed['id'].(isset($seed['units_per_drop']) ? sprintf(' (%s units per seed drop)', $seed['units_per_drop']) : '');
            }
        }

        return [
            ...($details ? $this->descriptionLines($id, $plant->getTranslation('description', 'en')) : []),
            ...($lines === [] ? ['variants unavailable'] : $lines),
            ...($details ? $this->consumerReferences($id) : []),
        ];
    }

    /** @return list<string> */
    private function descriptionLines(string $id, mixed $fallback): array
    {
        $description = resolve(GameText::class)->description($id) ?? $fallback;

        return is_string($description) && $description !== '' ? ['description: '.PoFile::stripMarkup($description)] : [];
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
    private function codex(string $id, bool $details): array
    {
        $entry = CodexEntry::query()->where('codex_id', $id)->first();

        if (! $entry instanceof CodexEntry) {
            return ['details unavailable'];
        }

        $lines = [sprintf('category %s · parent %s', $entry->category, $entry->parent_codex_id ?? 'none')];
        if (! $details) {
            return $lines;
        }

        $gameText = resolve(GameText::class);
        foreach ($entry->content ?? [] as $container) {
            foreach ($container['blocks'] ?? [] as $block) {
                if (($block['type'] ?? null) !== 'text') {
                    continue;
                }

                $value = $gameText->translate($block['key'] ?? null) ?? $block['text']['en'] ?? null;
                if (is_string($value) && $value !== '') {
                    $lines[] = PoFile::stripMarkup($value);
                }
            }
        }

        foreach ([
            'building' => $entry->buildings()->pluck('building_id'),
            'element' => $entry->elements()->pluck('element_id'),
            'critter' => $entry->critters()->pluck('critter_id'),
            'plant' => $entry->plants()->pluck('plant_id'),
        ] as $kind => $ids) {
            foreach ($ids->sort() as $relatedId) {
                $lines[] = sprintf('linked %s %s', $kind, $relatedId);
            }
        }

        return $lines;
    }
}
