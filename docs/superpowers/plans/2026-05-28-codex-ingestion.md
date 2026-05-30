# Codex Ingestion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ingest the in-game Codex (per-entity text + 311 long-form articles) into the database, linked to any game entity, faithfully stored for later presentation.

**Architecture:** Per-entity text lives in translatable columns on each entity (1:1). Codex articles live in a standalone `CodexEntry` model whose `content` is pre-resolved locale-keyed blocks. Articles link to entities through a polymorphic many-to-many pivot (`codexables`), wired onto entities via a `HasCodexEntries` trait. A `#[GameData]` attribute marks game-sourced models so the destructive reimport scope is derived, not hardcoded.

**Tech Stack:** Laravel 13, PHP 8.5, Spatie Translatable, Symfony Yaml (custom tags), Pest 5, SQLite.

**Spec:** `docs/superpowers/specs/2026-05-28-codex-ingestion-design.md`

**Conventions (enforced):**
- Commits: conventional, **no GPG signature, no Co-Authored-By**. Use `git -c commit.gpgsign=false commit -m "..."`.
- After modifying PHP, run `vendor/bin/pint --dirty --format agent` before committing.
- Run tests with `php artisan test --compact --filter=<name>`.
- All imported text is a locale-keyed map (`['en' => ...]`); never a bare string.

---

## File Structure

**Create:**
- `app/Models/Attributes/GameData.php` — class attribute marking a model as destructive game data.
- `app/Models/Concerns/HasCodexEntries.php` — trait: `morphToMany(CodexEntry)` relation.
- `app/Models/CodexEntry.php` — codex article model.
- `app/Services/GameImport/GameDataTables.php` — discovers game tables via `#[GameData]` + pivot list.
- `app/Services/GameImport/CodexImporter.php` — parses codex YAML, builds entries, matches entities.
- `database/factories/CodexEntryFactory.php` — for tests.
- 5 migrations (effect, critter desc, plant desc, codex_entries, codexables).
- Test fixtures under `tests/Fixtures/GameImport/`.
- Test files under `tests/Feature/GameImport/`.

**Modify:**
- `app/Services/GameImport/StringResolver.php` — multi-line gettext parsing.
- `app/Models/{Building,Element,Critter,Plant,GeyserType}.php` — trait + (where relevant) `effect`/`description`.
- All game models — add `#[GameData]`.
- `app/Services/GameImport/{Building,Element,Critter,Plant}Importer.php` — description/effect.
- `app/Console/Commands/GameImportCommand.php` — derived truncate + codex import.

---

## Task 1: Multi-line aware StringResolver

The current `parsePot` reads single-line `msgid "..."` only, silently dropping ~190 long EN entries (gettext continuation blocks) and blocking future FR `.po` loading. Make it concatenate continuation lines and unescape `\"` / `\\`. Single-line behaviour must stay identical.

**Files:**
- Modify: `app/Services/GameImport/StringResolver.php`
- Modify: `tests/Fixtures/GameImport/strings.pot`
- Test: `tests/Feature/GameImport/StringResolverTest.php`

- [ ] **Step 1: Add a multi-line entry to the fixture pot**

Append to `tests/Fixtures/GameImport/strings.pot`:

```pot

#. STRINGS.CODEX.TEST.MULTILINE
msgctxt "STRINGS.CODEX.TEST.MULTILINE"
msgid ""
"First part of the body. "
"Second part with a <link=\"OXYGEN\">link</link>."
msgstr ""
```

- [ ] **Step 2: Write the failing test**

Add to `tests/Feature/GameImport/StringResolverTest.php`:

```php
it('resolves a multi-line continuation block into the full concatenated value', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));

    expect($resolver->resolve('STRINGS.CODEX.TEST.MULTILINE'))
        ->toBe('First part of the body. Second part with a link.');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact --filter="multi-line continuation"`
Expected: FAIL (current parser returns `null` or empty for the continuation block).

- [ ] **Step 4: Replace `parsePot` with a continuation-aware state machine**

In `app/Services/GameImport/StringResolver.php`, replace the `parsePot` method with:

```php
    /** @return array<string, string> */
    private function parsePot(string $path): array
    {
        $content = (string) file_get_contents($path);
        $strings = [];
        $currentCtxt = null;
        $capturing = false;
        $buffer = '';

        $flush = function () use (&$strings, &$currentCtxt, &$capturing, &$buffer): void {
            if ($currentCtxt !== null && $capturing && $buffer !== '') {
                $strings[$currentCtxt] = $buffer;
            }

            $currentCtxt = null;
            $capturing = false;
            $buffer = '';
        };

        foreach (explode("\n", $content) as $rawLine) {
            $line = mb_rtrim($rawLine, "\r");

            if (str_starts_with($line, 'msgctxt "')) {
                $flush();
                $currentCtxt = $this->unquote(mb_substr($line, 8));

                continue;
            }

            if (str_starts_with($line, 'msgid "')) {
                $capturing = true;
                $buffer = $this->unquote(mb_substr($line, 6));

                continue;
            }

            if ($capturing && str_starts_with($line, '"')) {
                $buffer .= $this->unquote($line);

                continue;
            }

            if ($capturing) {
                $flush();
            }
        }

        $flush();

        return $strings;
    }

    private function unquote(string $token): string
    {
        $token = mb_trim($token);
        if (str_starts_with($token, '"')) {
            $token = mb_substr($token, 1);
        }
        if (str_ends_with($token, '"')) {
            $token = mb_substr($token, 0, -1);
        }

        return str_replace(['\\"', '\\\\'], ['"', '\\'], $token);
    }
```

