# Critters Index/Show Visual Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring `/critters` and `/critters/{family}` to the same visual/interaction quality as `/buildings` — filterable animated grid, correct display names, graceful missing-artwork fallback — using only data already available after two backend bugfixes.

**Architecture:** Two small backend fixes in `App\Services\GameImport\CritterImporter` and `App\Services\Critters\CritterPresenter` unlock correct `dlc_id` and display-name data with no schema change; `CritterFamilyListItemData` grows three fields (`dlc_id`, `variant_images`, `min_temp`/`max_temp`); `Critters/Index.vue` is rewritten as a flat filterable grid mirroring `Buildings/Index.vue`'s toolbar/animation language.

**Tech Stack:** Laravel 13 / PHP 8.5, spatie/laravel-data (typescript-transformer), Pest, Inertia v3 + Vue 3 + TypeScript, Tailwind v4.

## Global Constraints

- Every Vue SFC must have `lang="ts"`.
- No `Co-Authored-By` trailers and no GPG signing in commits made during this work — use `git -c commit.gpgsign=false commit`.
- New code you write must have 100% type coverage and 100% line coverage — every new branch needs a covering test.
- Run `vendor/bin/pint --dirty --format agent` after any PHP edit, before considering a task done.
- No dependency changes (composer.json/package.json) — this plan touches none.
- Follow existing code conventions in sibling files (PHPDoc array shapes, `final` classes/readonly where already used, explicit return types).

**Known pre-existing baseline failures (confirmed on `main` before this plan
started, zero relation to critters/DLC/naming work — do NOT attempt to fix
these, they are out of scope):**

- `vendor/bin/phpstan analyse` reports 18 errors: 1 in
  `app/Http/Controllers/GeyserController.php:12`, 17 in
  `app/Services/Critters/CritterPresenter.php` (lines 39, 53, 75, 97, 130,
  142, 152, 154, 164, 171, 177, 214, 216×2, 226, 234, 284 — as of the
  pre-plan baseline; line numbers shift as this plan edits that file).
  Default `memory_limit` (256M) can crash phpstan on a cold cache — if that
  happens, retry with `php -d memory_limit=1G vendor/bin/phpstan analyse`.
- `pint --test` fails on `public/frankenphp-worker.php` (unrelated Octane
  file, never formatted).
- `php artisan test --compact` (full suite) has 4 pre-existing failures: a
  Dusk `WelcomeTest`, and 3 Arch-preset violations (`TypeScriptTransformerServiceProvider`
  has protected methods; `BuildingImporter` is used by `BuildingController`,
  flagged twice under two Arch presets).

**Rule for this plan:** verify each task's own new code has full type/line
coverage and introduces no *new* phpstan/pint/test failures beyond the list
above — do not chase the pre-existing baseline issues to green, and do not
run the full `composer test` as a pass/fail gate (it currently fails
regardless of this work). Scope every verification run to the files/tests
each task actually touches.

---

## Task 1: Fix `dlc_id` import bug

**Files:**
- Modify: `app/Services/GameImport/CritterImporter.php:24`
- Test: `tests/Feature/GameImport/CritterImporterTest.php`

**Interfaces:**
- Consumes: nothing new — reads `$raw['dlc_id']` which the extractor already emits (`CritterExtractor.cs` → `CritterDto.DlcId`, JSON key `dlc_id`).
- Produces: `Critter::$dlc_id` is now populated from import. No signature changes to `CritterImporter::import()`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/GameImport/CritterImporterTest.php`:

```php
it('imports dlc_id', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'crit').'.json';
    file_put_contents($path, json_encode([[
        'critter_id' => 'AlgaeStego', 'is_base' => true,
        'min_temp' => 283.15, 'max_temp' => 313.15,
        'calories_per_cycle' => 700000, 'incubation_time' => 20, 'lifespan' => 100,
        'overcrowding_threshold' => 8, 'name_localization_id' => null,
        'variant_family' => 'AlgaeStego', 'dlc_id' => 'DLC5',
        'diets' => [], 'outputs' => [],
    ]]));

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new CritterImporter($resolver)->import($path);

    $critter = Critter::where('critter_id', 'AlgaeStego')->firstOrFail();
    expect($critter->dlc_id)->toBe('DLC5');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="imports dlc_id"`
Expected: FAIL — `expect($critter->dlc_id)->toBe('DLC5')` fails because `dlc_id` is `null`.

- [ ] **Step 3: Fix the importer**

In `app/Services/GameImport/CritterImporter.php`, change line 24 from:

```php
            $critter = Critter::query()->firstOrCreate(['critter_id' => $parentId], ['name' => ['en' => $parentId]]);
