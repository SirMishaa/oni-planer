# Critter Page + Ranch Planner Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a per-family critter page (imagery, stats, diet, outputs, lifecycle) and a URL-shareable multi-species ranch planner that computes net per-element consume/produce per cycle, consuming the already-imported critter ranching data.

**Architecture:** Two thin Inertia controllers (`CritterController` index/show, `RanchController` index) render Vue pages. A `CritterPresenter` service turns models into `App\Data` DTOs (auto-transformed to TS). The planner gets a compact `catalog` prop + `elements` map and does all math client-side in a `useRanch` composable; selection state lives in the URL query.

**Tech Stack:** Laravel 13, spatie/laravel-data (+typescript-transformer), Inertia v3, Vue 3 `<script setup lang="ts">`, Tailwind v4, Pest (PHP, 100% coverage gate), vitest (front), vue-tsc.

**Conventions:** All Vue SFCs use `<script setup lang="ts">`. JetBrains region markers (`<!-- region -->`/`<!-- endregion -->` in templates, `// region` in scripts) where a file has distinct sections. Commits: `--no-gpg-sign`, no `Co-Authored-By`. Run `vendor/bin/pint --dirty --format agent` after PHP changes. DTOs in `App\Data` are `final` classes extending `Spatie\LaravelData\Data` with promoted constructor props; translations typed as `array<string, string>`. After adding/altering DTOs run `php artisan typescript:transform`.

---

## File Structure

**Backend:**
- `app/Data/ElementRefData.php` — element/egg reference: id, display name, icon url, link url.
- `app/Data/CritterDietData.php` — one diet option: consumed ref, produced ref, amount/cycle, conversion ratio.
- `app/Data/CritterOutputData.php` — egg/dropping/resource output.
- `app/Data/CritterMorphData.php` — a morph's stats + diets + outputs.
- `app/Data/CritterVariantData.php` — a variant: critter_id, name, image, adult morph, optional baby morph.
- `app/Data/CritterFamilyData.php` — family: slug, name, description, image, variants.
- `app/Data/CritterFamilyListItemData.php` — index tile: slug, name, image, variant_count, summaries.
- `app/Data/RanchCatalogData.php` — `{ families: CritterFamilyData[], elements: array<string, ElementRefData> }`.
- `app/Services/Critters/CritterPresenter.php` — family grouping, element-ref resolution (codex-preferred url + icon existence), egg-ref resolution, DTO assembly. Single source of "model → DTO".
- `app/Http/Controllers/CritterController.php` — `index`, `show`.
- `app/Http/Controllers/RanchController.php` — `index`.
- `routes/web.php` — +3 named routes.
- `database/factories/CritterFactory.php`, `CritterMorphFactory.php`, `CritterMorphDietFactory.php`, `CritterMorphOutputFactory.php`.

**Frontend:**
- `resources/js/composables/useRanch.ts` — calc + URL encode/decode.
- `resources/js/composables/useRanch.test.ts` — vitest unit tests.
- `resources/js/pages/Critters/Index.vue` — family tile grid.
- `resources/js/pages/Critters/Show.vue` — family page.
- `resources/js/pages/Ranch/Index.vue` — planner (two-column).
- `resources/js/layouts/AppLayout.vue` — nav (flip Critters `available`, add Ranch).

**Generated:** `resources/js/types/App/Data/data.ts` (via `php artisan typescript:transform`).

---

## Task 1: Factories for Critter, CritterMorph, diets, outputs

**Files:**
- Create: `database/factories/CritterFactory.php`
- Create: `database/factories/CritterMorphFactory.php`
- Create: `database/factories/CritterMorphDietFactory.php`
- Create: `database/factories/CritterMorphOutputFactory.php`

- [ ] **Step 1: Write a failing test exercising the factories**

Create `tests/Feature/Critters/CritterFactoryTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a critter with morphs, diets and outputs via factories', function (): void {
    $critter = Critter::factory()
        ->has(CritterMorph::factory()->state(['is_base' => true])->has(CritterMorphDiet::factory()->count(2), 'diets')->has(CritterMorphOutput::factory()->egg(), 'outputs'), 'morphs')
        ->create(['critter_id' => 'Hatch', 'variant_family' => 'Hatch']);

    expect($critter->variant_family)->toBe('Hatch');
    $morph = $critter->morphs->first();
    expect($morph->diets)->toHaveCount(2)
        ->and($morph->outputs->first()->output_type)->toBe('egg');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=CritterFactoryTest`
Expected: FAIL — `Call to undefined method ...::factory()` (no factories).

- [ ] **Step 3: Create the factories**

`database/factories/CritterFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Critter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Critter> */
final class CritterFactory extends Factory
{
    protected $model = Critter::class;

    public function definition(): array
    {
        $id = $this->faker->unique()->word();

        return [
            'critter_id' => $id,
            'variant_family' => $id,
            'name' => ['en' => $id],
            'description' => ['en' => $this->faker->sentence()],
            'dlc_id' => null,
        ];
    }
}
```

`database/factories/CritterMorphFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Critter;
use App\Models\CritterMorph;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CritterMorph> */
final class CritterMorphFactory extends Factory
{
    protected $model = CritterMorph::class;

    public function definition(): array
    {
        return [
            'critter_id' => Critter::factory(),
            'morph_id' => $this->faker->unique()->word(),
            'is_base' => true,
            'min_temp' => 283.15,
            'max_temp' => 313.15,
            'calories_per_cycle' => 700000,
            'incubation_time' => 20,
            'lifespan' => 0,
            'overcrowding_threshold' => 12,
            'name' => ['en' => $this->faker->word()],
        ];
    }

    public function baby(): static
    {
        return $this->state(['is_base' => false, 'incubation_time' => 0]);
    }
}
```

`database/factories/CritterMorphDietFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CritterMorphDiet> */
final class CritterMorphDietFactory extends Factory
{
    protected $model = CritterMorphDiet::class;

    public function definition(): array
    {
        return [
            'critter_morph_id' => CritterMorph::factory(),
            'consumed_element_id' => 'Sand',
            'amount_per_cycle' => 140,
            'produced_element_id' => 'Carbon',
            'conversion_ratio' => 0.5,
        ];
    }
}
```

`database/factories/CritterMorphOutputFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CritterMorph;
use App\Models\CritterMorphOutput;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CritterMorphOutput> */
final class CritterMorphOutputFactory extends Factory
{
    protected $model = CritterMorphOutput::class;

    public function definition(): array
    {
        return [
            'critter_morph_id' => CritterMorph::factory(),
            'element_id' => 'HatchEgg',
            'amount_per_cycle' => 1 / 60,
            'output_type' => 'egg',
        ];
    }

    public function egg(): static
    {
        return $this->state(['output_type' => 'egg', 'element_id' => 'HatchEgg']);
    }
}
```