- [ ] **Step 5: Run the StringResolver tests to verify all pass**

Run: `php artisan test --compact --filter=StringResolver`
Expected: PASS (the 3 existing tests + the new multi-line test).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/GameImport/StringResolver.php tests/Feature/GameImport/StringResolverTest.php tests/Fixtures/GameImport/strings.pot
git -c commit.gpgsign=false commit -m "fix(import): parse multi-line gettext continuation blocks in StringResolver"
```

---

## Task 2: Per-entity text columns + model updates

Add the `effect` column to buildings and the `description` column to critters/plants (elements + buildings already have `description`). Wire the new attributes into the models.

**Files:**
- Create: `database/migrations/2026_05_28_100001_add_effect_to_buildings_table.php`
- Create: `database/migrations/2026_05_28_100002_add_description_to_critters_table.php`
- Create: `database/migrations/2026_05_28_100003_add_description_to_plants_table.php`
- Modify: `app/Models/Building.php`, `app/Models/Critter.php`, `app/Models/Plant.php`
- Test: `tests/Feature/GameImport/EntityTextColumnsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GameImport/EntityTextColumnsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Critter;
use App\Models\Plant;

it('stores effect and description translatable maps on a building', function (): void {
    $building = Building::factory()->create([
        'description' => ['en' => 'Electrolyzes water.'],
        'effect' => ['en' => 'Converts water into oxygen and hydrogen.'],
    ]);

    $fresh = $building->fresh();

    expect($fresh->getTranslation('description', 'en'))->toBe('Electrolyzes water.')
        ->and($fresh->getTranslation('effect', 'en'))->toBe('Converts water into oxygen and hydrogen.');
});