```

to:

```php
            $dlcId = is_string($raw['dlc_id'] ?? null) ? $raw['dlc_id'] : null;

            $critter = Critter::query()->firstOrCreate(
                ['critter_id' => $parentId],
                ['name' => ['en' => $parentId], 'dlc_id' => $dlcId],
            );

            if ($dlcId !== null && $critter->dlc_id !== $dlcId) {
                $critter->update(['dlc_id' => $dlcId]);
            }
```

(The `update` call mirrors the existing `variant_family` handling two lines
below it — needed because `firstOrCreate`'s second argument only applies on
first creation; later morphs of an already-created parent critter must still
be able to backfill `dlc_id` if the base-morph JSON entry is processed after
a baby entry.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="imports dlc_id"`
Expected: PASS

- [ ] **Step 5: Run the full importer test file to check no regression**

Run: `php artisan test --compact tests/Feature/GameImport/CritterImporterTest.php`
Expected: all tests PASS (3 total)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/GameImport/CritterImporter.php tests/Feature/GameImport/CritterImporterTest.php
git -c commit.gpgsign=false commit -m "fix(import): populate critter dlc_id from extractor JSON"
```

---

## Task 2: Fix display names (`CritterPresenter`)

**Files:**
- Modify: `app/Services/Critters/CritterPresenter.php:136-157` (methods `familyName`, `variantData`; add `variantName`)
- Test: `tests/Feature/Critters/CritterPresenterTest.php`

**Interfaces:**
- Consumes: `Critter::$morphs` (already eager-loaded by `critterQuery()`), `CritterMorph::getTranslations('name')` / `getTranslation('name', 'en')` (existing `HasTranslations` API).
- Produces: `familyName(array $variants): string` — same signature, corrected value (base morph's resolved name, falling back to `variant_family` then `critter_id`). New private method `variantName(Critter $critter, ?CritterMorph $adult): array` returning `array<string, string>`, used by `variantData()` for `CritterVariantData::$name`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Critters/CritterPresenterTest.php` currently only imports
`CritterPresenter` and `RefreshDatabase` — the new tests below construct
`Critter`/`CritterMorph` directly, so first add those two imports at the top
of the file (after line 4, before the existing `use App\Services\Critters\CritterPresenter;`):

```php
use App\Models\Critter;
use App\Models\CritterMorph;
```

Then add to `tests/Feature/Critters/CritterPresenterTest.php` (after the existing `it('lists families with variant counts', ...)` block):