Ensure each model uses `Illuminate\Database\Eloquent\Factories\HasFactory`. If `Critter`, `CritterMorph`, `CritterMorphDiet`, or `CritterMorphOutput` does not already `use HasFactory;`, add the trait import and `use HasFactory;` to that model.

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test --compact --filter=CritterFactoryTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/factories/Critter*.php app/Models/Critter*.php tests/Feature/Critters/CritterFactoryTest.php
git commit --no-gpg-sign -m "test(critters): factories for critter morphs, diets, outputs"
```

---

## Task 2: Data DTOs

**Files:**
- Create: `app/Data/ElementRefData.php`, `CritterDietData.php`, `CritterOutputData.php`, `CritterMorphData.php`, `CritterVariantData.php`, `CritterFamilyData.php`, `CritterFamilyListItemData.php`, `RanchCatalogData.php`

- [ ] **Step 1: Write a failing test asserting DTO shapes**

Create `tests/Feature/Critters/CritterDataTest.php`:

```php
<?php

declare(strict_types=1);

use App\Data\CritterFamilyData;
use App\Data\ElementRefData;

it('builds element ref and family data from arrays', function (): void {
    $ref = ElementRefData::from([
        'element_id' => 'Carbon', 'name' => 'Coal', 'icon' => '/assets/game/elements/Carbon.webp', 'url' => '/codex/CARBON',
    ]);
    expect($ref->name)->toBe('Coal');

    $family = CritterFamilyData::from([
        'slug' => 'hatch', 'name' => 'Hatch', 'description' => null,
        'image' => '/assets/game/critters/Hatch.webp', 'variants' => [],
    ]);
    expect($family->slug)->toBe('hatch')->and($family->variants)->toBe([]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=CritterDataTest`
Expected: FAIL — DTO classes do not exist.

- [ ] **Step 3: Create the DTOs**

`app/Data/ElementRefData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class ElementRefData extends Data
{
    public function __construct(
        public string $element_id,
        public string $name,
        public ?string $icon,
        public ?string $url,
    ) {}
}
```

`app/Data/CritterDietData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterDietData extends Data
{
    public function __construct(
        public ElementRefData $consumed,
        public ?ElementRefData $produced,
        public float $amount_per_cycle,
        public ?float $conversion_ratio,
    ) {}
}
```

`app/Data/CritterOutputData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterOutputData extends Data
{
    public function __construct(
        public string $kind,
        public string $element_id,
        public string $label,
        public ?string $icon,
        public ?string $url,
        public float $amount_per_cycle,
    ) {}
}
```

`app/Data/CritterMorphData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterMorphData extends Data
{
    public function __construct(
        public string $morph_id,
        public bool $is_base,
        /** @var array<string, string> */
        public array $name,
        public float $min_temp,
        public float $max_temp,
        public float $calories_per_cycle,
        public float $incubation_time,
        public float $lifespan,
        public int $overcrowding_threshold,
        /** @var list<CritterDietData> */
        public array $diets,
        /** @var list<CritterOutputData> */
        public array $outputs,
    ) {}
}
```

`app/Data/CritterVariantData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterVariantData extends Data
{
    public function __construct(
        public string $critter_id,
        /** @var array<string, string> */
        public array $name,
        public ?string $image,
        public CritterMorphData $adult,
        public ?CritterMorphData $baby,
    ) {}
}
```

`app/Data/CritterFamilyData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterFamilyData extends Data
{
    public function __construct(
        public string $slug,
        public string $name,
        /** @var array<string, string>|null */
        public ?array $description,
        public ?string $image,
        /** @var list<CritterVariantData> */
        public array $variants,
    ) {}
}
```

`app/Data/CritterFamilyListItemData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterFamilyListItemData extends Data
{
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $image,
        public int $variant_count,
        public ?string $eats_summary,
        public ?string $makes_summary,
    ) {}
}
```

`app/Data/RanchCatalogData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class RanchCatalogData extends Data
{
    public function __construct(
        /** @var list<CritterFamilyData> */
        public array $families,
        /** @var array<string, ElementRefData> */
        public array $elements,
    ) {}
}
```

- [ ] **Step 4: Run test + transform TS**

Run: `php artisan test --compact --filter=CritterDataTest`
Expected: PASS.
Then run: `php artisan typescript:transform`
Expected: regenerates `resources/js/types/App/Data/data.ts` including the new types.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/*.php resources/js/types tests/Feature/Critters/CritterDataTest.php
git commit --no-gpg-sign -m "feat(critters): ranch/critter Data DTOs + generated TS types"
```

---

## Task 3: CritterPresenter service

**Files:**
- Create: `app/Services/Critters/CritterPresenter.php`
- Test: `tests/Feature/Critters/CritterPresenterTest.php`

The presenter owns: family slug derivation, element-ref resolution (display name from the `elements` table, icon path if the webp exists, url preferring a Codex entry then the element page), egg-ref resolution (label, link to that critter's family page, critter animation icon), and assembly of morph/variant/family DTOs.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use App\Models\Element;
use App\Services\Critters\CritterPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeHatch(): Critter
{
    Element::factory()->create(['element_id' => 'Sand', 'name' => ['en' => 'Sand']]);
    Element::factory()->create(['element_id' => 'Carbon', 'name' => ['en' => 'Coal']]);

    $critter = Critter::factory()->create(['critter_id' => 'Hatch', 'variant_family' => 'Hatch', 'name' => ['en' => 'Hatch']]);
    $adult = CritterMorph::factory()->create(['critter_id' => $critter->id, 'morph_id' => 'Hatch', 'is_base' => true]);
    CritterMorphDiet::factory()->create(['critter_morph_id' => $adult->id, 'consumed_element_id' => 'Sand', 'produced_element_id' => 'Carbon', 'amount_per_cycle' => 140, 'conversion_ratio' => 0.5]);
    CritterMorphOutput::factory()->egg()->create(['critter_morph_id' => $adult->id, 'element_id' => 'HatchEgg', 'amount_per_cycle' => 1 / 60]);
    CritterMorph::factory()->baby()->create(['critter_id' => $critter->id, 'morph_id' => 'HatchBaby']);

    return $critter;
}

it('presents a family with resolved diet element refs and egg outputs', function (): void {
    makeHatch();

    $family = app(CritterPresenter::class)->family('hatch');

    expect($family)->not->toBeNull()
        ->and($family->slug)->toBe('hatch')
        ->and($family->variants)->toHaveCount(1);

    $adult = $family->variants[0]->adult;
    expect($adult->diets)->toHaveCount(1)
        ->and($adult->diets[0]->consumed->name)->toBe('Sand')
        ->and($adult->diets[0]->produced->name)->toBe('Coal')
        ->and($adult->diets[0]->consumed->icon)->toBeNull() // no webp file in test env
        ->and($adult->outputs[0]->kind)->toBe('egg')
        ->and($adult->outputs[0]->url)->toBe('/critters/hatch');

    expect($family->variants[0]->baby)->not->toBeNull();
});

it('returns null for an unknown family slug', function (): void {
    expect(app(CritterPresenter::class)->family('nope'))->toBeNull();
});

it('builds a ranch catalog with adult-only variants and an elements map', function (): void {
    makeHatch();

    $catalog = app(CritterPresenter::class)->catalog();

    expect($catalog->families)->toHaveCount(1)
        ->and($catalog->families[0]->variants[0]->baby)->toBeNull()
        ->and($catalog->elements)->toHaveKey('Carbon')
        ->and($catalog->elements['Carbon']->name)->toBe('Coal');
});

it('lists families with variant counts', function (): void {
    makeHatch();

    $list = app(CritterPresenter::class)->familyList();

    expect($list)->toHaveCount(1)
        ->and($list[0]->slug)->toBe('hatch')
        ->and($list[0]->variant_count)->toBe(1);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=CritterPresenterTest`
Expected: FAIL — `CritterPresenter` does not exist.

- [ ] **Step 3: Implement the presenter**

`app/Services/Critters/CritterPresenter.php`:

```php
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
use App\Models\Element;
use Illuminate\Support\Str;

final class CritterPresenter
{
    /** @var array<string, string>|null element_id => display name */
    private ?array $elementNames = null;

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
            $items[] = new CritterFamilyListItemData(
                slug: $slug,
                name: $this->familyName($variants),
                image: $this->critterImage($first->critter_id),
                variant_count: count($variants),
                eats_summary: $this->eatsSummary($first),
                makes_summary: $this->makesSummary($first),
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
    private function critterQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Critter::query()
            ->with(['morphs.diets', 'morphs.outputs'])
            ->orderBy('variant_family')
            ->orderBy('critter_id');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Critter>  $critters
     * @return array<string, list<Critter>> slug => variants
     */
    private function groupByFamily(\Illuminate\Support\Collection $critters): array
    {
        $groups = [];
        foreach ($critters as $critter) {
            $key = $critter->variant_family ?? $critter->critter_id;
            $slug = Str::slug(Str::kebab($key));
            $groups[$slug][] = $critter;
        }

        return $groups;
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

        return $first->variant_family ?? ($first->getTranslation('name', 'en') ?: $first->critter_id);
    }

    private function variantData(Critter $critter, bool $withBaby): CritterVariantData
    {
        $adult = $critter->morphs->firstWhere('is_base', true) ?? $critter->morphs->first();
        $baby = $withBaby ? $critter->morphs->firstWhere('is_base', false) : null;

        return new CritterVariantData(
            critter_id: $critter->critter_id,
            name: $critter->getTranslations('name'),
            image: $this->critterImage($critter->critter_id),
            adult: $this->morphData($adult),
            baby: $baby !== null ? $this->morphData($baby) : null,
        );
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
            outputs: $morph->outputs->map(fn ($o): CritterOutputData => $this->outputData($o))->all(),
        );
    }

    private function outputData(\App\Models\CritterMorphOutput $output): CritterOutputData
    {
        // Egg element_ids are prefab ids (e.g. "HatchEgg"), not elements.
        return new CritterOutputData(
            kind: $output->output_type,
            element_id: $output->element_id,
            label: $output->output_type === 'egg'
                ? $output->element_id
                : $this->elementName($output->element_id),
            icon: $this->critterImageForEgg($output->element_id),
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

    /** Codex entry preferred (codex liaison), else element page, else null. */
    private function elementUrl(string $elementId): ?string
    {
        $codexId = \App\Models\Element::query()
            ->where('element_id', $elementId)
            ->with('codexEntries:id,codex_id')
            ->first()?->codexEntries?->first()?->codex_id;

        if ($codexId !== null) {
            return route('codex.show', $codexId);
        }

        return route('elements.show', $elementId);
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
        $critterId = Str::beforeLast($eggId, 'Egg');
        $critter = Critter::query()->where('critter_id', $critterId)->first();
        if ($critter === null) {
            return null;
        }
        $slug = Str::slug(Str::kebab($critter->variant_family ?? $critter->critter_id));

        return route('critters.show', $slug);
    }

    private function assetIfExists(string $relative): ?string
    {
        return file_exists(public_path($relative)) ? "/{$relative}" : null;
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
```

If `Element` lacks a `codexEntries` relation, add it. Check `App\Models\Concerns\HasCodexEntries` (used by `Critter`); if `Element` uses it, `codexEntries` already exists. If not, add `use HasCodexEntries;` to `Element` (it relates via the same codex pivot the `CodexController` already loads as `elements`). Confirm the relation name with `grep -rn "codexEntries\|HasCodexEntries" app/Models`.

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test --compact --filter=CritterPresenterTest`
Expected: PASS (all four tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Critters/CritterPresenter.php app/Models/Element.php tests/Feature/Critters/CritterPresenterTest.php
git commit --no-gpg-sign -m "feat(critters): CritterPresenter builds family/catalog DTOs"
```

---

## Task 4: CritterController + routes + index/show wiring

**Files:**
- Create: `app/Http/Controllers/CritterController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Critters/CritterControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    makeHatch(); // defined in CritterPresenterTest.php — duplicate the helper here if running in isolation
});

it('renders the critter index with families', function (): void {
    $this->get('/critters')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Critters/Index')
            ->has('families', 1)
            ->where('families.0.slug', 'hatch')
            ->where('families.0.variant_count', 1));
});

it('renders a family page', function (): void {
    $this->get('/critters/hatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Critters/Show')
            ->where('family.slug', 'hatch')
            ->has('family.variants', 1)
            ->where('family.variants.0.adult.diets.0.consumed.name', 'Sand'));
});

it('404s on unknown family', function (): void {
    $this->get('/critters/nope')->assertNotFound();
});
```

Add a shared `makeHatch()` helper to `tests/Pest.php` (or a `tests/Feature/Critters/Helpers.php` autoloaded file) so both `CritterPresenterTest` and `CritterControllerTest` use it. Move the `makeHatch()` body from Task 3's test into the shared helper and reference it from both.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=CritterControllerTest`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: Implement controller + routes**

`app/Http/Controllers/CritterController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Critters\CritterPresenter;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CritterController
{
    public function __construct(private readonly CritterPresenter $presenter) {}

    public function index(): Response
    {
        return Inertia::render('Critters/Index', [
            'families' => $this->presenter->familyList(),
        ]);
    }

    public function show(string $family): Response
    {
        $data = $this->presenter->family($family);
        if ($data === null) {
            throw new NotFoundHttpException();
        }

        return Inertia::render('Critters/Show', [
            'family' => $data,
        ]);
    }
}
```

Add to `routes/web.php` (after the buildings routes), with the existing `use` style:

```php
use App\Http\Controllers\CritterController;
use App\Http\Controllers\RanchController;

Route::get('/critters', [CritterController::class, 'index'])->name('critters.index');
Route::get('/critters/{family}', [CritterController::class, 'show'])->name('critters.show');
Route::get('/ranch', [RanchController::class, 'index'])->name('ranch.index');
```

(The `ranch.index` route is referenced by `CritterPresenter` and implemented in Task 6; declaring it now keeps `route('ranch.index')` valid. If Task 6 runs later, the route resolves to a controller that exists only after Task 6 — that's fine because no request hits `/ranch` until then, but `route()` URL generation works immediately.)

- [ ] **Step 4: Run it to verify it passes (index + show + 404)**

The Vue pages don't exist yet, but `assertInertia` checks the server response (component name + props), not client rendering, so these pass without the SFCs.

Run: `php artisan test --compact --filter=CritterControllerTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/CritterController.php routes/web.php tests/Feature/Critters/CritterControllerTest.php tests/Pest.php
git commit --no-gpg-sign -m "feat(critters): CritterController index/show + routes"
```

---

## Task 5: useRanch composable (calc + URL state)

**Files:**
- Create: `resources/js/composables/useRanch.ts`
- Test: `resources/js/composables/useRanch.test.ts`

This is the calculator core. It is framework-light (pure functions + a thin reactive wrapper) so it unit-tests without a DOM.

- [ ] **Step 1: Write the failing test**

`resources/js/composables/useRanch.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import {
    computeBalance,
    decodeRows,
    encodeRows,
    type RanchRow,
    type RanchCatalog,
} from './useRanch';

const catalog: RanchCatalog = {
    families: [
        {
            slug: 'hatch',
            name: 'Hatch',
            description: null,
            image: null,
            variants: [
                {
                    critter_id: 'Hatch',
                    name: { en: 'Hatch' },
                    image: null,
                    adult: {
                        morph_id: 'Hatch',
                        is_base: true,
                        name: { en: 'Hatch' },
                        min_temp: 0,
                        max_temp: 0,
                        calories_per_cycle: 700000,
                        incubation_time: 20,
                        lifespan: 0,
                        overcrowding_threshold: 12,
                        diets: [
                            { consumed: { element_id: 'Sand', name: 'Sand', icon: null, url: null }, produced: { element_id: 'Carbon', name: 'Coal', icon: null, url: null }, amount_per_cycle: 140, conversion_ratio: 0.5 },
                            { consumed: { element_id: 'Clay', name: 'Clay', icon: null, url: null }, produced: { element_id: 'Carbon', name: 'Coal', icon: null, url: null }, amount_per_cycle: 140, conversion_ratio: 0.5 },
                        ],
                        outputs: [{ kind: 'egg', element_id: 'HatchEgg', label: 'HatchEgg', icon: null, url: null, amount_per_cycle: 1 / 60 }],
                    },
                    baby: null,
                },
            ],
        },
        {
            slug: 'drecko',
            name: 'Drecko',
            description: null,
            image: null,
            variants: [
                {
                    critter_id: 'Drecko',
                    name: { en: 'Drecko' },
                    image: null,
                    adult: {
                        morph_id: 'Drecko', is_base: true, name: { en: 'Drecko' },
                        min_temp: 0, max_temp: 0, calories_per_cycle: 0, incubation_time: 0, lifespan: 0, overcrowding_threshold: 0,
                        diets: [],
                        outputs: [{ kind: 'egg', element_id: 'DreckoEgg', label: 'DreckoEgg', icon: null, url: null, amount_per_cycle: 1 / 90 }],
                    },
                    baby: null,
                },
            ],
        },
    ],
    elements: {
        Sand: { element_id: 'Sand', name: 'Sand', icon: null, url: null },
        Carbon: { element_id: 'Carbon', name: 'Coal', icon: null, url: null },
    },
};

describe('useRanch calc', () => {
    it('nets produced minus consumed for the chosen food', () => {
        const rows: RanchRow[] = [{ critterId: 'Hatch', foodElementId: 'Sand', count: 6 }];
        const balance = computeBalance(catalog, rows);

        // 6 hatches eat 6*140 = 840 kg Sand, make 840*0.5 = 420 kg Coal.
        const sand = balance.elements.find((e) => e.element_id === 'Sand')!;
        const coal = balance.elements.find((e) => e.element_id === 'Carbon')!;
        expect(sand.net_per_cycle).toBeCloseTo(-840);
        expect(coal.net_per_cycle).toBeCloseTo(420);
        expect(balance.eggs[0].amount_per_cycle).toBeCloseTo(6 / 60);
    });

    it('counts only the chosen food, not all diet options', () => {
        const rows: RanchRow[] = [{ critterId: 'Hatch', foodElementId: 'Clay', count: 1 }];
        const balance = computeBalance(catalog, rows);
        expect(balance.elements.find((e) => e.element_id === 'Clay')!.net_per_cycle).toBeCloseTo(-140);
        expect(balance.elements.find((e) => e.element_id === 'Sand')).toBeUndefined();
    });

    it('defaults food to the first diet when unset', () => {
        const rows: RanchRow[] = [{ critterId: 'Hatch', foodElementId: null, count: 1 }];
        const balance = computeBalance(catalog, rows);
        expect(balance.elements.find((e) => e.element_id === 'Sand')!.net_per_cycle).toBeCloseTo(-140);
    });

    it('handles a critter with no diet (eggs only)', () => {
        const rows: RanchRow[] = [{ critterId: 'Drecko', foodElementId: null, count: 8 }];
        const balance = computeBalance(catalog, rows);
        expect(balance.elements).toHaveLength(0);
        expect(balance.eggs[0].amount_per_cycle).toBeCloseTo(8 / 90);
    });

    it('round-trips rows through the URL encoder', () => {
        const rows: RanchRow[] = [
            { critterId: 'Hatch', foodElementId: 'Sand', count: 6 },
            { critterId: 'Drecko', foodElementId: null, count: 8 },
        ];
        const encoded = encodeRows(rows);
        expect(decodeRows(encoded)).toEqual(rows);
    });

    it('drops unknown critters when decoding against the catalog is applied', () => {
        const rows = decodeRows('Ghost.X:3,Hatch.Sand:2');
        // decodeRows is catalog-agnostic; filtering happens in the composable.
        expect(rows).toEqual([
            { critterId: 'Ghost', foodElementId: 'X', count: 3 },
            { critterId: 'Hatch', foodElementId: 'Sand', count: 2 },
        ]);
    });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `bunx vitest run resources/js/composables/useRanch.test.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the composable**

`resources/js/composables/useRanch.ts`:

```ts
import { computed, ref, type Ref } from 'vue';
import { router } from '@inertiajs/vue3';
import type {
    CritterFamilyData,
    CritterVariantData,
    ElementRefData,
    RanchCatalogData,
} from '@/types/App/Data/data';

export type RanchCatalog = RanchCatalogData;

export interface RanchRow {
    critterId: string;
    foodElementId: string | null;
    count: number;
}

export interface ElementBalance {
    element_id: string;
    name: string;
    icon: string | null;
    url: string | null;
    net_per_cycle: number;
}

export interface EggBalance {
    element_id: string;
    label: string;
    icon: string | null;
    url: string | null;
    amount_per_cycle: number;
}

export interface RanchBalance {
    elements: ElementBalance[];
    eggs: EggBalance[];
}

function findVariant(catalog: RanchCatalog, critterId: string): CritterVariantData | null {
    for (const family of catalog.families as CritterFamilyData[]) {
        const v = family.variants.find((x) => x.critter_id === critterId);
        if (v) return v;
    }
    return null;
}

export function chosenDiet(variant: CritterVariantData, foodElementId: string | null) {
    const diets = variant.adult.diets;
    if (diets.length === 0) return null;
    if (foodElementId === null) return diets[0];
    return diets.find((d) => d.consumed.element_id === foodElementId) ?? diets[0];
}

export function computeBalance(catalog: RanchCatalog, rows: RanchRow[]): RanchBalance {
    const net = new Map<string, ElementBalance>();
    const eggs = new Map<string, EggBalance>();

    const bump = (ref: ElementRefData, delta: number) => {
        const cur = net.get(ref.element_id) ?? {
            element_id: ref.element_id, name: ref.name, icon: ref.icon, url: ref.url, net_per_cycle: 0,
        };
        cur.net_per_cycle += delta;
        net.set(ref.element_id, cur);
    };

    for (const row of rows) {
        if (row.count <= 0) continue;
        const variant = findVariant(catalog, row.critterId);
        if (!variant) continue;

        const diet = chosenDiet(variant, row.foodElementId);
        if (diet) {
            bump(diet.consumed, -diet.amount_per_cycle * row.count);
            if (diet.produced) {
                const ratio = diet.conversion_ratio ?? 0;
                bump(diet.produced, diet.amount_per_cycle * ratio * row.count);
            }
        }

        for (const out of variant.adult.outputs) {
            if (out.kind === 'egg') {
                const cur = eggs.get(out.element_id) ?? {
                    element_id: out.element_id, label: out.label, icon: out.icon, url: out.url, amount_per_cycle: 0,
                };
                cur.amount_per_cycle += out.amount_per_cycle * row.count;
                eggs.set(out.element_id, cur);
            } else {
                bump(
                    { element_id: out.element_id, name: out.label, icon: out.icon, url: out.url } as ElementRefData,
                    out.amount_per_cycle * row.count,
                );
            }
        }
    }

    return {
        elements: [...net.values()].sort((a, b) => Math.abs(b.net_per_cycle) - Math.abs(a.net_per_cycle)),
        eggs: [...eggs.values()],
    };
}

export function encodeRows(rows: RanchRow[]): string {
    return rows
        .map((r) => `${r.critterId}.${r.foodElementId ?? ''}:${r.count}`)
        .join(',');
}

export function decodeRows(value: string): RanchRow[] {
    if (!value) return [];
    return value
        .split(',')
        .map((token) => {
            const [idPart, countPart] = token.split(':');
            const [critterId, foodElementId] = idPart.split('.');
            const count = Number.parseInt(countPart ?? '0', 10);
            return {
                critterId,
                foodElementId: foodElementId ? foodElementId : null,
                count: Number.isFinite(count) ? count : 0,
            } as RanchRow;
        })
        .filter((r) => r.critterId.length > 0);
}

/** Reactive planner state backed by the URL query (?r=...). */
export function useRanch(catalog: RanchCatalog, initialQuery: string) {
    const rows: Ref<RanchRow[]> = ref(
        decodeRows(initialQuery).filter((r) => findVariant(catalog, r.critterId) !== null),
    );

    const balance = computed(() => computeBalance(catalog, rows.value));

    function syncUrl(): void {
        const r = encodeRows(rows.value);
        router.get(window.location.pathname, r ? { r } : {}, {
            replace: true, preserveState: true, preserveScroll: true, only: [],
        });
    }

    function addCritter(critterId: string): void {
        const variant = findVariant(catalog, critterId);
        if (!variant) return;
        rows.value.push({ critterId, foodElementId: variant.adult.diets[0]?.consumed.element_id ?? null, count: 1 });
        syncUrl();
    }

    function removeRow(index: number): void {
        rows.value.splice(index, 1);
        syncUrl();
    }

    function setCount(index: number, count: number): void {
        rows.value[index].count = Math.max(0, count);
        syncUrl();
    }

    function setFood(index: number, foodElementId: string | null): void {
        rows.value[index].foodElementId = foodElementId;
        syncUrl();
    }

    return { rows, balance, addCritter, removeRow, setCount, setFood };
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `bunx vitest run resources/js/composables/useRanch.test.ts`
Expected: PASS (all assertions).

- [ ] **Step 5: Commit**

```bash
git add resources/js/composables/useRanch.ts resources/js/composables/useRanch.test.ts
git commit --no-gpg-sign -m "feat(ranch): useRanch composable with per-cycle balance calc"
```

---

## Task 6: RanchController + Ranch/Index page

**Files:**
- Create: `app/Http/Controllers/RanchController.php`
- Create: `resources/js/pages/Ranch/Index.vue`
- Test: `tests/Feature/Critters/RanchControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the ranch planner with a catalog and elements map', function (): void {
    makeHatch();

    $this->get('/ranch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Ranch/Index')
            ->has('catalog.families', 1)
            ->where('catalog.families.0.variants.0.baby', null)
            ->where('catalog.elements.Carbon.name', 'Coal'));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=RanchControllerTest`
Expected: FAIL — `RanchController` does not exist.

- [ ] **Step 3: Implement the controller**

`app/Http/Controllers/RanchController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Critters\CritterPresenter;
use Inertia\Inertia;
use Inertia\Response;

final class RanchController
{
    public function __construct(private readonly CritterPresenter $presenter) {}

    public function index(): Response
    {
        return Inertia::render('Ranch/Index', [
            'catalog' => $this->presenter->catalog(),
        ]);
    }
}
```

(The route was already added in Task 4.)

- [ ] **Step 4: Run the controller test to verify it passes**

Run: `php artisan test --compact --filter=RanchControllerTest`
Expected: PASS.

- [ ] **Step 5: Create the Ranch/Index page**

`resources/js/pages/Ranch/Index.vue`:

```vue
<script setup lang="ts">
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useRanch, type RanchCatalog } from '@/composables/useRanch';

defineOptions({ layout: AppLayout });

const props = defineProps<{ catalog: RanchCatalog }>();

// region state
const initialQuery = new URLSearchParams(window.location.search).get('r') ?? '';
const { rows, balance, addCritter, removeRow, setCount, setFood } = useRanch(props.catalog, initialQuery);

const search = ref('');
const allVariants = computed(() =>
    props.catalog.families.flatMap((f) => f.variants.map((v) => ({ family: f, variant: v }))),
);
const matches = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return [];
    return allVariants.value.filter((x) => x.variant.critter_id.toLowerCase().includes(q)).slice(0, 8);
});

function variantFor(critterId: string) {
    return allVariants.value.find((x) => x.variant.critter_id === critterId)?.variant ?? null;
}
function nameEn(name: Record<string, string>, fallback: string): string {
    return name['en'] ?? fallback;
}
function fmt(n: number): string {
    return (n > 0 ? '+' : '') + n.toFixed(2).replace(/\.00$/, '');
}
// endregion
</script>

<template>
    <div class="mx-auto max-w-6xl px-4 py-6">
        <h1 class="mb-4 text-2xl font-semibold">Planificateur d'élevage</h1>

        <div class="grid gap-6 lg:grid-cols-[1.6fr_1fr]">
            <!-- region Rows -->
            <section>
                <div class="relative mb-3">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Ajouter une créature…"
                        class="w-full rounded-lg border border-black/10 px-3 py-2 dark:border-white/10 dark:bg-zinc-900"
                    />
                    <ul v-if="matches.length" class="absolute z-10 mt-1 w-full rounded-lg border border-black/10 bg-white shadow dark:border-white/10 dark:bg-zinc-900">
                        <li v-for="m in matches" :key="m.variant.critter_id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-black/5 dark:hover:bg-white/5"
                                @click="addCritter(m.variant.critter_id); search = ''"
                            >
                                <img v-if="m.variant.image" :src="m.variant.image" alt="" class="h-8 w-8 object-contain" />
                                <span>{{ nameEn(m.variant.name, m.variant.critter_id) }}</span>
                                <span class="ml-auto text-xs opacity-60">{{ m.family.name }}</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <p v-if="rows.length === 0" class="rounded-lg border border-dashed border-black/15 p-6 text-center text-sm opacity-70 dark:border-white/15">
                    Ajoute une créature pour démarrer ton élevage.
                </p>

                <ul class="space-y-2">
                    <li
                        v-for="(row, i) in rows"
                        :key="i"
                        class="flex flex-wrap items-center gap-2 rounded-xl border border-black/5 p-3 ring-1 ring-white/30 ring-inset dark:border-white/5 dark:bg-zinc-900/40"
                    >
                        <img v-if="variantFor(row.critterId)?.image" :src="variantFor(row.critterId)!.image!" alt="" class="h-12 w-12 object-contain" />
                        <span class="font-medium">{{ nameEn(variantFor(row.critterId)?.name ?? {}, row.critterId) }}</span>

                        <select
                            v-if="(variantFor(row.critterId)?.adult.diets.length ?? 0) > 1"
                            class="rounded border border-black/10 px-2 py-1 text-sm dark:border-white/10 dark:bg-zinc-800"
                            :value="row.foodElementId ?? ''"
                            @change="setFood(i, ($event.target as HTMLSelectElement).value || null)"
                        >
                            <option v-for="d in variantFor(row.critterId)!.adult.diets" :key="d.consumed.element_id" :value="d.consumed.element_id">
                                {{ d.consumed.name }}
                            </option>
                        </select>
                        <span v-else-if="(variantFor(row.critterId)?.adult.diets.length ?? 0) === 0" class="rounded bg-amber-500/15 px-2 py-0.5 text-xs text-amber-700 dark:text-amber-300">
                            régime indisponible
                        </span>

                        <div class="ml-auto flex items-center gap-1">
                            <button type="button" class="h-7 w-7 rounded border border-black/10 dark:border-white/10" @click="setCount(i, row.count - 1)">−</button>
                            <input
                                type="number"
                                min="0"
                                class="w-14 rounded border border-black/10 px-2 py-1 text-center dark:border-white/10 dark:bg-zinc-800"
                                :value="row.count"
                                @input="setCount(i, Number.parseInt(($event.target as HTMLInputElement).value || '0', 10))"
                            />
                            <button type="button" class="h-7 w-7 rounded border border-black/10 dark:border-white/10" @click="setCount(i, row.count + 1)">+</button>
                            <button type="button" class="ml-2 text-sm text-red-500" @click="removeRow(i)">✕</button>
                        </div>
                    </li>
                </ul>
            </section>
            <!-- endregion -->

            <!-- region Summary -->
            <aside class="lg:sticky lg:top-6 lg:self-start">
                <div class="rounded-xl border border-black/5 p-4 ring-1 ring-white/30 ring-inset dark:border-white/5 dark:bg-zinc-900/40">
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide opacity-70">Bilan / cycle</h2>

                    <ul class="space-y-1.5">
                        <li v-for="el in balance.elements" :key="el.element_id" class="flex items-center gap-2 text-sm">
                            <img v-if="el.icon" :src="el.icon" alt="" class="h-5 w-5 object-contain" />
                            <component :is="el.url ? 'a' : 'span'" :href="el.url ?? undefined" class="truncate" :class="el.url ? 'hover:underline' : ''">
                                {{ el.name }}
                            </component>
                            <span class="ml-auto tabular-nums" :class="el.net_per_cycle >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500'">
                                {{ fmt(el.net_per_cycle) }} kg
                            </span>
                        </li>
                    </ul>

                    <div v-if="balance.eggs.length" class="mt-4 border-t border-black/5 pt-3 dark:border-white/5">
                        <h3 class="mb-1.5 text-xs font-semibold uppercase opacity-60">Œufs</h3>
                        <ul class="space-y-1 text-sm">
                            <li v-for="egg in balance.eggs" :key="egg.element_id" class="flex items-center gap-2">
                                <img v-if="egg.icon" :src="egg.icon" alt="" class="h-5 w-5 object-contain" />
                                <span class="truncate">{{ egg.label }}</span>
                                <span class="ml-auto tabular-nums">+{{ egg.amount_per_cycle.toFixed(3) }}/cy</span>
                            </li>
                        </ul>
                    </div>

                    <p v-if="!balance.elements.length && !balance.eggs.length" class="text-sm opacity-60">Aucune créature ajoutée.</p>
                </div>
            </aside>
            <!-- endregion -->
        </div>
    </div>
</template>
```

- [ ] **Step 6: Typecheck + commit**

Run: `bun run typecheck`
Expected: no errors.

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/RanchController.php resources/js/pages/Ranch/Index.vue tests/Feature/Critters/RanchControllerTest.php
git commit --no-gpg-sign -m "feat(ranch): planner page + RanchController catalog"
```

---

## Task 7: Critters/Index + Critters/Show pages + nav

**Files:**
- Create: `resources/js/pages/Critters/Index.vue`
- Create: `resources/js/pages/Critters/Show.vue`
- Modify: `resources/js/layouts/AppLayout.vue`

- [ ] **Step 1: Create the index page**

`resources/js/pages/Critters/Index.vue`:

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { CritterFamilyListItemData } from '@/types/App/Data/data';

defineOptions({ layout: AppLayout });

defineProps<{ families: CritterFamilyListItemData[] }>();
</script>

<template>
    <div class="mx-auto max-w-6xl px-4 py-6">
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Créatures</h1>
            <Link href="/ranch" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-500">
                Planificateur d'élevage
            </Link>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <Link
                v-for="family in families"
                :key="family.slug"
                :href="`/critters/${family.slug}`"
                class="codex-thumb group flex flex-col overflow-hidden rounded-xl border border-black/5 text-left ring-1 ring-white/30 transition-[transform,box-shadow] duration-200 ease-[cubic-bezier(0.22,1,0.36,1)] ring-inset hover:-translate-y-0.5 hover:shadow-md dark:border-white/5 dark:ring-white/5"
            >
                <div class="flex h-28 items-center justify-center bg-black/[0.03] dark:bg-white/[0.03]">
                    <img v-if="family.image" :src="family.image" :alt="family.name" class="h-24 w-24 object-contain transition-transform duration-300 group-hover:scale-105" />
                    <span v-else class="text-3xl opacity-30">🐾</span>
                </div>
                <div class="p-3">
                    <div class="font-medium">{{ family.name }}</div>
                    <div class="text-xs opacity-60">
                        {{ family.variant_count }} variant{{ family.variant_count > 1 ? 's' : '' }}
                        <template v-if="family.eats_summary"> · mange {{ family.eats_summary }}</template>
                    </div>
                </div>
            </Link>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Create the show page**

`resources/js/pages/Critters/Show.vue`:

```vue
<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { CritterFamilyData } from '@/types/App/Data/data';

defineOptions({ layout: AppLayout });

const props = defineProps<{ family: CritterFamilyData }>();

const selectedIndex = ref(0);
const variant = computed(() => props.family.variants[selectedIndex.value]);
const adult = computed(() => variant.value.adult);

function nameEn(name: Record<string, string>, fallback: string): string {
    return name['en'] ?? fallback;
}
function tempC(k: number): string {
    return `${(k - 273.15).toFixed(0)} °C`;
}
function lifespanLabel(n: number): string {
    return n > 0 ? `${n} cycles` : '—';
}
function addToRanch(): void {
    router.get('/ranch', { r: `${variant.value.critter_id}.:1` });
}
</script>

<template>
    <div class="mx-auto max-w-5xl px-4 py-6">
        <Link href="/critters" class="text-sm opacity-60 hover:underline">← Créatures</Link>

        <!-- region Hero -->
        <header class="mt-3 flex flex-wrap items-center gap-5">
            <div class="flex h-32 w-32 items-center justify-center rounded-2xl bg-black/[0.03] dark:bg-white/[0.03]">
                <img v-if="variant.image" :src="variant.image" :alt="family.name" class="h-28 w-28 object-contain" />
                <span v-else class="text-5xl opacity-30">🐾</span>
            </div>
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-semibold">{{ family.name }}</h1>
                <p v-if="family.description" class="mt-1 max-w-prose text-sm opacity-70">{{ family.description['en'] }}</p>
            </div>
            <button type="button" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500" @click="addToRanch">
                + Ajouter à l'élevage
            </button>
        </header>
        <!-- endregion -->

        <!-- region Variant tabs -->
        <div v-if="family.variants.length > 1" class="mt-5 flex flex-wrap gap-2">
            <button
                v-for="(v, i) in family.variants"
                :key="v.critter_id"
                type="button"
                class="rounded-full px-3 py-1 text-sm ring-1 ring-inset"
                :class="i === selectedIndex ? 'bg-emerald-600 text-white ring-emerald-600' : 'ring-black/10 hover:bg-black/5 dark:ring-white/10 dark:hover:bg-white/5'"
                @click="selectedIndex = i"
            >
                {{ nameEn(v.name, v.critter_id) }}
            </button>
        </div>
        <!-- endregion -->

        <!-- region Panels -->
        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <section class="rounded-xl border border-black/5 p-4 dark:border-white/5">
                <h2 class="mb-2 text-sm font-semibold uppercase opacity-60">Stats</h2>
                <dl class="grid grid-cols-2 gap-1 text-sm">
                    <dt class="opacity-60">Température</dt><dd>{{ tempC(adult.min_temp) }} – {{ tempC(adult.max_temp) }}</dd>
                    <dt class="opacity-60">Calories/cycle</dt><dd>{{ adult.calories_per_cycle.toLocaleString() }}</dd>
                    <dt class="opacity-60">Surpopulation</dt><dd>{{ adult.overcrowding_threshold }} tuiles</dd>
                    <dt class="opacity-60">Durée de vie</dt><dd>{{ lifespanLabel(adult.lifespan) }}</dd>
                    <dt class="opacity-60">Incubation</dt><dd>{{ adult.incubation_time }} cycles</dd>
                </dl>
            </section>

            <section class="rounded-xl border border-black/5 p-4 dark:border-white/5">
                <h2 class="mb-2 text-sm font-semibold uppercase opacity-60">Régime</h2>
                <p v-if="adult.diets.length === 0" class="text-sm opacity-60">Données de régime indisponibles.</p>
                <ul v-else class="space-y-1.5 text-sm">
                    <li v-for="d in adult.diets" :key="d.consumed.element_id" class="flex items-center gap-2">
                        <img v-if="d.consumed.icon" :src="d.consumed.icon" alt="" class="h-5 w-5 object-contain" />
                        <component :is="d.consumed.url ? 'a' : 'span'" :href="d.consumed.url ?? undefined" :class="d.consumed.url ? 'hover:underline' : ''">{{ d.consumed.name }}</component>
                        <span class="opacity-50">→</span>
                        <template v-if="d.produced">
                            <img v-if="d.produced.icon" :src="d.produced.icon" alt="" class="h-5 w-5 object-contain" />
                            <component :is="d.produced.url ? 'a' : 'span'" :href="d.produced.url ?? undefined" :class="d.produced.url ? 'hover:underline' : ''">{{ d.produced.name }}</component>
                        </template>
                        <span class="ml-auto tabular-nums opacity-70">{{ d.amount_per_cycle }} kg/cy</span>
                    </li>
                </ul>
            </section>

            <section class="rounded-xl border border-black/5 p-4 dark:border-white/5 sm:col-span-2">
                <h2 class="mb-2 text-sm font-semibold uppercase opacity-60">Production</h2>
                <ul class="space-y-1.5 text-sm">
                    <li v-for="o in adult.outputs" :key="o.element_id" class="flex items-center gap-2">
                        <img v-if="o.icon" :src="o.icon" alt="" class="h-5 w-5 object-contain" />
                        <component :is="o.url ? 'a' : 'span'" :href="o.url ?? undefined" :class="o.url ? 'hover:underline' : ''">{{ o.label }}</component>
                        <span class="ml-auto tabular-nums opacity-70">{{ o.amount_per_cycle.toFixed(3) }} /cy</span>
                    </li>
                    <li v-if="adult.outputs.length === 0" class="opacity-60">—</li>
                </ul>
            </section>
        </div>
        <!-- endregion -->
    </div>
</template>
```

- [ ] **Step 3: Enable nav entries**

In `resources/js/layouts/AppLayout.vue`, find the `navItems` array. The Critters entry exists with `available: false` — change it to `available: true`. Then add a Ranch entry right after it:

```ts
{ key: 'critters', label: 'Créatures', href: '/critters', available: true },
{ key: 'ranch', label: 'Élevage', href: '/ranch', available: true },
```

(Match the exact `NavItem` shape used by the surrounding entries — copy a sibling's keys. Keep ordering sensible; Ranch directly after Critters.)

- [ ] **Step 4: Typecheck**

Run: `bun run typecheck`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Critters/Index.vue resources/js/pages/Critters/Show.vue resources/js/layouts/AppLayout.vue
git commit --no-gpg-sign -m "feat(critters): index/show pages + nav entries"
```

---

## Task 8: Design polish + full verification gates

**Files:** (polish only — touch the three Vue pages as needed)

- [ ] **Step 1: Design polish pass**

Activate the `tailwindcss-development` skill (and optionally `impeccable`/`frontend-design`) and refine the three pages for production quality: visual hierarchy on the family page hero, consistent card styling between Critters/Index and the Codex tiles, the planner's net-balance bars (add a proportional bar element behind each element row using the max abs value), dark-mode parity, empty/hover/focus states, and responsive behavior (summary stacks under rows below `lg`). Keep all `lang="ts"`, region markers, and existing class conventions. Do not change calc logic or props.

Add proportional net bars in `Ranch/Index.vue` summary: compute `maxAbs = Math.max(1, ...balance.elements.map(e => Math.abs(e.net_per_cycle)))` and render a div whose width is `Math.abs(net)/maxAbs*100 + '%'`, colored emerald for positive / red for negative.

- [ ] **Step 2: Manual browser verification**

Ensure the dev server is running (it runs continuously with HMR — do not build). Use the project URL `http://localhost:8003`. With Playwright/Boost, load and confirm:
- `/critters` — family grid renders, animations show, tiles link.
- `/critters/hatch` — hero animation, variant tabs switch stats/diet, diet chips link to codex/element, "+ Ajouter à l'élevage" navigates to `/ranch?r=Hatch.:1`.
- `/ranch` — add Hatch + Pacu, change count/food, watch net balance + eggs update live; reload preserves state from URL; element chips deep-link.

Record any issue and fix before proceeding.

- [ ] **Step 3: Front gates**

Run: `bunx vitest run`
Expected: all green.
Run: `bun run typecheck`
Expected: no errors.
Run: `bun run test:lint`
Expected: clean (fix with `bun run lint` if needed).

- [ ] **Step 4: Backend gates**

Run: `vendor/bin/pint --dirty --format agent` → clean.
Run: `composer test`
Expected: green. Note `composer test` enforces `pest --type-coverage --min=100` and `pest --coverage --exactly=100.0` plus `phpstan`. If coverage < 100%, add the missing tests (e.g. cover `CritterPresenter` branches: egg vs non-egg output, null produced element, codex-url vs element-url, missing image → null icon, singleton family with null `variant_family`). If phpstan reports issues, fix types. Do not lower the thresholds.

- [ ] **Step 5: Final commit**

```bash
git add -A
git commit --no-gpg-sign -m "feat(critters): polish pages and pass full verification gates"
```

---

## Self-Review Notes

- **Spec coverage:** routing (Task 4/6); DTOs incl. element/egg refs with codex-preferred url + icon existence (Task 2/3); `CritterPresenter` family/catalog/list (Task 3); critter family page with variant switcher, stats, diet, outputs, lifecycle (Task 7); index tiles (Task 7); two-column planner with food selection, count stepper, net per-element bars, eggs, URL-shareable state (Task 5/6/8); one-food-per-critter + conversion + best-effort empty diets (Task 5); element icons + codex liaison (Task 3 refs, rendered Task 6/7); nav (Task 7); testing incl. vitest calc + Pest controllers/presenter and the 100%-coverage/phpstan gates (Task 8).
- **Type consistency:** DTO names (`ElementRefData`, `CritterDietData`, `CritterOutputData`, `CritterMorphData`, `CritterVariantData`, `CritterFamilyData`, `CritterFamilyListItemData`, `RanchCatalogData`) match across PHP, generated TS, composable, and pages. `CritterPresenter` methods `family()/familyList()/catalog()` match controller calls. `useRanch` exports (`computeBalance`, `encodeRows`, `decodeRows`, `chosenDiet`, `useRanch`, types `RanchRow`/`RanchCatalog`/`RanchBalance`) match the test and page imports. URL param is `r` everywhere; encoding `critterId.food:count` matches encode/decode.
- **Known follow-ups (out of scope, pre-existing):** `incubation_time` stores cycles despite the name; `dlc_id` extracted but unused. Not addressed here.