it('stores a description map on a critter and a plant', function (): void {
    $critter = Critter::query()->create(['critter_id' => 'Hatch', 'name' => ['en' => 'Hatch'], 'description' => ['en' => 'A digger.']]);
    $plant = Plant::query()->create(['plant_id' => 'Mealwood', 'name' => ['en' => 'Mealwood'], 'description' => ['en' => 'A crop.']]);

    expect($critter->fresh()->getTranslation('description', 'en'))->toBe('A digger.')
        ->and($plant->fresh()->getTranslation('description', 'en'))->toBe('A crop.');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EntityTextColumns`
Expected: FAIL (`effect`/`description` columns do not exist).

- [ ] **Step 3: Create the migrations**

`database/migrations/2026_05_28_100001_add_effect_to_buildings_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table): void {
            $table->json('effect')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table): void {
            $table->dropColumn('effect');
        });
    }
};
```

`database/migrations/2026_05_28_100002_add_description_to_critters_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('critters', function (Blueprint $table): void {
            $table->json('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('critters', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
```

`database/migrations/2026_05_28_100003_add_description_to_plants_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plants', function (Blueprint $table): void {
            $table->json('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('plants', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
```

- [ ] **Step 4: Update the models**

In `app/Models/Building.php`: add `'effect'` to the `#[Fillable([...])]` list, add `'effect'` to `$translatable`, and add `'effect' => 'array'` to `casts()`. Result:

```php
#[Fillable([
    'building_id', 'category', 'power_consumption', 'power_generation',
    'heat_generation', 'width', 'height', 'construction_time',
    'tags', 'name', 'description', 'effect', 'dlc_id',
])]
```
```php
    /** @var list<string> */
    public array $translatable = ['name', 'description', 'effect'];
```
```php
    public function casts(): array
    {
        return ['tags' => 'array', 'name' => 'array', 'description' => 'array', 'effect' => 'array'];
    }
```

In `app/Models/Critter.php`: add `'description'` to `#[Fillable]`, `$translatable`, and `casts()`:

```php
#[Fillable(['critter_id', 'name', 'description', 'dlc_id'])]
```
```php
    public array $translatable = ['name', 'description'];
```
```php
    public function casts(): array
    {
        return ['name' => 'array', 'description' => 'array'];
    }
```

In `app/Models/Plant.php`: the same three edits as Critter:

```php
#[Fillable(['plant_id', 'name', 'description', 'dlc_id'])]
```
```php
    public array $translatable = ['name', 'description'];
```
```php
    public function casts(): array
    {
        return ['name' => 'array', 'description' => 'array'];
    }
```

- [ ] **Step 5: Run migrations and the test**

Run: `php artisan migrate && php artisan test --compact --filter=EntityTextColumns`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/Building.php app/Models/Critter.php app/Models/Plant.php tests/Feature/GameImport/EntityTextColumnsTest.php
git -c commit.gpgsign=false commit -m "feat(data): add effect (buildings) and description (critters, plants) columns"
```

---

## Task 3: CodexEntry model, trait, polymorphic link

Create the `CodexEntry` model + `codex_entries`/`codexables` tables, the `HasCodexEntries` trait, and wire the trait onto the five linkable entities.

**Files:**
- Create: `database/migrations/2026_05_28_100004_create_codex_entries_table.php`
- Create: `database/migrations/2026_05_28_100005_create_codexables_table.php`
- Create: `app/Models/CodexEntry.php`
- Create: `app/Models/Concerns/HasCodexEntries.php`
- Create: `database/factories/CodexEntryFactory.php`
- Modify: `app/Models/{Building,Element,Critter,Plant,GeyserType}.php`
- Test: `tests/Feature/GameImport/CodexEntryRelationTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GameImport/CodexEntryRelationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\CodexEntry;

it('links a codex entry to a building both ways', function (): void {
    $building = Building::factory()->create(['building_id' => 'Electrolyzer']);
    $entry = CodexEntry::factory()->create(['codex_id' => 'Electrolyzer', 'category' => 'Buildings']);

    $building->codexEntries()->attach($entry->id);

    expect($building->fresh()->codexEntries)->toHaveCount(1)
        ->and($building->fresh()->codexEntries->first()->codex_id)->toBe('Electrolyzer')
        ->and($entry->fresh()->buildings)->toHaveCount(1)
        ->and($entry->fresh()->buildings->first()->building_id)->toBe('Electrolyzer');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CodexEntryRelation`
Expected: FAIL (`CodexEntry` class does not exist).

- [ ] **Step 3: Create the migrations**

`database/migrations/2026_05_28_100004_create_codex_entries_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codex_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('codex_id')->unique();
            $table->string('category');
            $table->string('parent_codex_id')->nullable();
            $table->json('title');
            $table->string('icon_asset')->nullable();
            $table->json('content');
            $table->string('dlc_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codex_entries');
    }
};
```

`database/migrations/2026_05_28_100005_create_codexables_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codexables', function (Blueprint $table): void {
            $table->foreignId('codex_entry_id')->constrained()->cascadeOnDelete();
            $table->morphs('codexable');
            $table->unique(['codex_entry_id', 'codexable_type', 'codexable_id'], 'codexables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codexables');
    }
};
```

- [ ] **Step 4a: Create the `#[GameData]` attribute (needed by CodexEntry, reused in Task 4)**

`app/Models/Attributes/GameData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class GameData {}
```

- [ ] **Step 4: Create the trait**

`app/Models/Concerns/HasCodexEntries.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\CodexEntry;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasCodexEntries
{
    /** @return MorphToMany<CodexEntry, $this> */
    public function codexEntries(): MorphToMany
    {
        return $this->morphToMany(CodexEntry::class, 'codexable');
    }
}
```

- [ ] **Step 5: Create the CodexEntry model**

`app/Models/CodexEntry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\GameData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Translatable\HasTranslations;

#[GameData]
#[Fillable(['codex_id', 'category', 'parent_codex_id', 'title', 'icon_asset', 'content', 'dlc_id'])]
final class CodexEntry extends Model
{
    use HasFactory;
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['title'];

    /** @return MorphToMany<Building, $this> */
    public function buildings(): MorphToMany
    {
        return $this->morphedByMany(Building::class, 'codexable');
    }

    /** @return MorphToMany<Element, $this> */
    public function elements(): MorphToMany
    {
        return $this->morphedByMany(Element::class, 'codexable');
    }

    /** @return MorphToMany<Critter, $this> */
    public function critters(): MorphToMany
    {
        return $this->morphedByMany(Critter::class, 'codexable');
    }

    /** @return MorphToMany<Plant, $this> */
    public function plants(): MorphToMany
    {
        return $this->morphedByMany(Plant::class, 'codexable');
    }

    /** @return MorphToMany<GeyserType, $this> */
    public function geyserTypes(): MorphToMany
    {
        return $this->morphedByMany(GeyserType::class, 'codexable');
    }

    public function casts(): array
    {
        return ['title' => 'array', 'content' => 'array'];
    }
}
```

> `#[GameData]` was created in Step 4a above, so this compiles immediately. Task 4 reuses the same attribute for discovery.

- [ ] **Step 6: Create the factory**

`database/factories/CodexEntryFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CodexEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CodexEntry> */
final class CodexEntryFactory extends Factory
{
    protected $model = CodexEntry::class;

    public function definition(): array
    {
        $id = $this->faker->unique()->word();

        return [
            'codex_id' => $id,
            'category' => 'Systems',
            'parent_codex_id' => null,
            'title' => ['en' => ucfirst($id)],
            'icon_asset' => null,
            'content' => [],
            'dlc_id' => null,
        ];
    }
}
```

- [ ] **Step 7: Wire the trait onto the five entities**

In each of `app/Models/Building.php`, `Element.php`, `Critter.php`, `Plant.php`, `GeyserType.php`: add the import `use App\Models\Concerns\HasCodexEntries;` and add `use HasCodexEntries;` alongside the existing `use HasTranslations;` inside the class body. Example for `Building.php`:

```php
use App\Models\Concerns\HasCodexEntries;
// ... other imports

final class Building extends Model
{
    use HasCodexEntries;
    use HasFactory;
    use HasTranslations;
```

Apply the identical two-line addition to `Element`, `Critter`, `Plant`, `GeyserType`.

- [ ] **Step 8: Run migrations and the test**

Run: `php artisan migrate && php artisan test --compact --filter=CodexEntryRelation`
Expected: PASS.

- [ ] **Step 9: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations database/factories/CodexEntryFactory.php app/Models
git -c commit.gpgsign=false commit -m "feat(codex): CodexEntry model, HasCodexEntries trait, polymorphic codexables pivot"
```

---

## Task 4: `#[GameData]` attribute + derived truncate scope

Replace the hardcoded truncate list in `GameImportCommand` with discovery driven by a `#[GameData]` attribute (plus an explicit short list for model-less pivot tables).

> The `#[GameData]` attribute was already created in Task 3 (Step 4a). This task adds the discovery service, annotates the remaining game models, and refactors the truncate.

**Files:**
- Create: `app/Services/GameImport/GameDataTables.php`
- Modify: every game model (add `#[GameData]`)
- Modify: `app/Console/Commands/GameImportCommand.php`
- Test: `tests/Feature/GameImport/GameDataTablesTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GameImport/GameDataTablesTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\GameImport\GameDataTables;

it('discovers game-data tables including codex and model-less pivots', function (): void {
    $tables = GameDataTables::all();

    expect($tables)->toContain('buildings')
        ->toContain('elements')
        ->toContain('codex_entries')
        ->toContain('codexables')
        ->toContain('plant_variant_mutations');
});

it('excludes non-game tables such as users', function (): void {
    expect(GameDataTables::all())->not->toContain('users');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=GameDataTables`
Expected: FAIL (`GameDataTables` class does not exist).

- [ ] **Step 3: Create the discovery service**

`app/Services/GameImport/GameDataTables.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Attributes\GameData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;

final class GameDataTables
{
    /**
     * Game-data tables with no Eloquent model (pivots). These cannot carry the
     * #[GameData] attribute, so they are listed explicitly.
     *
     * @var list<string>
     */
    private const array PIVOT_TABLES = ['plant_variant_mutations', 'codexables'];

    /** @return list<string> */
    public static function all(): array
    {
        $tables = [];

        foreach (File::allFiles(app_path('Models')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            /** @var class-string $class */
            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->getAttributes(GameData::class) === []) {
                continue;
            }

            /** @var Model $instance */
            $instance = $reflection->newInstanceWithoutConstructor();
            $tables[] = $instance->getTable();
        }

        return array_values(array_unique([...$tables, ...self::PIVOT_TABLES]));
    }
}
```

- [ ] **Step 4: Annotate every game model with `#[GameData]`**

For each of these models, add `use App\Models\Attributes\GameData;` to the imports and `#[GameData]` directly above the `final class` declaration (CodexEntry already has it from Task 3):

```
Building, BuildingConstructionMaterial, Critter, CritterMorph, CritterMorphDiet,
CritterMorphOutput, DuplicantBaseStat, DuplicantTrait, DuplicantTraitEffect,
Element, ElementGasProperty, ElementLiquidProperty, ElementSolidProperty,
ElementSpecialProperty, ElementThermalProperty, FoodProperty, GeyserType,
Plant, PlantMutation, PlantMutationEffect, PlantVariant, PlantVariantInput,
PlantVariantOutput, Recipe, RecipeItem, RocketEngine, RocketModule
```

Do **not** annotate `User`. Example diff for `app/Models/Recipe.php`:

```php
use App\Models\Attributes\GameData;
// ... other imports

#[GameData]
final class Recipe extends Model
```

- [ ] **Step 5: Run the discovery test**

Run: `php artisan test --compact --filter=GameDataTables`
Expected: PASS.

- [ ] **Step 6: Refactor `truncateAll` to use discovery**

In `app/Console/Commands/GameImportCommand.php`, replace the `truncateAll` method body and add the import `use App\Services\GameImport\GameDataTables;`:

```php
    private function truncateAll(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        foreach (GameDataTables::all() as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('PRAGMA foreign_keys = ON');
    }
```

- [ ] **Step 7: Verify the full suite still passes**

Run: `php artisan test --compact`
Expected: PASS (no regression from the truncate refactor).

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models app/Services/GameImport/GameDataTables.php app/Console/Commands/GameImportCommand.php tests/Feature/GameImport/GameDataTablesTest.php
git -c commit.gpgsign=false commit -m "feat(import): derive destructive truncate scope from #[GameData] attribute"
```

---

## Task 5: CodexImporter — parse YAML into entries

Parse codex YAML files (custom tags) into `CodexEntry` rows with resolved, locale-keyed content blocks. Matching to entities is the next task.

**Files:**
- Create: `app/Services/GameImport/CodexImporter.php`
- Create: `tests/Fixtures/GameImport/codex/Buildings/Electrolyzer.yaml`
- Create: `tests/Fixtures/GameImport/codex/Systems/Genetics.yaml`
- Modify: `tests/Fixtures/GameImport/strings.pot`
- Test: `tests/Feature/GameImport/CodexImporterTest.php`

- [ ] **Step 1: Create fixture codex YAML files**

`tests/Fixtures/GameImport/codex/Buildings/Electrolyzer.yaml`:

```yaml
id: "Electrolyzer"
title: STRINGS.CODEX.ELECTROLYZER.TITLE
contentContainers:
  - contentLayout: Vertical
    lockID:
    content:
      - !CodexText
          stringKey: STRINGS.CODEX.ELECTROLYZER.TITLE
          style: Title
      - !CodexDividerLine
          preferredWidth: -1
      - !CodexImage
          spriteName: electrolyzer_img
          preferredHeight: 128
      - !CodexText
          stringKey: STRINGS.CODEX.ELECTROLYZER.BODY
          style: Body
```

`tests/Fixtures/GameImport/codex/Systems/Genetics.yaml`:

```yaml
id: "Genetics"
title: STRINGS.CODEX.GENETICS.TITLE
parentId: SYSTEMSTUTORIALS
iconAssetName: codex_genetics
contentContainers:
  - contentLayout: Vertical
    content:
      - !CodexText
          stringKey: STRINGS.CODEX.GENETICS.TITLE
          style: Title
      - !CodexText
          stringKey: STRINGS.CODEX.GENETICS.MISSING
          style: Body
```

> The `STRINGS.CODEX.GENETICS.MISSING` key is intentionally absent from the fixture pot to prove missing-string blocks are dropped.

- [ ] **Step 2: Add the codex strings to the fixture pot**

Append to `tests/Fixtures/GameImport/strings.pot`:

```pot

#. STRINGS.CODEX.ELECTROLYZER.TITLE
msgctxt "STRINGS.CODEX.ELECTROLYZER.TITLE"
msgid "Electrolyzer"
msgstr ""

#. STRINGS.CODEX.ELECTROLYZER.BODY
msgctxt "STRINGS.CODEX.ELECTROLYZER.BODY"
msgid "Splits water into oxygen and hydrogen."
msgstr ""

#. STRINGS.CODEX.GENETICS.TITLE
msgctxt "STRINGS.CODEX.GENETICS.TITLE"
msgid "Genetics"
msgstr ""
```

- [ ] **Step 3: Write the failing test**

Create `tests/Feature/GameImport/CodexImporterTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\CodexEntry;
use App\Services\GameImport\CodexImporter;
use App\Services\GameImport\StringResolver;

function runCodexImporter(): void
{
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new CodexImporter($resolver)->import(base_path('tests/Fixtures/GameImport/codex'));
}

it('imports codex entries with category from the folder', function (): void {
    runCodexImporter();

    expect(CodexEntry::query()->count())->toBe(2);

    $genetics = CodexEntry::query()->where('codex_id', 'Genetics')->sole();

    expect($genetics->category)->toBe('Systems')
        ->and($genetics->parent_codex_id)->toBe('SYSTEMSTUTORIALS')
        ->and($genetics->icon_asset)->toBe('codex_genetics')
        ->and($genetics->getTranslation('title', 'en'))->toBe('Genetics');
});

it('resolves content blocks into typed, locale-keyed structures', function (): void {
    runCodexImporter();

    $entry = CodexEntry::query()->where('codex_id', 'Electrolyzer')->sole();
    $blocks = $entry->content[0]['blocks'];

    expect($entry->content[0]['layout'])->toBe('Vertical')
        ->and($blocks[0])->toBe(['type' => 'text', 'style' => 'Title', 'text' => ['en' => 'Electrolyzer']])
        ->and($blocks[1])->toBe(['type' => 'divider'])
        ->and($blocks[2])->toBe(['type' => 'image', 'sprite' => 'electrolyzer_img', 'height' => 128])
        ->and($blocks[3])->toBe(['type' => 'text', 'style' => 'Body', 'text' => ['en' => 'Splits water into oxygen and hydrogen.']]);
});

it('drops text blocks whose string key is missing', function (): void {
    runCodexImporter();

    $genetics = CodexEntry::query()->where('codex_id', 'Genetics')->sole();

    // Only the resolvable Title block remains; the MISSING block is dropped.
    expect($genetics->content[0]['blocks'])->toHaveCount(1)
        ->and($genetics->content[0]['blocks'][0]['style'])->toBe('Title');
});
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test --compact --filter=CodexImporter`
Expected: FAIL (`CodexImporter` class does not exist).

- [ ] **Step 5: Implement the importer (parsing only)**

`app/Services/GameImport/CodexImporter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\CodexEntry;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

final readonly class CodexImporter
{
    public function __construct(private StringResolver $stringResolver) {}

    public function import(string $codexDir): void
    {
        foreach (File::allFiles($codexDir) as $file) {
            if ($file->getExtension() !== 'yaml') {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = Yaml::parseFile($file->getRealPath(), Yaml::PARSE_CUSTOM_TAGS);

            $codexId = $data['id'] ?? null;
            if (! is_string($codexId) || $codexId === '') {
                continue;
            }

            $category = $this->categoryFor($codexDir, (string) $file->getRelativePath());
            $titleKey = is_string($data['title'] ?? null) ? $data['title'] : null;
            $title = ($titleKey !== null ? $this->stringResolver->resolveToJson($titleKey) : null) ?? ['en' => $codexId];

            CodexEntry::query()->create([
                'codex_id' => $codexId,
                'category' => $category,
                'parent_codex_id' => is_string($data['parentId'] ?? null) ? $data['parentId'] : null,
                'title' => $title,
                'icon_asset' => is_string($data['iconAssetName'] ?? null) ? $data['iconAssetName'] : null,
                'content' => $this->buildContent($data['contentContainers'] ?? []),
                'dlc_id' => null,
            ]);
        }
    }

    private function categoryFor(string $codexDir, string $relativePath): string
    {
        if ($relativePath === '') {
            return 'Root';
        }

        return explode(DIRECTORY_SEPARATOR, $relativePath)[0];
    }

    /**
     * @param  mixed  $containers
     * @return list<array{layout: string, lock_id: string|null, blocks: list<array<string, mixed>>}>
     */
    private function buildContent(mixed $containers): array
    {
        if (! is_array($containers)) {
            return [];
        }

        $result = [];

        foreach ($containers as $container) {
            if (! is_array($container)) {
                continue;
            }

            $blocks = [];
            foreach (($container['content'] ?? []) as $item) {
                $block = $item instanceof TaggedValue ? $this->buildBlock($item) : null;
                if ($block !== null) {
                    $blocks[] = $block;
                }
            }

            if ($blocks !== []) {
                $result[] = [
                    'layout' => is_string($container['contentLayout'] ?? null) ? $container['contentLayout'] : 'Vertical',
                    'lock_id' => is_string($container['lockID'] ?? null) ? $container['lockID'] : null,
                    'blocks' => $blocks,
                ];
            }
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function buildBlock(TaggedValue $item): ?array
    {
        /** @var array<string, mixed> $value */
        $value = is_array($item->getValue()) ? $item->getValue() : [];

        return match ($item->getTag()) {
            'CodexText' => $this->textBlock($value),
            'CodexImage' => [
                'type' => 'image',
                'sprite' => is_string($value['spriteName'] ?? null) ? $value['spriteName'] : null,
                'height' => $value['preferredHeight'] ?? null,
            ],
            'CodexDividerLine' => ['type' => 'divider'],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|null
     */
    private function textBlock(array $value): ?array
    {
        $key = $value['stringKey'] ?? null;
        if (! is_string($key)) {
            return null;
        }

        $text = $this->stringResolver->resolveToJson($key);
        if ($text === null) {
            return null;
        }

        return [
            'type' => 'text',
            'style' => is_string($value['style'] ?? null) ? $value['style'] : 'Body',
            'text' => $text,
        ];
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact --filter=CodexImporter`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/GameImport/CodexImporter.php tests/Feature/GameImport/CodexImporterTest.php tests/Fixtures/GameImport
git -c commit.gpgsign=false commit -m "feat(codex): CodexImporter parses codex YAML into resolved content blocks"
```

---

## Task 6: CodexImporter — match entries to entities

Add a second pass that links each entry to the entity sharing its `codex_id`, by category. Unmatched entries stay standalone.

**Files:**
- Modify: `app/Services/GameImport/CodexImporter.php`
- Test: `tests/Feature/GameImport/CodexImporterTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/GameImport/CodexImporterTest.php`:

```php
it('links an entry to a building that shares its codex id, leaving lore unlinked', function (): void {
    $building = Building::factory()->create(['building_id' => 'Electrolyzer']);

    runCodexImporter();

    $electrolyzer = CodexEntry::query()->where('codex_id', 'Electrolyzer')->sole();
    $genetics = CodexEntry::query()->where('codex_id', 'Genetics')->sole();

    expect($building->fresh()->codexEntries)->toHaveCount(1)
        ->and($building->fresh()->codexEntries->first()->codex_id)->toBe('Electrolyzer')
        ->and($electrolyzer->buildings)->toHaveCount(1)
        ->and($genetics->buildings)->toHaveCount(0);
});
```

Add the import at the top of the test file:

```php
use App\Models\Building;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="shares its codex id"`
Expected: FAIL (no pivot rows are created yet).

- [ ] **Step 3: Add the matching pass**

In `app/Services/GameImport/CodexImporter.php`, add these imports:

```php
use App\Models\Building;
use App\Models\Critter;
use App\Models\Element;
use App\Models\GeyserType;
use App\Models\Plant;
use Illuminate\Database\Eloquent\Model;
```

Call `$this->linkEntities();` at the end of `import()` (after the `foreach` loop), then add:

```php
    private function linkEntities(): void
    {
        /** @var array<string, array{0: class-string<Model>, 1: string}> $map */
        $map = [
            'Buildings' => [Building::class, 'building_id'],
            'ElementTypes' => [Element::class, 'element_id'],
            'Creatures' => [Critter::class, 'critter_id'],
            'Plants' => [Plant::class, 'plant_id'],
            'Geysers' => [GeyserType::class, 'geyser_id'],
        ];

        CodexEntry::query()->each(function (CodexEntry $entry) use ($map): void {
            $target = $map[$entry->category] ?? null;
            if ($target === null) {
                return;
            }

            [$modelClass, $key] = $target;

            /** @var (Model&\App\Models\Concerns\HasCodexEntries)|null $entity */
            $entity = $modelClass::query()->where($key, $entry->codex_id)->first();
            $entity?->codexEntries()->attach($entry->id);
        });
    }
```

- [ ] **Step 4: Run the codex tests to verify all pass**

Run: `php artisan test --compact --filter=CodexImporter`
Expected: PASS (all parsing tests + the matching test).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/GameImport/CodexImporter.php tests/Feature/GameImport/CodexImporterTest.php
git -c commit.gpgsign=false commit -m "feat(codex): match codex entries to entities by natural key"
```

---

## Task 7: Populate description / effect in entity importers

Resolve `.DESC` (building, element, critter, plant) and `.EFFECT` (building) by swapping the `.NAME` suffix of the existing localization id, storing locale-keyed maps.

**Files:**
- Modify: `app/Services/GameImport/BuildingImporter.php`
- Modify: `app/Services/GameImport/CritterImporter.php`
- Modify: `app/Services/GameImport/PlantImporter.php`
- Modify: `app/Services/GameImport/ElementImporter.php`
- Test: `tests/Feature/GameImport/EntityTextImportTest.php`

- [ ] **Step 1: Add `.DESC`/`.EFFECT` keys to the fixture pot**

Append to `tests/Fixtures/GameImport/strings.pot`:

```pot

#. STRINGS.BUILDINGS.PREFABS.TESTBUILDING.NAME
msgctxt "STRINGS.BUILDINGS.PREFABS.TESTBUILDING.NAME"
msgid "Test Building"
msgstr ""

#. STRINGS.BUILDINGS.PREFABS.TESTBUILDING.DESC
msgctxt "STRINGS.BUILDINGS.PREFABS.TESTBUILDING.DESC"
msgid "A building for tests."
msgstr ""

#. STRINGS.BUILDINGS.PREFABS.TESTBUILDING.EFFECT
msgctxt "STRINGS.BUILDINGS.PREFABS.TESTBUILDING.EFFECT"
msgid "Does a test thing."
msgstr ""
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/GameImport/EntityTextImportTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Building;
use App\Services\GameImport\BuildingImporter;
use App\Services\GameImport\StringResolver;

it('imports description and effect for a building from suffix-derived keys', function (): void {
    $json = [[
        'building_id' => 'TestBuilding',
        'category' => 'Oxygen',
        'width' => 2,
        'height' => 2,
        'tags' => [],
        'name_localization_id' => 'STRINGS.BUILDINGS.PREFABS.TESTBUILDING.NAME',
    ]];
    $path = sys_get_temp_dir().'/test_buildings.json';
    file_put_contents($path, json_encode($json));

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new BuildingImporter($resolver)->import($path);

    $building = Building::query()->where('building_id', 'TestBuilding')->sole();

    expect($building->getTranslation('description', 'en'))->toBe('A building for tests.')
        ->and($building->getTranslation('effect', 'en'))->toBe('Does a test thing.');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact --filter=EntityTextImport`
Expected: FAIL (description/effect are not imported yet).

- [ ] **Step 4: Add a suffix-swap helper to BuildingImporter and set description/effect**

In `app/Services/GameImport/BuildingImporter.php`, inside the `foreach` loop, after `$nameJson` is computed, add:

```php
            $nameKey = is_string($raw['name_localization_id'] ?? null) ? $raw['name_localization_id'] : null;
            $descriptionJson = $this->resolveSibling($nameKey, 'DESC');
            $effectJson = $this->resolveSibling($nameKey, 'EFFECT');
```

Add `'description' => $descriptionJson,` and `'effect' => $effectJson,` to the `Building::query()->create([...])` array (after `'name' => $nameJson,`).

Add this method to the class:

```php
    /** @return array<string, string>|null */
    private function resolveSibling(?string $nameKey, string $suffix): ?array
    {
        if ($nameKey === null || ! str_ends_with($nameKey, '.NAME')) {
            return null;
        }

        $key = mb_substr($nameKey, 0, -mb_strlen('.NAME')).'.'.$suffix;

        return $this->stringResolver->resolveToJson($key);
    }
```

- [ ] **Step 5: Run the building text test**

Run: `php artisan test --compact --filter=EntityTextImport`
Expected: PASS.

- [ ] **Step 6: Apply the same `.DESC` resolution to Critter, Plant, Element importers**

In `app/Services/GameImport/CritterImporter.php`: add the same `resolveSibling` private method (suffix `'DESC'` only is needed). When the morph is the base (`$raw['is_base'] ?? false`), set the species description on the `Critter`:

```php
            if (($raw['is_base'] ?? false) === true) {
                $descKey = is_string($raw['name_localization_id'] ?? null) ? $raw['name_localization_id'] : null;
                $description = $this->resolveSibling($descKey, 'DESC');
                if ($description !== null) {
                    $critter->update(['description' => $description]);
                }
            }
```

Add the helper:

```php
    /** @return array<string, string>|null */
    private function resolveSibling(?string $nameKey, string $suffix): ?array
    {
        if ($nameKey === null || ! str_ends_with($nameKey, '.NAME')) {
            return null;
        }

        $key = mb_substr($nameKey, 0, -mb_strlen('.NAME')).'.'.$suffix;

        return $this->stringResolver->resolveToJson($key);
    }
```

In `app/Services/GameImport/PlantImporter.php`: add the same `resolveSibling` helper, and inside the variant loop, when the variant is the base, set the species description on the `Plant`:

```php
            if (($raw['is_base'] ?? false) === true) {
                $descKey = is_string($raw['name_localization_id'] ?? null) ? $raw['name_localization_id'] : null;
                $description = $this->resolveSibling($descKey, 'DESC');
                if ($description !== null) {
                    $plant->update(['description' => $description]);
                }
            }
```

Add the helper to the class:

```php
    /** @return array<string, string>|null */
    private function resolveSibling(?string $nameKey, string $suffix): ?array
    {
        if ($nameKey === null || ! str_ends_with($nameKey, '.NAME')) {
            return null;
        }

        $key = mb_substr($nameKey, 0, -mb_strlen('.NAME')).'.'.$suffix;

        return $this->stringResolver->resolveToJson($key);
    }
```

In `app/Services/GameImport/ElementImporter.php`: after `$nameJson`, derive description from the element's localization id. Element name keys are `STRINGS.ELEMENTS.<ID>.NAME`; add the helper and set `'description' => $this->resolveSibling(is_string($raw['localizationID'] ?? null) ? $raw['localizationID'] : null, 'DESC'),` in the `Element::query()->create([...])` array.

- [ ] **Step 7: Add per-entity assertions to the text import test**

Append to `tests/Feature/GameImport/EntityTextImportTest.php` (the Element case uses the existing fixture `STRINGS.ELEMENTS.OXYGEN.*`). First add the DESC sibling to the fixture pot:

```pot

#. STRINGS.ELEMENTS.OXYGEN.DESC
msgctxt "STRINGS.ELEMENTS.OXYGEN.DESC"
msgid "A breathable gas."
msgstr ""
```

Then add the test:

```php
it('imports description for an element from the YAML localization id', function (): void {
    $yaml = sys_get_temp_dir().'/test_elements_gas.yaml';
    file_put_contents($yaml, <<<'YAML'
    elements:
      - elementId: Oxygen
        molarMass: 32.0
        toxicity: 0
        materialCategory: Gas
        tags: []
        localizationID: STRINGS.ELEMENTS.OXYGEN.NAME
        specificHeatCapacity: 1.0
        thermalConductivity: 1.0
        defaultTemperature: 300
    YAML);

    $resolver = new App\Services\GameImport\StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new App\Services\GameImport\ElementImporter($resolver)->importYaml($yaml, 'gas');

    expect(App\Models\Element::query()->where('element_id', 'Oxygen')->sole()->getTranslation('description', 'en'))
        ->toBe('A breathable gas.');
});
```

- [ ] **Step 8: Run the full text-import test and the existing importer suite**

Run: `php artisan test --compact --filter="EntityTextImport|CritterImporter|ElementImporter|BuildingImporter"`
Expected: PASS (new assertions + no regression in existing importer tests).

- [ ] **Step 9: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/GameImport tests/Feature/GameImport/EntityTextImportTest.php tests/Fixtures/GameImport/strings.pot
git -c commit.gpgsign=false commit -m "feat(import): populate description (building, element, critter, plant) and effect (building)"
```

---

## Task 8: Wire CodexImporter into the import command

Run the codex import after all entities exist, and add a summary line.

**Files:**
- Modify: `app/Console/Commands/GameImportCommand.php`

- [ ] **Step 1: Add the codex import step**

In `app/Console/Commands/GameImportCommand.php`, add the import `use App\Services\GameImport\CodexImporter;` and `use App\Models\CodexEntry;`. After the rocket components block (the last importer) and before the `Import complete:` summary, add:

```php
        $this->info('Importing codex...');
        $codexDir = $gamePath.'/StreamingAssets/codex';
        if (is_dir($codexDir)) {
            new CodexImporter($resolver)->import($codexDir);
        }
```

- [ ] **Step 2: Add the codex count to the summary**

In the same file, in the summary block, add:

```php
        $this->line('  Codex: '.CodexEntry::query()->count().' entries');
```

- [ ] **Step 3: Verify the command compiles and the full suite passes**

Run: `php artisan game:import --help && php artisan test --compact`
Expected: command help prints with no error; full suite PASSES.

- [ ] **Step 4: Run a real import to sanity-check against live game data**

Run: `php artisan game:import --skip-extractor`
Expected: completes successfully; the summary shows `Codex: <N> entries` with N in the low hundreds (≈300).

- [ ] **Step 5: Spot-check linkage via tinker**

Run:
```bash
php artisan tinker --execute 'echo App\Models\CodexEntry::query()->count()." entries, ".DB::table("codexables")->count()." links\n";'
```
Expected: a few hundred entries and a non-zero number of links.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/GameImportCommand.php
git -c commit.gpgsign=false commit -m "feat(import): wire CodexImporter into game:import and report counts"
```

---

## Final verification

- [ ] Run the whole suite: `php artisan test --compact` → all green.
- [ ] Run Larastan if configured: `vendor/bin/phpstan analyse --no-progress` → no new errors.
- [ ] Confirm no game-data fixtures or the FR `strings.po` were committed (they live under the gitignored `game-data/`).