```php
it('uses the resolved base-morph name for the family, not the raw variant_family', function (): void {
    $critter = Critter::factory()->create(['critter_id' => 'OilFloater', 'variant_family' => 'OilFloater']);
    CritterMorph::factory()->create([
        'critter_id' => $critter->id,
        'morph_id' => 'OilFloater',
        'is_base' => true,
        'name' => ['en' => 'Oil Floater'],
    ]);

    $list = app(CritterPresenter::class)->familyList();

    expect($list)->toHaveCount(1)
        ->and($list[0]->name)->toBe('Oil Floater');
});

it('falls back to variant_family when no morph is present', function (): void {
    Critter::factory()->create(['critter_id' => 'Ghost', 'variant_family' => 'Ghost']);

    $list = app(CritterPresenter::class)->familyList();

    expect($list)->toHaveCount(1)
        ->and($list[0]->name)->toBe('Ghost');
});

it('falls back to critter_id when there is no variant_family and no morph', function (): void {
    Critter::factory()->create(['critter_id' => 'Solo', 'variant_family' => null]);

    $list = app(CritterPresenter::class)->familyList();

    expect($list)->toHaveCount(1)
        ->and($list[0]->name)->toBe('Solo');
});

it('resolves the variant tab name from the base morph, not the raw critter name', function (): void {
    $critter = Critter::factory()->create(['critter_id' => 'HatchHard', 'variant_family' => 'Hatch', 'name' => ['en' => 'HatchHard']]);
    CritterMorph::factory()->create([
        'critter_id' => $critter->id,
        'morph_id' => 'HatchHard',
        'is_base' => true,
        'name' => ['en' => 'Hatch Hard'],
    ]);

    $family = app(CritterPresenter::class)->family('hatch');

    expect($family)->not->toBeNull()
        ->and($family->variants[0]->name)->toBe(['en' => 'Hatch Hard']);
});

it('falls back to critter_id for the variant name when the base morph name is unresolved', function (): void {
    $critter = Critter::factory()->create(['critter_id' => 'Mystery', 'variant_family' => 'Mystery']);
    CritterMorph::factory()->create([
        'critter_id' => $critter->id,
        'morph_id' => 'Mystery',
        'is_base' => true,
        'name' => [],
    ]);

    $family = app(CritterPresenter::class)->family('mystery');

    expect($family)->not->toBeNull()
        ->and($family->variants[0]->name)->toBe(['en' => 'Mystery']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="resolved base-morph name|falls back to variant_family|falls back to critter_id when there is no variant_family|resolves the variant tab name|falls back to critter_id for the variant name"`
Expected: FAIL on the new assertions (current code returns `'OilFloater'` instead of `'Oil Floater'`, and `['en' => 'HatchHard']` instead of `['en' => 'Hatch Hard']`).

- [ ] **Step 3: Implement the fix**

In `app/Services/Critters/CritterPresenter.php`, replace lines 136-157:

```php
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
```

with:

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Critters/CritterPresenterTest.php`
Expected: all tests PASS (9 total)

- [ ] **Step 5: Run the controller tests too (they exercise the same presenter)**

Run: `php artisan test --compact tests/Feature/Critters/CritterControllerTest.php tests/Feature/Critters/RanchControllerTest.php`
Expected: all PASS (no behavior change for the `Hatch` fixture used there — `makeHatch()` doesn't set an explicit morph name, so the base-morph resolution just returns whatever the factory's fake word is, and no existing test asserts `family.name` or `variants.0.name`, so nothing regresses)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Critters/CritterPresenter.php tests/Feature/Critters/CritterPresenterTest.php
git -c commit.gpgsign=false commit -m "fix(critters): resolve display names from base morph instead of raw ids"
```

---

## Task 3: Add `dlc_id`, `variant_images`, `min_temp`/`max_temp` to the index DTO

**Files:**
- Modify: `app/Data/CritterFamilyListItemData.php`
- Modify: `app/Services/Critters/CritterPresenter.php:50-70` (`familyList` method; add `variantPreviewImages` and `familyTempRange` helpers)
- Test: `tests/Feature/Critters/CritterPresenterTest.php`
- Generated (do not hand-edit): `resources/js/types/App/Data/data.d.ts`, `resources/js/typescript-transformer-manifest.json`

