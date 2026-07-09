<?php

declare(strict_types=1);

namespace App\Services\Critters;

use App\Data\CritterDietData;
use App\Data\CritterFamilyData;
use App\Data\CritterFamilyListItemData;
use App\Data\CritterMorphData;
use App\Data\CritterOutputData;
use App\Data\CritterVariantData;
use App\Data\ElementRefData;
use App\Data\RanchCatalogData;
use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use App\Models\Element;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CritterPresenter
{
    /** @var array<string, string>|null element_id => display name */
    private ?array $elementNames = null;

    /** @var array<string, string|null>|null element_id => root-relative URL */
    private ?array $elementUrls = null;

    /** @var array<string, string>|null critter_id => family slug */
    private ?array $critterFamilySlugs = null;

    /** Build a single family by slug, or null if not found. */
    public function family(string $slug): ?CritterFamilyData
    {
        $critters = $this->critterQuery()->get();
        $groups = $this->groupByFamily($critters);

        foreach ($groups as $familySlug => $variants) {
            if ($familySlug === $slug) {
                return $this->familyData($familySlug, $variants, withBaby: true);
            }
        }

        return null;
    }

    /** @return list<CritterFamilyListItemData> */
    public function familyList(): array
    {
        $groups = $this->groupByFamily($this->critterQuery()->get());

        $items = [];
        foreach ($groups as $slug => $variants) {
            /** @var Critter $first */
            $first = $variants[0];
            [$minTemp, $maxTemp] = $this->familyTempRange($variants);
            $items[] = new CritterFamilyListItemData(
                slug: $slug,
                name: $this->familyName($variants),
                image: $this->critterImage($first->critter_id),
                variant_count: count($variants),
                eats_summary: $this->eatsSummary($first),
                makes_summary: $this->makesSummary($first),
                dlc_id: $first->dlc_id,
                variant_images: $this->variantPreviewImages($variants, $first),
                min_temp: $minTemp,
                max_temp: $maxTemp,
            );
        }

        return $items;
    }

    /** Ranch catalog: adult-only variants + an element ref map. */
    public function catalog(): RanchCatalogData
    {
        $groups = $this->groupByFamily($this->critterQuery()->get());

        $families = [];
        $elements = [];
        foreach ($groups as $slug => $variants) {
            $family = $this->familyData($slug, $variants, withBaby: false);
            $families[] = $family;

            foreach ($family->variants as $variant) {
                foreach ($variant->adult->diets as $diet) {
                    $elements[$diet->consumed->element_id] = $diet->consumed;
                    if ($diet->produced !== null) {
                        $elements[$diet->produced->element_id] = $diet->produced;
                    }
                }
            }
        }

        return new RanchCatalogData(families: $families, elements: $elements);
    }

    /** Eager-loaded critter query. */
    private function critterQuery(): Builder
    {
        return Critter::query()
            ->with(['morphs.diets', 'morphs.outputs'])
            ->orderBy('variant_family')
            ->orderBy('critter_id');
    }

    /**
     * @param  Collection<int, Critter>  $critters
     * @return array<string, list<Critter>> slug => variants, base variant first
     */
    private function groupByFamily(Collection $critters): array
    {
        $groups = [];
        foreach ($critters as $critter) {
            $key = $critter->variant_family ?? $critter->critter_id;
            $slug = Str::slug(Str::kebab($key));
            $groups[$slug][] = $critter;
        }

        return array_map($this->withBaseVariantFirst(...), $groups);
    }

    /**
     * Move the variant whose critter_id matches the family key to index 0,
     * falling back to the existing (alphabetical-first) order when none matches.
     *
     * @param  list<Critter>  $variants
     * @return list<Critter>
     */
    private function withBaseVariantFirst(array $variants): array
    {
        $baseIndex = null;
        foreach ($variants as $index => $variant) {
            if ($variant->critter_id === $variant->variant_family) {
                $baseIndex = $index;
                break;
            }
        }

        if ($baseIndex === null || $baseIndex === 0) {
            return $variants;
        }

        $base = $variants[$baseIndex];
        unset($variants[$baseIndex]);

        return [$base, ...array_values($variants)];
    }

    /** @param list<Critter> $variants */
    private function familyData(string $slug, array $variants, bool $withBaby): CritterFamilyData
    {
        /** @var Critter $first */
        $first = $variants[0];

        return new CritterFamilyData(
            slug: $slug,
            name: $this->familyName($variants),
            description: $first->getTranslations('description') ?: null,
            image: $this->critterImage($first->critter_id),
            variants: array_map(fn (Critter $c): CritterVariantData => $this->variantData($c, $withBaby), $variants),
        );
    }

    /** @param list<Critter> $variants */
    private function familyName(array $variants): string
    {
        /** @var Critter $first */
        $first = $variants[0];
        $baseMorph = $first->morphs->firstWhere('is_base', true) ?? $first->morphs->first();
        $resolved = $baseMorph?->getTranslation('name', 'en');

        if ($resolved) {
            return $resolved;
        }

        return $first->variant_family ?? $first->critter_id;
    }

    /**
     * @param  list<Critter>  $variants
     * @return array{0: float, 1: float} [min_temp, max_temp] across every variant's base morph
     */
    private function familyTempRange(array $variants): array
    {
        $min = null;
        $max = null;
        foreach ($variants as $variant) {
            $morph = $variant->morphs->firstWhere('is_base', true) ?? $variant->morphs->first();
            if ($morph === null) {
                continue;
            }
            $min = $min === null ? (float) $morph->min_temp : min($min, (float) $morph->min_temp);
            $max = $max === null ? (float) $morph->max_temp : max($max, (float) $morph->max_temp);
        }

        return [$min ?? 0.0, $max ?? 0.0];
    }

    /**
     * Up to 2 images from the family's other variants, for the index tile's preview stack.
     *
     * @param  list<Critter>  $variants
     * @return list<string>
     */
    private function variantPreviewImages(array $variants, Critter $primary): array
    {
        $images = [];
        foreach ($variants as $variant) {
            if ($variant->critter_id === $primary->critter_id) {
                continue;
            }
            $image = $this->critterImage($variant->critter_id);
            if ($image !== null) {
                $images[] = $image;
            }
            if (count($images) === 2) {
                break;
            }
        }

        return $images;
    }

    private function variantData(Critter $critter, bool $withBaby): CritterVariantData
    {
        $adult = $critter->morphs->firstWhere('is_base', true) ?? $critter->morphs->first();
        $baby = $withBaby ? $critter->morphs->firstWhere('is_base', false) : null;

        return new CritterVariantData(
            critter_id: $critter->critter_id,
            name: $this->variantName($critter, $adult),
            image: $this->critterImage($critter->critter_id),
            adult: $this->morphData($adult),
            baby: $baby !== null ? $this->morphData($baby) : null,
        );
    }

    /** @return array<string, string> */
    private function variantName(Critter $critter, ?CritterMorph $adult): array
    {
        $translations = $adult?->getTranslations('name') ?? [];

        return $translations !== [] ? $translations : ['en' => $critter->critter_id];
    }

    private function morphData(CritterMorph $morph): CritterMorphData
    {
        return new CritterMorphData(
            morph_id: $morph->morph_id,
            is_base: (bool) $morph->is_base,
            name: $morph->getTranslations('name'),
            min_temp: (float) $morph->min_temp,
            max_temp: (float) $morph->max_temp,
            calories_per_cycle: (float) $morph->calories_per_cycle,
            incubation_time: (float) $morph->incubation_time,
            lifespan: (float) $morph->lifespan,
            overcrowding_threshold: (int) $morph->overcrowding_threshold,
            diets: $morph->diets->map(fn (CritterMorphDiet $d): CritterDietData => new CritterDietData(
                consumed: $this->elementRef($d->consumed_element_id),
                produced: $d->produced_element_id !== null ? $this->elementRef($d->produced_element_id) : null,
                amount_per_cycle: (float) $d->amount_per_cycle,
                conversion_ratio: $d->conversion_ratio !== null ? (float) $d->conversion_ratio : null,
            ))->all(),
            outputs: $morph->outputs->map(fn (CritterMorphOutput $o): CritterOutputData => $this->outputData($o))->all(),
        );
    }

    private function outputData(CritterMorphOutput $output): CritterOutputData
    {
        // Egg element_ids are prefab ids (e.g. "HatchEgg"), not elements.
        return new CritterOutputData(
            kind: $output->output_type,
            element_id: $output->element_id,
            label: $output->output_type === 'egg'
                ? $output->element_id
                : $this->elementName($output->element_id),
            icon: $output->output_type === 'egg'
                ? $this->critterImageForEgg($output->element_id)
                : $this->assetIfExists("assets/game/elements/{$output->element_id}.webp"),
            url: $output->output_type === 'egg'
                ? $this->familyUrlForEgg($output->element_id)
                : $this->elementUrl($output->element_id),
            amount_per_cycle: (float) $output->amount_per_cycle,
        );
    }

    private function elementRef(string $elementId): ElementRefData
    {
        return new ElementRefData(
            element_id: $elementId,
            name: $this->elementName($elementId),
            icon: $this->assetIfExists("assets/game/elements/{$elementId}.webp"),
            url: $this->elementUrl($elementId),
        );
    }

    /** Display name from the elements table, falling back to the id. */
    private function elementName(string $elementId): string
    {
        if ($this->elementNames === null) {
            $this->elementNames = Element::query()
                ->pluck('name', 'element_id')
                ->map(fn ($name): string => is_array($name) ? ($name['en'] ?? '') : (string) $name)
                ->all();
        }

        $name = $this->elementNames[$elementId] ?? '';

        return $name !== '' ? $name : $elementId;
    }

    /** Codex entry preferred, else element page. Returns a root-relative path. */
    private function elementUrl(string $elementId): ?string
    {
        if ($this->elementUrls === null) {
            $this->elementUrls = [];
            Element::query()
                ->with('codexEntries:id,codex_id')
                ->get(['id', 'element_id'])
                ->each(function (Element $element): void {
                    $codexId = $element->codexEntries?->first()?->codex_id;
                    $this->elementUrls[$element->element_id] = $codexId !== null
                        ? $this->routePath('codex.show', $codexId)
                        : $this->routePath('elements.show', $element->element_id);
                });
        }

        return $this->elementUrls[$elementId]
            ?? $this->routePath('elements.show', $elementId);
    }

    private function critterImage(string $critterId): ?string
    {
        return $this->assetIfExists("assets/game/critters/{$critterId}.webp");
    }

    /** Map an egg prefab id back to its critter image, e.g. HatchEgg -> Hatch. */
    private function critterImageForEgg(string $eggId): ?string
    {
        return $this->critterImage(Str::beforeLast($eggId, 'Egg'));
    }

    private function familyUrlForEgg(string $eggId): ?string
    {
        if ($this->critterFamilySlugs === null) {
            $this->critterFamilySlugs = [];
            Critter::query()
                ->get(['critter_id', 'variant_family'])
                ->each(function (Critter $critter): void {
                    $slug = Str::slug(Str::kebab($critter->variant_family ?? $critter->critter_id));
                    $this->critterFamilySlugs[$critter->critter_id] = $slug;
                });
        }

        $critterId = Str::beforeLast($eggId, 'Egg');
        $slug = $this->critterFamilySlugs[$critterId] ?? null;

        return $slug !== null
            ? $this->routePath('critters.show', $slug)
            : null;
    }

    private function assetIfExists(string $relative): ?string
    {
        return file_exists(public_path($relative)) ? "/{$relative}" : null;
    }

    /** Generate a root-relative URL path from a named route. */
    private function routePath(string $name, mixed ...$parameters): string
    {
        return parse_url(route($name, ...$parameters), PHP_URL_PATH) ?? route($name, ...$parameters);
    }

    private function eatsSummary(Critter $critter): ?string
    {
        $adult = $critter->morphs->firstWhere('is_base', true) ?? $critter->morphs->first();
        $first = $adult?->diets->first();

        return $first !== null ? $this->elementName($first->consumed_element_id) : null;
    }

    private function makesSummary(Critter $critter): ?string
    {
        $adult = $critter->morphs->firstWhere('is_base', true) ?? $critter->morphs->first();
        $first = $adult?->diets->first();

        return ($first !== null && $first->produced_element_id !== null)
            ? $this->elementName($first->produced_element_id)
            : null;
    }
}