**Interfaces:**
- Consumes: `Critter::$dlc_id` (now correctly populated per Task 1), `CritterMorph::$min_temp`/`$max_temp` (existing columns), `critterImage(string): ?string` (existing private helper).
- Produces: `CritterFamilyListItemData` gains `dlc_id: ?string`, `variant_images: list<string>`, `min_temp: float`, `max_temp: float`. `familyList()`'s return shape changes accordingly — this is what Task 4's `Critters/Index.vue` consumes.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Critters/CritterPresenterTest.php`:

```php
it('includes dlc_id, temperature range, and other-variant image previews in the family list', function (): void {
    $tempPublic = sys_get_temp_dir().'/ony-planner-test-public';
    @mkdir($tempPublic.'/assets/game/critters', 0777, true);
    file_put_contents($tempPublic.'/assets/game/critters/PufferFish.webp', 'webp');

    $base = Critter::factory()->create(['critter_id' => 'PacuTropical', 'variant_family' => 'Pacu', 'dlc_id' => 'DLC5']);
    CritterMorph::factory()->create(['critter_id' => $base->id, 'morph_id' => 'PacuTropical', 'is_base' => true, 'min_temp' => 290.0, 'max_temp' => 305.0]);

    $other = Critter::factory()->create(['critter_id' => 'PufferFish', 'variant_family' => 'Pacu', 'dlc_id' => 'DLC5']);
    CritterMorph::factory()->create(['critter_id' => $other->id, 'morph_id' => 'PufferFish', 'is_base' => true, 'min_temp' => 275.0, 'max_temp' => 300.0]);

    $list = app(CritterPresenter::class)->familyList();

    expect($list)->toHaveCount(1);
    $family = $list[0];
    expect($family->dlc_id)->toBe('DLC5')
        ->and($family->min_temp)->toBe(275.0)
        ->and($family->max_temp)->toBe(305.0)
        ->and($family->variant_images)->toBe(['/assets/game/critters/PufferFish.webp']);

    @unlink($tempPublic.'/assets/game/critters/PufferFish.webp');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="includes dlc_id, temperature range"`
Expected: FAIL — constructor error (`CritterFamilyListItemData` doesn't accept `dlc_id`/`variant_images`/`min_temp`/`max_temp` yet).

- [ ] **Step 3: Update the DTO**

Replace the full contents of `app/Data/CritterFamilyListItemData.php`:

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
        public ?string $dlc_id,
        /** @var list<string> */
        public array $variant_images,
        public float $min_temp,
        public float $max_temp,
    ) {}
}
```

- [ ] **Step 4: Wire the new fields in `CritterPresenter`**

In `app/Services/Critters/CritterPresenter.php`, replace the `familyList()` method (lines 50-70):

```php
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
```

Then add two new private methods right after `familyName()` (i.e. after the closing brace that now ends around line 150, before `variantData()`):

```php
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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Critters/CritterPresenterTest.php`
Expected: all tests PASS (10 total)

- [ ] **Step 6: Run the full suite to check for regressions in dependent tests**

Run: `php artisan test --compact tests/Feature/Critters/`
Expected: all PASS — `CritterControllerTest`'s existing assertions only check `slug`/`variant_count`, unaffected by the new fields.

- [ ] **Step 7: Regenerate TypeScript types**

Run: `php artisan typescript:transform`
Expected: `resources/js/types/App/Data/data.d.ts`'s `CritterFamilyListItemData` type now includes the four new fields; `resources/js/typescript-transformer-manifest.json`'s hash changes. Verify with:

```bash
rg -n "CritterFamilyListItemData" -A 12 resources/js/types/App/Data/data.d.ts
```

Expected output includes `dlc_id: string | null,`, `variant_images: Array<string>,`, `min_temp: number,`, `max_temp: number,` alongside the existing fields.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/CritterFamilyListItemData.php app/Services/Critters/CritterPresenter.php \
    tests/Feature/Critters/CritterPresenterTest.php resources/js/types/App/Data/data.d.ts \
    resources/js/typescript-transformer-manifest.json
git -c commit.gpgsign=false commit -m "feat(critters): expose dlc_id, temperature range, and variant preview images on the family list"
```

---

## Task 4: Redesign `Critters/Index.vue`

**Files:**
- Modify: `resources/js/pages/Critters/Index.vue` (full rewrite)

**Interfaces:**
- Consumes: `CritterFamilyListItemData[]` (from Task 3: `slug`, `name`, `image`, `variant_count`, `eats_summary`, `makes_summary`, `dlc_id`, `variant_images`, `min_temp`, `max_temp`).
- Produces: no new exports — this is a leaf page component. No other file imports from it.

There is no dedicated frontend test file for this page (matching the existing
convention — `Buildings/Index.vue`, the reference-quality page, also has no
unit test; its filter/grid logic is page-local, not extracted into a
composable, so it isn't covered by the vitest suite). Verification is
`bun run typecheck` plus the manual/screenshot check in Task 5.

- [ ] **Step 1: Replace the full contents of `resources/js/pages/Critters/Index.vue`**

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { CritterFamilyListItemData } from '@/types/App/Data/data.d';

defineOptions({ layout: AppLayout });

const props = defineProps<{ families: CritterFamilyListItemData[] }>();

// region Filters
const search = ref('');

type TempBucket = 'cold' | 'temperate' | 'hot';

const TEMP_BUCKETS: { key: TempBucket; label: string }[] = [
    { key: 'cold', label: 'Froid' },
    { key: 'temperate', label: 'Tempéré' },
    { key: 'hot', label: 'Chaud' },
];

const activeTemp = ref<TempBucket | null>(null);
const activeDiet = ref<string | null>(null);
const activeDlc = ref(false);

function toCelsius(kelvin: number): number {
    return kelvin - 273.15;
}

function matchesTempBucket(family: CritterFamilyListItemData, bucket: TempBucket): boolean {
    const minC = toCelsius(family.min_temp);
    const maxC = toCelsius(family.max_temp);
    if (bucket === 'cold') return minC < 15;
    if (bucket === 'hot') return maxC > 35;
    return maxC >= 15 && minC <= 35;
}

const dietOptions = computed<string[]>(() => {
    const set = new Set<string>();
    for (const f of props.families) {
        if (f.eats_summary) set.add(f.eats_summary);
    }
    return [...set].sort((a, b) => a.localeCompare(b));
});

const hasDlcContent = computed<boolean>(() => props.families.some((f) => f.dlc_id !== null));

const filtered = computed<CritterFamilyListItemData[]>(() => {
    const q = search.value.toLowerCase();
    return props.families.filter((f) => {
        const matchSearch = !q || f.name.toLowerCase().includes(q) || f.slug.includes(q);
        const matchTemp = activeTemp.value === null || matchesTempBucket(f, activeTemp.value);
        const matchDiet = activeDiet.value === null || f.eats_summary === activeDiet.value;
        const matchDlc = !activeDlc.value || f.dlc_id !== null;
        return matchSearch && matchTemp && matchDiet && matchDlc;
    });
});
// endregion

// region Tinting
function familyHue(slug: string): number {
    let hash = 0;
    for (let i = 0; i < slug.length; i++) {
        hash = (hash * 31 + slug.charCodeAt(i)) >>> 0;
    }
    return hash % 360;
}
// endregion
</script>

<template>
    <div class="min-h-full pb-16 md:pb-0">
        <!-- region Sticky toolbar -->
        <header
            class="sticky top-0 z-10 border-b border-[oklch(82%_0.025_75)] bg-[oklch(94%_0.015_75)]/90 backdrop-blur-sm dark:border-[oklch(32%_0.020_250)] dark:bg-[oklch(22%_0.016_250)]/95"
        >
            <div class="mx-auto max-w-5xl px-5 py-4">
                <div class="flex items-baseline justify-between gap-3">
                    <h1 class="text-base font-semibold tracking-tight">Créatures</h1>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-[oklch(42%_0.015_250)] tabular-nums dark:text-[oklch(66%_0.012_260)]">
                            {{ filtered.length
                            }}<span v-if="filtered.length !== families.length"> / {{ families.length }}</span>
                        </span>
                        <Link
                            href="/ranch"
                            class="flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-all hover:bg-emerald-500 hover:-translate-y-px hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 active:scale-[0.97]"
                        >
                            <svg width="12" height="12" viewBox="0 0 18 18" fill="none">
                                <rect x="2" y="5" width="14" height="9" rx="1" stroke="currentColor" stroke-width="1.4" />
                                <path d="M2 8h14M2 11h14" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" />
                                <path d="M6 5v9M12 5v9" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" />
                            </svg>
                            Planificateur d'élevage
                        </Link>
                    </div>
                </div>

                <!-- Search -->
                <input
                    v-model="search"
                    type="search"
                    placeholder="Rechercher une créature…"
                    class="mt-2.5 w-full rounded-lg border border-[oklch(88%_0.01_250)] bg-[oklch(99%_0.003_260)] px-3 py-2 text-sm placeholder:text-[oklch(58%_0.01_250)] focus:border-oni-amber focus:ring-1 focus:ring-oni-amber focus:outline-none dark:border-[oklch(35%_0.015_260)] dark:bg-[oklch(18%_0.015_260)] dark:placeholder:text-[oklch(52%_0.012_260)]"
                />

                <!-- Filter chips -->
                <div class="mt-2.5 flex flex-wrap gap-1.5">
                    <button
                        class="rounded px-2.5 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-oni-amber focus-visible:outline-none"
                        :class="
                            activeTemp === null
                                ? 'bg-[oklch(16%_0.012_250)] text-[oklch(97%_0.005_260)] dark:bg-[oklch(88%_0.008_260)] dark:text-[oklch(14%_0.01_260)]'
                                : 'text-[oklch(42%_0.015_250)] hover:bg-[oklch(90%_0.01_250)] dark:text-[oklch(66%_0.012_260)] dark:hover:bg-[oklch(30%_0.015_260)]'
                        "
                        @click="activeTemp = null"
                    >
                        Toutes températures
                    </button>
                    <button
                        v-for="bucket in TEMP_BUCKETS"
                        :key="bucket.key"
                        class="rounded px-2.5 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-oni-amber focus-visible:outline-none"
                        :class="
                            activeTemp === bucket.key
                                ? 'bg-[oklch(16%_0.012_250)] text-[oklch(97%_0.005_260)] dark:bg-[oklch(88%_0.008_260)] dark:text-[oklch(14%_0.01_260)]'
                                : 'text-[oklch(42%_0.015_250)] hover:bg-[oklch(90%_0.01_250)] dark:text-[oklch(66%_0.012_260)] dark:hover:bg-[oklch(30%_0.015_260)]'
                        "
                        @click="activeTemp = activeTemp === bucket.key ? null : bucket.key"
                    >
                        {{ bucket.label }}
                    </button>

                    <span class="mx-1 self-center text-[oklch(70%_0.01_250)] dark:text-[oklch(45%_0.012_260)]">·</span>

                    <button
                        v-for="diet in dietOptions"
                        :key="diet"
                        class="rounded px-2.5 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-oni-amber focus-visible:outline-none"
                        :class="
                            activeDiet === diet
                                ? 'bg-[oklch(16%_0.012_250)] text-[oklch(97%_0.005_260)] dark:bg-[oklch(88%_0.008_260)] dark:text-[oklch(14%_0.01_260)]'
                                : 'text-[oklch(42%_0.015_250)] hover:bg-[oklch(90%_0.01_250)] dark:text-[oklch(66%_0.012_260)] dark:hover:bg-[oklch(30%_0.015_260)]'
                        "
                        @click="activeDiet = activeDiet === diet ? null : diet"
                    >
                        Mange {{ diet }}
                    </button>

                    <template v-if="hasDlcContent">
                        <span class="mx-1 self-center text-[oklch(70%_0.01_250)] dark:text-[oklch(45%_0.012_260)]">·</span>
                        <button
                            class="rounded px-2.5 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-oni-amber focus-visible:outline-none"
                            :class="
                                activeDlc
                                    ? 'bg-[oklch(16%_0.012_250)] text-[oklch(97%_0.005_260)] dark:bg-[oklch(88%_0.008_260)] dark:text-[oklch(14%_0.01_260)]'
                                    : 'text-[oklch(42%_0.015_250)] hover:bg-[oklch(90%_0.01_250)] dark:text-[oklch(66%_0.012_260)] dark:hover:bg-[oklch(30%_0.015_260)]'
                            "
                            @click="activeDlc = !activeDlc"
                        >
                            Aquatic Pack
                        </button>
                    </template>
                </div>
            </div>
        </header>
        <!-- endregion -->

        <!-- region Grid -->
        <main class="mx-auto max-w-5xl px-5 py-6">
            <p
                v-if="filtered.length === 0"
                class="mt-24 text-center text-sm text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
            >
                Aucune créature trouvée.
            </p>

            <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <Link
                    v-for="(family, i) in filtered"
                    :key="family.slug"
                    :href="`/critters/${family.slug}`"
                    class="critter-tile group relative flex flex-col overflow-hidden rounded-xl border border-black/5 text-left ring-1 ring-white/30 transition-[transform,box-shadow] duration-200 ease-[cubic-bezier(0.22,1,0.36,1)] ring-inset hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-oni-amber dark:border-white/5 dark:ring-white/5"
                    :style="{ '--tile-hue': String(familyHue(family.slug)), animationDelay: `${i * 22}ms` }"
                >
                    <div class="relative flex h-28 items-center justify-center">
                        <img
                            v-if="family.image"
                            :src="family.image"
                            :alt="family.name"
                            class="h-24 w-24 object-contain drop-shadow-sm transition-transform duration-300 group-hover:scale-110"
                        />
                        <svg
                            v-else
                            viewBox="0 0 96 96"
                            fill="none"
                            class="h-16 w-16 opacity-40 transition-transform duration-300 group-hover:scale-110"
                            style="color: oklch(45% 0.08 var(--tile-hue))"
                        >
                            <ellipse cx="48" cy="58" rx="28" ry="20" stroke="currentColor" stroke-width="3" />
                            <circle cx="39" cy="55" r="3.2" fill="currentColor" />
                            <circle cx="57" cy="55" r="3.2" fill="currentColor" />
                            <path d="M21 60c-8 2.5-13 8-10 13" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                            <path d="M75 60c8 2.5 13 8 10 13" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                            <path d="M33 40c-3-8 1-16 8-19" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                            <path d="M63 40c3-8-1-16-8-19" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                        </svg>

                        <!-- Other-variant preview stack -->
                        <div v-if="family.variant_images.length > 0" class="absolute right-1.5 bottom-1.5 flex -space-x-2">
                            <img
                                v-for="(img, vi) in family.variant_images"
                                :key="img"
                                :src="img"
                                alt=""
                                class="h-6 w-6 rounded-full border-2 border-[oklch(98%_0.01_260)] bg-[oklch(98%_0.01_260)] object-contain shadow-sm dark:border-[oklch(24%_0.02_260)] dark:bg-[oklch(24%_0.02_260)]"
                                :style="{ zIndex: 10 - vi }"
                            />
                        </div>

                        <span
                            v-if="family.dlc_id"
                            class="absolute top-1.5 left-1.5 rounded bg-[oklch(80%_0.14_80)] px-1 py-0.5 text-[8px] font-bold text-[oklch(28%_0.10_60)] dark:bg-[oklch(40%_0.12_80)] dark:text-[oklch(90%_0.08_80)]"
                            >DLC</span
                        >
                    </div>
                    <div class="border-t border-[oklch(0%_0_0/0.06)] px-3 py-2.5 dark:border-[oklch(100%_0_0/0.06)]">
                        <div class="truncate font-semibold text-[oklch(22%_0.025_60)] dark:text-[oklch(92%_0.012_65)]">
                            {{ family.name }}
                        </div>
                        <div class="mt-0.5 truncate text-xs text-[oklch(50%_0.015_250)] dark:text-[oklch(60%_0.012_260)]">
                            {{ family.variant_count }} variant{{ family.variant_count > 1 ? 's' : '' }}
                            <template v-if="family.eats_summary"> · mange {{ family.eats_summary }}</template>
                        </div>
                    </div>
                </Link>
            </div>
        </main>
        <!-- endregion -->
    </div>
</template>

<style scoped>
.critter-tile {
    background-image: linear-gradient(160deg, oklch(97% 0.02 var(--tile-hue)), oklch(93% 0.045 var(--tile-hue)));
    animation: critter-tile-in 280ms cubic-bezier(0.22, 1, 0.36, 1) both;
}

@media (prefers-color-scheme: dark) {
    .critter-tile {
        background-image: linear-gradient(160deg, oklch(27% 0.03 var(--tile-hue)), oklch(22% 0.028 var(--tile-hue)));
    }
}

@keyframes critter-tile-in {
    from {
        opacity: 0;
        transform: translateY(8px) scale(0.98);
    }
}

@media (prefers-reduced-motion: reduce) {
    .critter-tile {
        animation: none;
    }
}
</style>
```

- [ ] **Step 2: Typecheck**

Run: `bun run typecheck`
Expected: no errors (exit 0). If `CritterFamilyListItemData` fields are
missing/misnamed, this is where it surfaces — re-check Task 3 Step 7 ran
successfully.

- [ ] **Step 3: Format**

Run: `bun run lint`
Expected: no diff / exits clean (this project's `lint` script auto-fixes
formatting via `vp fmt`; re-run and `git diff` to confirm it settled).

- [ ] **Step 4: Manual smoke check**

With the Octane dev server running on `localhost:8003`:

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8003/critters
```

Expected: `200`. Then visually confirm in a browser (or via
`scripts/design-audit/capture.sh`, see Task 5) that: search filters the grid,
each temperature/diet/DLC chip narrows results, families without artwork show
the tinted silhouette instead of a blank tile, and multi-variant families with
extra artwork show the preview stack in the tile's corner.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Critters/Index.vue
git -c commit.gpgsign=false commit -m "feat(critters): redesign index as a filterable animated grid"
```

---

## Task 5: Full verification pass

**Files:** none (verification only)

- [ ] **Step 1: Re-run the design audit**

```bash
./scripts/design-audit/capture.sh
```

Expected: 20 screenshots written to `scripts/design-audit/screenshots/`
(unchanged filenames — they overwrite the pre-redesign captures from the
brainstorming phase). Read `critters-index--desktop--light.png` and
`critters-index--desktop--dark.png` and confirm the grid now shows filter
chips, tinted tiles/silhouettes, and no more empty-looking cards.

- [ ] **Step 2: Run the backend gates, scoped against the known baseline**

```bash
php -d memory_limit=1G vendor/bin/phpstan analyse
composer test:type-coverage
XDEBUG_MODE=coverage composer test:unit
vendor/bin/pint --test --parallel
```

Expected: phpstan reports the same ~18 pre-existing errors (see Global
Constraints) plus **no new ones** — the lines inside `CritterPresenter.php`
this plan touched (`familyName`, `variantData`, `variantName`,
`familyTempRange`, `variantPreviewImages`, `familyList`) should show no
*additional* errors versus what those methods' old code already had; new
private methods (`variantName`, `familyTempRange`, `variantPreviewImages`)
should be fully clean since they're new code. Type/line coverage: 100% on
every file this plan created or modified (the coverage tool reports
per-file; confirm `CritterImporter.php`, `CritterPresenter.php`,
`CritterFamilyListItemData.php` all show full coverage — pre-existing gaps
elsewhere in the codebase, if any, are not this plan's concern). Pint: same
single pre-existing `frankenphp-worker.php` failure, nothing new.

- [ ] **Step 3: Run the frontend typecheck gate**

```bash
bun run typecheck
```

Expected: PASS, no errors.

- [ ] **Step 4: Re-import the DB so the `dlc_id` fix is reflected in the running app**

```bash
php artisan game:import --skip-extractor --no-interaction -v
```

Expected: import completes; spot-check with
`mcp__laravel-boost__database-query`:

```sql
select count(*) from critters where dlc_id is not null
```

Expected: a non-zero count (DLC5 Aquatic Pack critters), matching the earlier
audit's finding that DLC content exists in the extractor JSON.

This step is destructive (truncates and reseeds game tables) — confirm with
the user before running it, per the project's standing rule on destructive
DB operations.
