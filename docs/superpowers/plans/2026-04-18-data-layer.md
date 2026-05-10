# Data Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the complete game data extraction pipeline and database schema for the ONI Planner — 27 tables, a PHP import command, and a C# AOT binary that reads `Assembly-CSharp.dll` and Unity asset bundles.

**Architecture:** Two-stage pipeline: `dotnet-extractor` (C# binary) reads DLL + Unity bundles → outputs to `json-cache/` + `public/assets/game/`; `php artisan game:import` reads YAML files and `json-cache/` → truncates and rebuilds all 27 tables. The two stages are decoupled: the Laravel importer is tested with fixture JSON files independently of the extractor.

**Tech Stack:** Laravel 13 / PHP 8.5 / Pest 5 / SQLite (tests) / `spatie/laravel-translatable` / `symfony/yaml` / C# .NET 8 AOT / `Mono.Cecil` / `AssetTools.NET`

---

## File Structure

```
# Dependencies to add
composer require spatie/laravel-translatable
composer require symfony/yaml

# Migrations (27 tables)
database/migrations/
  *_create_elements_table.php
  *_create_element_thermal_properties_table.php
  *_create_element_gas_properties_table.php
  *_create_element_liquid_properties_table.php
  *_create_element_solid_properties_table.php
  *_create_element_special_properties_table.php
  *_create_food_properties_table.php
  *_create_duplicant_base_stats_table.php
  *_create_duplicant_traits_table.php
  *_create_duplicant_trait_effects_table.php
  *_create_geyser_types_table.php
  *_create_buildings_table.php
  *_create_recipes_table.php
  *_create_recipe_items_table.php
  *_create_building_construction_materials_table.php
  *_create_critters_table.php
  *_create_critter_morphs_table.php
  *_create_critter_morph_diets_table.php
  *_create_critter_morph_outputs_table.php
  *_create_plants_table.php
  *_create_plant_variants_table.php
  *_create_plant_variant_inputs_table.php
  *_create_plant_variant_outputs_table.php
  *_create_plant_mutations_table.php
  *_create_plant_mutation_effects_table.php
  *_create_plant_variant_mutations_table.php
  *_create_rocket_engines_table.php
  *_create_rocket_modules_table.php

# Models (app/Models/)
Element.php  ElementThermalProperty.php  ElementGasProperty.php
ElementLiquidProperty.php  ElementSolidProperty.php  ElementSpecialProperty.php
FoodProperty.php  DuplicantBaseStat.php  DuplicantTrait.php
DuplicantTraitEffect.php  GeyserType.php  Building.php  Recipe.php
RecipeItem.php  BuildingConstructionMaterial.php  Critter.php
CritterMorph.php  CritterMorphDiet.php  CritterMorphOutput.php
Plant.php  PlantVariant.php  PlantVariantInput.php  PlantVariantOutput.php
PlantMutation.php  PlantMutationEffect.php  RocketEngine.php  RocketModule.php

# Import services
app/Services/GameImport/StringResolver.php
app/Services/GameImport/ElementImporter.php
app/Services/GameImport/BuildingImporter.php
app/Services/GameImport/RecipeImporter.php
app/Services/GameImport/CritterImporter.php
app/Services/GameImport/PlantImporter.php
app/Services/GameImport/GeyserImporter.php
app/Services/GameImport/DuplicantImporter.php
app/Services/GameImport/RocketImporter.php

# Command
app/Console/Commands/GameImportCommand.php

# Tests + fixtures
tests/Feature/GameImport/StringResolverTest.php
tests/Feature/GameImport/ElementImporterTest.php
tests/Feature/GameImport/BuildingImporterTest.php
tests/Feature/GameImport/CritterImporterTest.php
tests/Feature/GameImport/PlantImporterTest.php
tests/Feature/GameImport/GeyserImporterTest.php
tests/Feature/GameImport/GameImportCommandTest.php
tests/Fixtures/GameImport/elements_gas.yaml
tests/Fixtures/GameImport/strings.pot
tests/Fixtures/GameImport/buildings.json
tests/Fixtures/GameImport/recipes.json
tests/Fixtures/GameImport/critters.json
tests/Fixtures/GameImport/plants.json
tests/Fixtures/GameImport/geyser_types.json
tests/Fixtures/GameImport/duplicant_traits.json
tests/Fixtures/GameImport/rocket_components.json

# dotnet-extractor (C# project at repo root)
dotnet-extractor/DotnetExtractor.csproj
dotnet-extractor/Program.cs
dotnet-extractor/Extractors/BuildingExtractor.cs
dotnet-extractor/Extractors/RecipeExtractor.cs
dotnet-extractor/Extractors/CritterExtractor.cs
dotnet-extractor/Extractors/PlantExtractor.cs
dotnet-extractor/Extractors/GeyserExtractor.cs
dotnet-extractor/Extractors/DuplicantTraitExtractor.cs
dotnet-extractor/Extractors/RocketExtractor.cs
dotnet-extractor/Extractors/AssetExtractor.cs
dotnet-extractor/Dtos/ (one DTO per entity type)
```

---

## Task 1: Install dependencies

**Files:**
- Modify: `composer.json`

- [ ] **Install spatie/laravel-translatable and symfony/yaml**

```bash
cd /home/mishaa/dev/ony-planner
composer require spatie/laravel-translatable symfony/yaml --no-interaction
```

- [ ] **Publish spatie config**

```bash
php artisan vendor:publish --provider="Spatie\Translatable\TranslatableServiceProvider" --no-interaction
```

- [ ] **Verify installation**

```bash
php artisan tinker --execute 'echo class_exists(\Spatie\Translatable\HasTranslations::class) ? "ok" : "fail";'
```

Expected: `ok`

- [ ] **Commit**

```bash
git add composer.json composer.lock
git commit --no-gpg-sign -m "chore: add spatie/laravel-translatable and symfony/yaml"
```

---

## Task 2: Element migrations

**Files:**
- Create: all 8 element-related migrations

- [ ] **Create all element migrations**

```bash
php artisan make:migration create_elements_table --no-interaction
php artisan make:migration create_element_thermal_properties_table --no-interaction
php artisan make:migration create_element_gas_properties_table --no-interaction
php artisan make:migration create_element_liquid_properties_table --no-interaction
php artisan make:migration create_element_solid_properties_table --no-interaction
php artisan make:migration create_element_special_properties_table --no-interaction
php artisan make:migration create_food_properties_table --no-interaction
```

- [ ] **Fill elements migration**

```php
// database/migrations/*_create_elements_table.php
public function up(): void
{
    Schema::create('elements', function (Blueprint $table): void {
        $table->id();
        $table->string('element_id')->unique();
        $table->enum('state', ['gas', 'liquid', 'solid', 'special']);
        $table->float('molar_mass');
        $table->float('toxicity');
        $table->string('material_category');
        $table->json('tags');
        $table->string('low_temp_transition_target')->nullable();
        $table->string('high_temp_transition_target')->nullable();
        $table->json('name');
        $table->json('description')->nullable();
        $table->string('dlc_id')->nullable();
        $table->boolean('is_disabled')->default(false);
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('elements');
}
```

Note: transition targets use `string` (references `element_id`, not `id`) to avoid FK ordering issues during destructive reimport.

- [ ] **Fill element_thermal_properties migration**

```php
public function up(): void
{
    Schema::create('element_thermal_properties', function (Blueprint $table): void {
        $table->string('element_id')->primary();
        $table->foreign('element_id')->references('element_id')->on('elements')->cascadeOnDelete();
        $table->float('specific_heat_capacity');
        $table->float('thermal_conductivity');
        $table->float('low_temp')->nullable();
        $table->float('high_temp')->nullable();
        $table->float('default_temperature');
        $table->float('light_absorption_factor');
        $table->float('radiation_absorption_factor');
        $table->float('radiation_per_1000_mass');
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('element_thermal_properties');
}
```

- [ ] **Fill element_gas_properties migration**

```php
public function up(): void
{
    Schema::create('element_gas_properties', function (Blueprint $table): void {
        $table->string('element_id')->primary();
        $table->foreign('element_id')->references('element_id')->on('elements')->cascadeOnDelete();
        $table->float('flow');
        $table->float('default_pressure');
        $table->float('gas_surface_area_multiplier');
        $table->boolean('is_breathable')->default(false);
        $table->boolean('is_toxic')->default(false);
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('element_gas_properties');
}
```

- [ ] **Fill element_liquid_properties migration**

```php
public function up(): void
{
    Schema::create('element_liquid_properties', function (Blueprint $table): void {
        $table->string('element_id')->primary();
        $table->foreign('element_id')->references('element_id')->on('elements')->cascadeOnDelete();
        $table->float('flow');
        $table->float('liquid_surface_area_multiplier');
        $table->timestamps();
    });
}
```

- [ ] **Fill element_solid_properties migration**

```php
public function up(): void
{
    Schema::create('element_solid_properties', function (Blueprint $table): void {
        $table->string('element_id')->primary();
        $table->foreign('element_id')->references('element_id')->on('elements')->cascadeOnDelete();
        $table->float('solid_surface_area_multiplier');
        $table->integer('hardness')->nullable();
        $table->boolean('is_ore')->default(false);
        $table->boolean('is_metal')->default(false);
        $table->boolean('is_refined_metal')->default(false);
        $table->timestamps();
    });
}
```

- [ ] **Fill element_special_properties migration**

```php
public function up(): void
{
    Schema::create('element_special_properties', function (Blueprint $table): void {
        $table->string('element_id')->primary();
        $table->foreign('element_id')->references('element_id')->on('elements')->cascadeOnDelete();
        $table->timestamps();
    });
}
```

- [ ] **Fill food_properties migration**

```php
public function up(): void
{
    Schema::create('food_properties', function (Blueprint $table): void {
        $table->string('element_id')->primary();
        $table->foreign('element_id')->references('element_id')->on('elements')->cascadeOnDelete();
        $table->float('calories');
        $table->enum('quality', ['bland', 'good', 'great', 'excellent']);
        $table->boolean('can_rot')->default(true);
        $table->timestamps();
    });
}
```

- [ ] **Run migrations to verify**

```bash
php artisan migrate --no-interaction
```

Expected: all 8 migrations run without error.

- [ ] **Commit**

```bash
git add database/migrations/
git commit --no-gpg-sign -m "feat: add element migrations (CTI pattern)"
```

---

## Task 3: Remaining domain migrations

**Files:**
- Create: 20 remaining migrations

- [ ] **Create migrations**

```bash
php artisan make:migration create_duplicant_base_stats_table --no-interaction
php artisan make:migration create_duplicant_traits_table --no-interaction
php artisan make:migration create_duplicant_trait_effects_table --no-interaction
php artisan make:migration create_geyser_types_table --no-interaction
php artisan make:migration create_buildings_table --no-interaction
php artisan make:migration create_recipes_table --no-interaction
php artisan make:migration create_recipe_items_table --no-interaction
php artisan make:migration create_building_construction_materials_table --no-interaction
php artisan make:migration create_critters_table --no-interaction
php artisan make:migration create_critter_morphs_table --no-interaction
php artisan make:migration create_critter_morph_diets_table --no-interaction
php artisan make:migration create_critter_morph_outputs_table --no-interaction
php artisan make:migration create_plants_table --no-interaction
php artisan make:migration create_plant_variants_table --no-interaction
php artisan make:migration create_plant_variant_inputs_table --no-interaction
php artisan make:migration create_plant_variant_outputs_table --no-interaction
php artisan make:migration create_plant_mutations_table --no-interaction
php artisan make:migration create_plant_mutation_effects_table --no-interaction
php artisan make:migration create_plant_variant_mutations_table --no-interaction
php artisan make:migration create_rocket_engines_table --no-interaction
php artisan make:migration create_rocket_modules_table --no-interaction
```

- [ ] **Fill duplicant migrations**

```php
// *_create_duplicant_base_stats_table.php
Schema::create('duplicant_base_stats', function (Blueprint $table): void {
    $table->id();
    $table->float('oxygen_consumption_gs');
    $table->float('co2_production_gs');
    $table->integer('calories_per_cycle');
    $table->float('mass_kg');
    $table->float('bladder_fill_per_cycle');
    $table->timestamps();
});

// *_create_duplicant_traits_table.php
Schema::create('duplicant_traits', function (Blueprint $table): void {
    $table->id();
    $table->string('trait_id')->unique();
    $table->boolean('is_positive')->default(true);
    $table->json('name');
    $table->json('description')->nullable();
    $table->string('dlc_id')->nullable();
    $table->timestamps();
});

// *_create_duplicant_trait_effects_table.php
Schema::create('duplicant_trait_effects', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('duplicant_trait_id')->constrained()->cascadeOnDelete();
    $table->string('stat');
    $table->float('modifier');
    $table->enum('modifier_type', ['multiply', 'add']);
    $table->timestamps();
});
```

- [ ] **Fill geyser_types migration**

```php
// *_create_geyser_types_table.php
Schema::create('geyser_types', function (Blueprint $table): void {
    $table->id();
    $table->string('geyser_id')->unique();
    $table->enum('type', ['geyser', 'vent', 'volcano', 'fissure']);
    $table->string('element_id');
    $table->foreign('element_id')->references('element_id')->on('elements');
    $table->float('temperature');
    $table->float('max_pressure');
    $table->float('min_yield_rate');
    $table->float('max_yield_rate');
    $table->float('min_eruption_duration');
    $table->float('max_eruption_duration');
    $table->float('min_eruption_period');
    $table->float('max_eruption_period');
    $table->float('dormancy_min_cycles');
    $table->float('dormancy_max_cycles');
    $table->json('name');
    $table->string('dlc_id')->nullable();
    $table->timestamps();
});
```

- [ ] **Fill buildings + recipes migrations**

```php
// *_create_buildings_table.php
Schema::create('buildings', function (Blueprint $table): void {
    $table->id();
    $table->string('building_id')->unique();
    $table->string('category');
    $table->float('power_consumption')->nullable();
    $table->float('power_generation')->nullable();
    $table->float('heat_generation')->default(0);
    $table->integer('width')->default(1);
    $table->integer('height')->default(1);
    $table->float('construction_time')->default(0);
    $table->json('tags');
    $table->json('name');
    $table->json('description')->nullable();
    $table->string('dlc_id')->nullable();
    $table->timestamps();
});

// *_create_recipes_table.php
Schema::create('recipes', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('building_id')->constrained()->cascadeOnDelete();
    $table->float('duration');
    $table->json('fabricators')->nullable();
    $table->json('name')->nullable();
    $table->timestamps();
});

// *_create_recipe_items_table.php
Schema::create('recipe_items', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
    $table->string('element_id');
    $table->foreign('element_id')->references('element_id')->on('elements');
    $table->float('amount');
    $table->enum('role', ['input', 'output']);
    $table->timestamps();
});

// *_create_building_construction_materials_table.php
Schema::create('building_construction_materials', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('building_id')->constrained()->cascadeOnDelete();
    $table->float('amount');
    $table->string('material_category')->nullable();
    $table->string('element_id')->nullable();
    $table->foreign('element_id')->references('element_id')->on('elements')->nullOnDelete();
    $table->timestamps();
});
```

- [ ] **Fill critter migrations**

```php
// *_create_critters_table.php
Schema::create('critters', function (Blueprint $table): void {
    $table->id();
    $table->string('critter_id')->unique();
    $table->json('name');
    $table->string('dlc_id')->nullable();
    $table->timestamps();
});

// *_create_critter_morphs_table.php
Schema::create('critter_morphs', function (Blueprint $table): void {
    $table->id();
    $table->string('morph_id')->unique();
    $table->foreignId('critter_id')->constrained()->cascadeOnDelete();
    $table->boolean('is_base')->default(false);
    $table->float('min_temp');
    $table->float('max_temp');
    $table->float('calories_per_cycle');
    $table->float('incubation_time');
    $table->float('lifespan');
    $table->integer('overcrowding_threshold')->default(0);
    $table->json('name');
    $table->timestamps();
});

// *_create_critter_morph_diets_table.php
Schema::create('critter_morph_diets', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('critter_morph_id')->constrained()->cascadeOnDelete();
    $table->string('consumed_element_id');
    $table->foreign('consumed_element_id')->references('element_id')->on('elements');
    $table->float('amount_per_cycle');
    $table->string('produced_element_id')->nullable();
    $table->foreign('produced_element_id')->references('element_id')->on('elements')->nullOnDelete();
    $table->float('conversion_ratio')->nullable();
    $table->timestamps();
});

// *_create_critter_morph_outputs_table.php
Schema::create('critter_morph_outputs', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('critter_morph_id')->constrained()->cascadeOnDelete();
    $table->string('element_id');
    $table->foreign('element_id')->references('element_id')->on('elements');
    $table->float('amount_per_cycle');
    $table->enum('output_type', ['egg', 'dropping', 'resource']);
    $table->timestamps();
});
```

- [ ] **Fill plant migrations**

```php
// *_create_plants_table.php
Schema::create('plants', function (Blueprint $table): void {
    $table->id();
    $table->string('plant_id')->unique();
    $table->json('name');
    $table->string('dlc_id')->nullable();
    $table->timestamps();
});

// *_create_plant_variants_table.php
Schema::create('plant_variants', function (Blueprint $table): void {
    $table->id();
    $table->string('variant_id')->unique();
    $table->foreignId('plant_id')->constrained()->cascadeOnDelete();
    $table->boolean('is_base')->default(false);
    $table->float('min_temp');
    $table->float('max_temp');
    $table->float('min_pressure');
    $table->float('max_pressure');
    $table->string('atmosphere_element_id')->nullable();
    $table->foreign('atmosphere_element_id')->references('element_id')->on('elements')->nullOnDelete();
    $table->boolean('light_required')->default(false);
    $table->float('growth_time');
    $table->json('name');
    $table->timestamps();
});

// *_create_plant_variant_inputs_table.php
Schema::create('plant_variant_inputs', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('plant_variant_id')->constrained()->cascadeOnDelete();
    $table->string('element_id');
    $table->foreign('element_id')->references('element_id')->on('elements');
    $table->float('amount_per_cycle');
    $table->enum('input_type', ['irrigation', 'fertilizer']);
    $table->timestamps();
});

// *_create_plant_variant_outputs_table.php
Schema::create('plant_variant_outputs', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('plant_variant_id')->constrained()->cascadeOnDelete();
    $table->string('element_id');
    $table->foreign('element_id')->references('element_id')->on('elements');
    $table->float('amount_per_harvest');
    $table->enum('output_type', ['food', 'resource']);
    $table->timestamps();
});

// *_create_plant_mutations_table.php
Schema::create('plant_mutations', function (Blueprint $table): void {
    $table->id();
    $table->string('mutation_id')->unique();
    $table->json('name');
    $table->timestamps();
});

// *_create_plant_mutation_effects_table.php
Schema::create('plant_mutation_effects', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('plant_mutation_id')->constrained()->cascadeOnDelete();
    $table->string('stat');
    $table->float('modifier');
    $table->enum('modifier_type', ['multiply', 'add']);
    $table->timestamps();
});

// *_create_plant_variant_mutations_table.php (pivot)
Schema::create('plant_variant_mutations', function (Blueprint $table): void {
    $table->foreignId('plant_variant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('plant_mutation_id')->constrained()->cascadeOnDelete();
    $table->primary(['plant_variant_id', 'plant_mutation_id']);
});
```

- [ ] **Fill rocket migrations**

```php
// *_create_rocket_engines_table.php
Schema::create('rocket_engines', function (Blueprint $table): void {
    $table->id();
    $table->string('engine_id')->unique();
    $table->string('fuel_element_id')->nullable();
    $table->foreign('fuel_element_id')->references('element_id')->on('elements')->nullOnDelete();
    $table->string('oxidizer_element_id')->nullable();
    $table->foreign('oxidizer_element_id')->references('element_id')->on('elements')->nullOnDelete();
    $table->float('max_range');
    $table->float('fuel_consumption_rate');
    $table->float('oxidizer_consumption_rate')->nullable();
    $table->float('exhaust_temperature');
    $table->json('name');
    $table->string('dlc_id')->nullable();
    $table->timestamps();
});

// *_create_rocket_modules_table.php
Schema::create('rocket_modules', function (Blueprint $table): void {
    $table->id();
    $table->string('module_id')->unique();
    $table->enum('module_type', ['cargo_solid', 'cargo_liquid', 'cargo_gas', 'cargo_bio', 'utility', 'command']);
    $table->float('mass');
    $table->float('capacity');
    $table->float('power_consumption')->nullable();
    $table->json('name');
    $table->string('dlc_id')->nullable();
    $table->timestamps();
});
```

- [ ] **Run all migrations**

```bash
php artisan migrate:fresh --no-interaction
```

Expected: 31 migrations run (3 existing + 28 new), no errors.

- [ ] **Commit**

```bash
git add database/migrations/
git commit --no-gpg-sign -m "feat: add all 27 game data table migrations"
```

---

## Task 4: Element models

**Files:**
- Create: `app/Models/Element.php` and 6 extension models + `FoodProperty.php`

- [ ] **Create Element model**

```bash
php artisan make:model Element --no-interaction
```

- [ ] **Fill Element model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Translatable\HasTranslations;

final class Element extends Model
{
    use HasTranslations;

    public array $translatable = ['name', 'description'];

    protected $fillable = [
        'element_id', 'state', 'molar_mass', 'toxicity', 'material_category',
        'tags', 'low_temp_transition_target', 'high_temp_transition_target',
        'name', 'description', 'dlc_id', 'is_disabled',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_disabled' => 'boolean',
            'name' => 'array',
            'description' => 'array',
        ];
    }

    public function thermalProperties(): HasOne
    {
        return $this->hasOne(ElementThermalProperty::class, 'element_id', 'element_id');
    }

    public function gasProperties(): HasOne
    {
        return $this->hasOne(ElementGasProperty::class, 'element_id', 'element_id');
    }

    public function liquidProperties(): HasOne
    {
        return $this->hasOne(ElementLiquidProperty::class, 'element_id', 'element_id');
    }

    public function solidProperties(): HasOne
    {
        return $this->hasOne(ElementSolidProperty::class, 'element_id', 'element_id');
    }

    public function specialProperties(): HasOne
    {
        return $this->hasOne(ElementSpecialProperty::class, 'element_id', 'element_id');
    }

    public function foodProperties(): HasOne
    {
        return $this->hasOne(FoodProperty::class, 'element_id', 'element_id');
    }

    public function lowTempTransitionElement(): HasOne
    {
        return $this->hasOne(Element::class, 'element_id', 'low_temp_transition_target');
    }

    public function highTempTransitionElement(): HasOne
    {
        return $this->hasOne(Element::class, 'element_id', 'high_temp_transition_target');
    }
}
```

- [ ] **Create extension models**

```bash
php artisan make:model ElementThermalProperty --no-interaction
php artisan make:model ElementGasProperty --no-interaction
php artisan make:model ElementLiquidProperty --no-interaction
php artisan make:model ElementSolidProperty --no-interaction
php artisan make:model ElementSpecialProperty --no-interaction
php artisan make:model FoodProperty --no-interaction
```

- [ ] **Fill extension models** (same pattern for all — shown for ElementThermalProperty, apply same structure to others)

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ElementThermalProperty extends Model
{
    protected $primaryKey = 'element_id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'element_id', 'specific_heat_capacity', 'thermal_conductivity',
        'low_temp', 'high_temp', 'default_temperature',
        'light_absorption_factor', 'radiation_absorption_factor', 'radiation_per_1000_mass',
    ];

    protected function casts(): array
    {
        return [
            'low_temp' => 'float',
            'high_temp' => 'float',
        ];
    }

    public function element(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'element_id', 'element_id');
    }
}
```

```php
// ElementGasProperty — same pattern, fields:
protected $fillable = [
    'element_id', 'flow', 'default_pressure', 'gas_surface_area_multiplier',
    'is_breathable', 'is_toxic',
];
protected function casts(): array
{
    return ['is_breathable' => 'boolean', 'is_toxic' => 'boolean'];
}

// ElementLiquidProperty — fields:
protected $fillable = ['element_id', 'flow', 'liquid_surface_area_multiplier'];

// ElementSolidProperty — fields:
protected $fillable = [
    'element_id', 'solid_surface_area_multiplier', 'hardness',
    'is_ore', 'is_metal', 'is_refined_metal',
];
protected function casts(): array
{
    return ['is_ore' => 'boolean', 'is_metal' => 'boolean', 'is_refined_metal' => 'boolean'];
}

// ElementSpecialProperty — fields:
protected $fillable = ['element_id'];

// FoodProperty — fields:
protected $fillable = ['element_id', 'calories', 'quality', 'can_rot'];
protected function casts(): array { return ['can_rot' => 'boolean']; }
```

- [ ] **Write Element model test**

```bash
php artisan make:test --pest Feature/Models/ElementTest --no-interaction
```

```php
<?php

declare(strict_types=1);

use App\Models\Element;
use App\Models\ElementThermalProperty;
use App\Models\ElementGasProperty;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a gas element with thermal and gas properties', function (): void {
    $element = Element::create([
        'element_id' => 'CarbonDioxide',
        'state' => 'gas',
        'molar_mass' => 44.01,
        'toxicity' => 0.0001,
        'material_category' => 'Unbreathable',
        'tags' => [],
        'name' => ['en' => 'Carbon Dioxide'],
        'is_disabled' => false,
    ]);

    ElementThermalProperty::create([
        'element_id' => 'CarbonDioxide',
        'specific_heat_capacity' => 0.846,
        'thermal_conductivity' => 0.0146,
        'default_temperature' => 300.0,
        'light_absorption_factor' => 0.1,
        'radiation_absorption_factor' => 0.08,
        'radiation_per_1000_mass' => 0.0,
    ]);

    ElementGasProperty::create([
        'element_id' => 'CarbonDioxide',
        'flow' => 0.1,
        'default_pressure' => 139.0,
        'gas_surface_area_multiplier' => 1.0,
        'is_breathable' => false,
        'is_toxic' => false,
    ]);

    expect($element->thermalProperties)->not->toBeNull()
        ->and($element->thermalProperties->specific_heat_capacity)->toBe(0.846)
        ->and($element->gasProperties->is_breathable)->toBeFalse()
        ->and($element->getTranslation('name', 'en'))->toBe('Carbon Dioxide');
});

it('resolves temperature transitions between elements', function (): void {
    Element::create([
        'element_id' => 'Ice',
        'state' => 'solid',
        'molar_mass' => 18.0,
        'toxicity' => 0.0,
        'material_category' => 'Water',
        'tags' => [],
        'name' => ['en' => 'Ice'],
        'high_temp_transition_target' => 'Water',
        'is_disabled' => false,
    ]);

    Element::create([
        'element_id' => 'Water',
        'state' => 'liquid',
        'molar_mass' => 18.0,
        'toxicity' => 0.0,
        'material_category' => 'Water',
        'tags' => [],
        'name' => ['en' => 'Water'],
        'is_disabled' => false,
    ]);

    $ice = Element::where('element_id', 'Ice')->first();
    expect($ice->high_temp_transition_target)->toBe('Water');
});
```

- [ ] **Run tests**

```bash
php artisan test --compact --filter=ElementTest
```

Expected: 2 passed.

- [ ] **Commit**

```bash
git add app/Models/ tests/Feature/Models/
git commit --no-gpg-sign -m "feat: add Element models with CTI pattern"
```

---

## Task 5: Remaining domain models

**Files:**
- Create: all remaining 21 models

- [ ] **Generate all models**

```bash
php artisan make:model DuplicantBaseStat --no-interaction
php artisan make:model DuplicantTrait --no-interaction
php artisan make:model DuplicantTraitEffect --no-interaction
php artisan make:model GeyserType --no-interaction
php artisan make:model Building --no-interaction
php artisan make:model Recipe --no-interaction
php artisan make:model RecipeItem --no-interaction
php artisan make:model BuildingConstructionMaterial --no-interaction
php artisan make:model Critter --no-interaction
php artisan make:model CritterMorph --no-interaction
php artisan make:model CritterMorphDiet --no-interaction
php artisan make:model CritterMorphOutput --no-interaction
php artisan make:model Plant --no-interaction
php artisan make:model PlantVariant --no-interaction
php artisan make:model PlantVariantInput --no-interaction
php artisan make:model PlantVariantOutput --no-interaction
php artisan make:model PlantMutation --no-interaction
php artisan make:model PlantMutationEffect --no-interaction
php artisan make:model RocketEngine --no-interaction
php artisan make:model RocketModule --no-interaction
```

- [ ] **Fill Critter + CritterMorph models**

```php
<?php declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

final class Critter extends Model
{
    use HasTranslations;
    public array $translatable = ['name'];
    protected $fillable = ['critter_id', 'name', 'dlc_id'];
    protected function casts(): array { return ['name' => 'array']; }
    public function morphs(): HasMany { return $this->hasMany(CritterMorph::class); }
    public function baseMorph(): HasOne { return $this->hasOne(CritterMorph::class)->where('is_base', true); }
}

final class CritterMorph extends Model
{
    use HasTranslations;
    public array $translatable = ['name'];
    protected $fillable = [
        'morph_id', 'critter_id', 'is_base', 'min_temp', 'max_temp',
        'calories_per_cycle', 'incubation_time', 'lifespan',
        'overcrowding_threshold', 'name',
    ];
    protected function casts(): array
    {
        return ['is_base' => 'boolean', 'name' => 'array'];
    }
    public function critter(): BelongsTo { return $this->belongsTo(Critter::class); }
    public function diets(): HasMany { return $this->hasMany(CritterMorphDiet::class); }
    public function outputs(): HasMany { return $this->hasMany(CritterMorphOutput::class); }
}

final class CritterMorphDiet extends Model
{
    protected $fillable = [
        'critter_morph_id', 'consumed_element_id', 'amount_per_cycle',
        'produced_element_id', 'conversion_ratio',
    ];
    public function consumedElement(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'consumed_element_id', 'element_id');
    }
    public function producedElement(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'produced_element_id', 'element_id');
    }
}

final class CritterMorphOutput extends Model
{
    protected $fillable = ['critter_morph_id', 'element_id', 'amount_per_cycle', 'output_type'];
    public function element(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'element_id', 'element_id');
    }
}
```

- [ ] **Fill Plant + PlantVariant models**

```php
<?php declare(strict_types=1);
namespace App\Models;

final class Plant extends Model
{
    use HasTranslations;
    public array $translatable = ['name'];
    protected $fillable = ['plant_id', 'name', 'dlc_id'];
    protected function casts(): array { return ['name' => 'array']; }
    public function variants(): HasMany { return $this->hasMany(PlantVariant::class); }
    public function baseVariant(): HasOne { return $this->hasOne(PlantVariant::class)->where('is_base', true); }
}

final class PlantVariant extends Model
{
    use HasTranslations;
    public array $translatable = ['name'];
    protected $fillable = [
        'variant_id', 'plant_id', 'is_base', 'min_temp', 'max_temp',
        'min_pressure', 'max_pressure', 'atmosphere_element_id',
        'light_required', 'growth_time', 'name',
    ];
    protected function casts(): array { return ['is_base' => 'boolean', 'light_required' => 'boolean', 'name' => 'array']; }
    public function plant(): BelongsTo { return $this->belongsTo(Plant::class); }
    public function inputs(): HasMany { return $this->hasMany(PlantVariantInput::class); }
    public function outputs(): HasMany { return $this->hasMany(PlantVariantOutput::class); }
    public function mutations(): BelongsToMany
    {
        return $this->belongsToMany(PlantMutation::class, 'plant_variant_mutations');
    }
}

final class PlantVariantInput extends Model
{
    protected $fillable = ['plant_variant_id', 'element_id', 'amount_per_cycle', 'input_type'];
    public function element(): BelongsTo { return $this->belongsTo(Element::class, 'element_id', 'element_id'); }
}

final class PlantVariantOutput extends Model
{
    protected $fillable = ['plant_variant_id', 'element_id', 'amount_per_harvest', 'output_type'];
    public function element(): BelongsTo { return $this->belongsTo(Element::class, 'element_id', 'element_id'); }
}

final class PlantMutation extends Model
{
    use HasTranslations;
    public array $translatable = ['name'];
    protected $fillable = ['mutation_id', 'name'];
    protected function casts(): array { return ['name' => 'array']; }
    public function effects(): HasMany { return $this->hasMany(PlantMutationEffect::class); }
}

final class PlantMutationEffect extends Model
{
    protected $fillable = ['plant_mutation_id', 'stat', 'modifier', 'modifier_type'];
}
```

- [ ] **Fill Building + Recipe models**

```php
<?php declare(strict_types=1);
namespace App\Models;

final class Building extends Model
{
    use HasTranslations;
    public array $translatable = ['name', 'description'];
    protected $fillable = [
        'building_id', 'category', 'power_consumption', 'power_generation',
        'heat_generation', 'width', 'height', 'construction_time',
        'tags', 'name', 'description', 'dlc_id',
    ];
    protected function casts(): array { return ['tags' => 'array', 'name' => 'array', 'description' => 'array']; }
    public function recipes(): HasMany { return $this->hasMany(Recipe::class); }
    public function constructionMaterials(): HasMany { return $this->hasMany(BuildingConstructionMaterial::class); }
}

final class Recipe extends Model
{
    use HasTranslations;
    public array $translatable = ['name'];
    protected $fillable = ['building_id', 'duration', 'fabricators', 'name'];
    protected function casts(): array { return ['fabricators' => 'array', 'name' => 'array']; }
    public function building(): BelongsTo { return $this->belongsTo(Building::class); }
    public function items(): HasMany { return $this->hasMany(RecipeItem::class); }
    public function inputs(): HasMany { return $this->hasMany(RecipeItem::class)->where('role', 'input'); }
    public function outputs(): HasMany { return $this->hasMany(RecipeItem::class)->where('role', 'output'); }
}

final class RecipeItem extends Model
{
    protected $fillable = ['recipe_id', 'element_id', 'amount', 'role'];
    public function element(): BelongsTo { return $this->belongsTo(Element::class, 'element_id', 'element_id'); }
}

final class BuildingConstructionMaterial extends Model
{
    protected $fillable = ['building_id', 'amount', 'material_category', 'element_id'];
    public function element(): BelongsTo { return $this->belongsTo(Element::class, 'element_id', 'element_id'); }
}
```

- [ ] **Fill remaining models (GeyserType, DuplicantBaseStat, DuplicantTrait, DuplicantTraitEffect, RocketEngine, RocketModule)**

```php
final class GeyserType extends Model
{
    use HasTranslations;
    public array $translatable = ['name'];
    protected $fillable = [
        'geyser_id', 'type', 'element_id', 'temperature', 'max_pressure',
        'min_yield_rate', 'max_yield_rate', 'min_eruption_duration', 'max_eruption_duration',
        'min_eruption_period', 'max_eruption_period', 'dormancy_min_cycles', 'dormancy_max_cycles',
        'name', 'dlc_id',
    ];
    protected function casts(): array { return ['name' => 'array']; }
    public function element(): BelongsTo { return $this->belongsTo(Element::class, 'element_id', 'element_id'); }
}

final class DuplicantBaseStat extends Model
{
    protected $fillable = [
        'oxygen_consumption_gs', 'co2_production_gs', 'calories_per_cycle', 'mass_kg', 'bladder_fill_per_cycle',
    ];
}

final class DuplicantTrait extends Model
{
    use HasTranslations;
    public array $translatable = ['name', 'description'];
    protected $fillable = ['trait_id', 'is_positive', 'name', 'description', 'dlc_id'];
    protected function casts(): array { return ['is_positive' => 'boolean', 'name' => 'array', 'description' => 'array']; }
    public function effects(): HasMany { return $this->hasMany(DuplicantTraitEffect::class); }
}

final class DuplicantTraitEffect extends Model
{
    protected $fillable = ['duplicant_trait_id', 'stat', 'modifier', 'modifier_type'];
}

final class RocketEngine extends Model
{
    use HasTranslations;
    public array $translatable = ['name'];
    protected $fillable = [
        'engine_id', 'fuel_element_id', 'oxidizer_element_id', 'max_range',
        'fuel_consumption_rate', 'oxidizer_consumption_rate', 'exhaust_temperature', 'name', 'dlc_id',
    ];
    protected function casts(): array { return ['name' => 'array']; }
    public function fuelElement(): BelongsTo { return $this->belongsTo(Element::class, 'fuel_element_id', 'element_id'); }
    public function oxidizerElement(): BelongsTo { return $this->belongsTo(Element::class, 'oxidizer_element_id', 'element_id'); }
}

final class RocketModule extends Model
{
    use HasTranslations;
    public array $translatable = ['name'];
    protected $fillable = ['module_id', 'module_type', 'mass', 'capacity', 'power_consumption', 'name', 'dlc_id'];
    protected function casts(): array { return ['name' => 'array']; }
}
```

- [ ] **Run pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Run all tests**

```bash
php artisan test --compact
```

Expected: all existing tests pass, no new failures.

- [ ] **Commit**

```bash
git add app/Models/
git commit --no-gpg-sign -m "feat: add all 27 Eloquent models with relationships"
```

---

## Task 6: Test fixtures

**Files:**
- Create: `tests/Fixtures/GameImport/*.yaml`, `*.pot`, `*.json`

- [ ] **Create fixture directory and sample YAML**

```bash
mkdir -p tests/Fixtures/GameImport
```

```yaml
# tests/Fixtures/GameImport/elements_gas.yaml
---
elements:
  - elementId: CarbonDioxide
    specificHeatCapacity: 0.846
    thermalConductivity: 0.0146
    solidSurfaceAreaMultiplier: 25
    liquidSurfaceAreaMultiplier: 1
    gasSurfaceAreaMultiplier: 1
    flow: 0.1
    lowTemp: 225
    lowTempTransitionTarget: LiquidCarbonDioxide
    defaultTemperature: 300
    defaultPressure: 139
    molarMass: 44.01
    toxicity: 0.0001
    lightAbsorptionFactor: 0.1
    radiationAbsorptionFactor: 0.08
    radiationPer1000Mass: 0
    materialCategory: Unbreathable
    tags: []
    isDisabled: false
    state: Gas
    localizationID: STRINGS.ELEMENTS.CARBONDIOXIDE.NAME
    dlcId: ""
  - elementId: Oxygen
    specificHeatCapacity: 1.005
    thermalConductivity: 0.024
    solidSurfaceAreaMultiplier: 25
    liquidSurfaceAreaMultiplier: 1
    gasSurfaceAreaMultiplier: 1
    flow: 0.1
    defaultTemperature: 300
    defaultPressure: 101
    molarMass: 32.0
    toxicity: 0
    lightAbsorptionFactor: 0
    radiationAbsorptionFactor: 0
    radiationPer1000Mass: 0
    materialCategory: Breathable
    tags:
      - Breathable
    isDisabled: false
    state: Gas
    localizationID: STRINGS.ELEMENTS.OXYGEN.NAME
    dlcId: ""
```

- [ ] **Create fixture .pot file**

```
# tests/Fixtures/GameImport/strings.pot
msgid ""
msgstr ""
"Application: Oxygen Not Included"

#. STRINGS.ELEMENTS.CARBONDIOXIDE.NAME
msgctxt "STRINGS.ELEMENTS.CARBONDIOXIDE.NAME"
msgid "Carbon Dioxide"
msgstr ""

#. STRINGS.ELEMENTS.OXYGEN.NAME
msgctxt "STRINGS.ELEMENTS.OXYGEN.NAME"
msgid "Oxygen"
msgstr ""

#. STRINGS.BUILDINGS.COALGEN.NAME
msgctxt "STRINGS.BUILDINGS.COALGEN.NAME"
msgid "Coal Generator"
msgstr ""
```

- [ ] **Create fixture JSON files**

```json
// tests/Fixtures/GameImport/buildings.json
[
  {
    "building_id": "CoalGenerator",
    "category": "Power",
    "power_generation": 600,
    "power_consumption": null,
    "heat_generation": 1.0,
    "width": 4,
    "height": 3,
    "construction_time": 60,
    "tags": ["BuildableAnyground"],
    "name_localization_id": "STRINGS.BUILDINGS.COALGEN.NAME",
    "dlc_id": null
  }
]
```

```json
// tests/Fixtures/GameImport/recipes.json
[
  {
    "building_id": "CoalGenerator",
    "duration": 600,
    "fabricators": null,
    "name_localization_id": null,
    "inputs": [{"element_id": "Carbon", "amount": 1.0}],
    "outputs": [{"element_id": "CarbonDioxide", "amount": 0.5}]
  }
]
```

```json
// tests/Fixtures/GameImport/critters.json
[
  {
    "critter_id": "HatchHardSkin",
    "parent_critter_id": "BasicHatch",
    "is_base": false,
    "min_temp": 243.15,
    "max_temp": 373.15,
    "calories_per_cycle": 700,
    "incubation_time": 9000,
    "lifespan": 25,
    "overcrowding_threshold": 8,
    "name_localization_id": null,
    "diets": [{"consumed_element_id": "Sand", "amount_per_cycle": 140, "produced_element_id": "Carbon", "conversion_ratio": 1.0}],
    "outputs": [{"element_id": "EggShell", "amount_per_cycle": 0.1, "output_type": "dropping"}]
  }
]
```

```json
// tests/Fixtures/GameImport/geyser_types.json
[
  {
    "geyser_id": "GeyserGeneric_hot_water",
    "type": "geyser",
    "element_id": "Water",
    "temperature": 368.15,
    "max_pressure": 500,
    "min_yield_rate": 1,
    "max_yield_rate": 4,
    "min_eruption_duration": 60,
    "max_eruption_duration": 1140,
    "min_eruption_period": 167,
    "max_eruption_period": 833,
    "dormancy_min_cycles": 25,
    "dormancy_max_cycles": 75,
    "name_localization_id": null,
    "dlc_id": null
  }
]
```

```json
// tests/Fixtures/GameImport/plants.json
[
  {
    "plant_id": "Mealwood",
    "variant_id": "Mealwood",
    "is_base": true,
    "min_temp": 248.15,
    "max_temp": 323.15,
    "min_pressure": 0.1,
    "max_pressure": 2,
    "atmosphere_element_id": null,
    "light_required": false,
    "growth_time": 600,
    "name_localization_id": null,
    "dlc_id": null,
    "inputs": [{"element_id": "Water", "amount_per_cycle": 0.035, "input_type": "irrigation"}],
    "outputs": [{"element_id": "MealLice", "amount_per_harvest": 0.8, "output_type": "food"}]
  }
]
```

```json
// tests/Fixtures/GameImport/duplicant_traits.json
{
  "base_stats": {
    "oxygen_consumption_gs": 100,
    "co2_production_gs": 2,
    "calories_per_cycle": 1000,
    "mass_kg": 30,
    "bladder_fill_per_cycle": 1.0
  },
  "traits": [
    {
      "trait_id": "BottomlessStomach",
      "is_positive": false,
      "name_localization_id": null,
      "dlc_id": null,
      "effects": [{"stat": "calories_per_cycle", "modifier": 2.0, "modifier_type": "multiply"}]
    }
  ]
}
```

```json
// tests/Fixtures/GameImport/rocket_components.json
{
  "engines": [
    {
      "engine_id": "RocketEngineHydrogen",
      "fuel_element_id": "LiquidHydrogen",
      "oxidizer_element_id": "LiquidOxygen",
      "max_range": 180000,
      "fuel_consumption_rate": 0.1,
      "oxidizer_consumption_rate": 0.053,
      "exhaust_temperature": 800,
      "name_localization_id": null,
      "dlc_id": null
    }
  ],
  "modules": [
    {
      "module_id": "SolidCargoBay",
      "module_type": "cargo_solid",
      "mass": 2,
      "capacity": 1,
      "power_consumption": null,
      "name_localization_id": null,
      "dlc_id": null
    }
  ]
}
```

- [ ] **Commit**

```bash
git add tests/Fixtures/
git commit --no-gpg-sign -m "test: add game import fixture files"
```

---

## Task 7: StringResolver service

**Files:**
- Create: `app/Services/GameImport/StringResolver.php`
- Create: `tests/Feature/GameImport/StringResolverTest.php`

- [ ] **Write failing test**

```bash
php artisan make:test --pest Feature/GameImport/StringResolverTest --no-interaction
```

```php
<?php

declare(strict_types=1);

use App\Services\GameImport\StringResolver;

it('resolves a localization id to english string', function (): void {
    $potPath = base_path('tests/Fixtures/GameImport/strings.pot');
    $resolver = new StringResolver($potPath);

    expect($resolver->resolve('STRINGS.ELEMENTS.CARBONDIOXIDE.NAME'))->toBe('Carbon Dioxide')
        ->and($resolver->resolve('STRINGS.ELEMENTS.OXYGEN.NAME'))->toBe('Oxygen');
});

it('returns null for unknown localization id', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));

    expect($resolver->resolve('STRINGS.UNKNOWN.KEY'))->toBeNull();
});

it('builds a name json with english key', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));

    expect($resolver->resolveToJson('STRINGS.ELEMENTS.OXYGEN.NAME'))->toBe(['en' => 'Oxygen']);
});
```

- [ ] **Run test to verify it fails**

```bash
php artisan test --compact --filter=StringResolverTest
```

Expected: FAIL — class not found.

- [ ] **Create StringResolver**

```bash
mkdir -p app/Services/GameImport
```

```php
<?php

declare(strict_types=1);

namespace App\Services\GameImport;

final class StringResolver
{
    /** @var array<string, string> */
    private array $strings = [];

    public function __construct(private readonly string $potFilePath)
    {
        $this->strings = $this->parsePot($potFilePath);
    }

    public function resolve(string $localizationId): ?string
    {
        return $this->strings[$localizationId] ?? null;
    }

    /** @return array<string, string>|null */
    public function resolveToJson(string $localizationId): ?array
    {
        $value = $this->resolve($localizationId);

        return $value !== null ? ['en' => $value] : null;
    }

    /** @return array<string, string> */
    private function parsePot(string $path): array
    {
        $content = file_get_contents($path);
        $strings = [];
        $currentCtxt = null;

        foreach (explode("\n", $content) as $line) {
            if (str_starts_with($line, 'msgctxt "')) {
                $currentCtxt = trim(substr($line, 9), '"');
            } elseif (str_starts_with($line, 'msgid "') && $currentCtxt !== null) {
                $value = trim(substr($line, 7), '"');
                if ($value !== '') {
                    $strings[$currentCtxt] = $value;
                }
                $currentCtxt = null;
            }
        }

        return $strings;
    }
}
```

- [ ] **Run tests to verify passing**

```bash
php artisan test --compact --filter=StringResolverTest
```

Expected: 3 passed.

- [ ] **Run pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Commit**

```bash
git add app/Services/GameImport/StringResolver.php tests/Feature/GameImport/StringResolverTest.php
git commit --no-gpg-sign -m "feat: add StringResolver for .pot file parsing"
```

---

## Task 8: ElementImporter service

**Files:**
- Create: `app/Services/GameImport/ElementImporter.php`
- Create: `tests/Feature/GameImport/ElementImporterTest.php`

- [ ] **Write failing test**

```bash
php artisan make:test --pest Feature/GameImport/ElementImporterTest --no-interaction
```

```php
<?php

declare(strict_types=1);

use App\Models\Element;
use App\Models\ElementGasProperty;
use App\Models\ElementThermalProperty;
use App\Services\GameImport\ElementImporter;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports gas elements from yaml with thermal and gas properties', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    $importer = new ElementImporter($resolver);

    $importer->importYaml(base_path('tests/Fixtures/GameImport/elements_gas.yaml'), 'gas');

    expect(Element::count())->toBe(2)
        ->and(ElementThermalProperty::count())->toBe(2)
        ->and(ElementGasProperty::count())->toBe(2);

    $co2 = Element::where('element_id', 'CarbonDioxide')->first();
    expect($co2)->not->toBeNull()
        ->and($co2->state)->toBe('gas')
        ->and($co2->getTranslation('name', 'en'))->toBe('Carbon Dioxide')
        ->and($co2->thermalProperties->specific_heat_capacity)->toBe(0.846)
        ->and($co2->thermalProperties->thermal_conductivity)->toBe(0.0146)
        ->and($co2->gasProperties->flow)->toBe(0.1)
        ->and($co2->gasProperties->default_pressure)->toBe(139.0)
        ->and($co2->gasProperties->is_breathable)->toBeFalse()
        ->and($co2->low_temp_transition_target)->toBe('LiquidCarbonDioxide');
});

it('sets is_breathable true when element has Breathable tag', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    $importer = new ElementImporter($resolver);

    $importer->importYaml(base_path('tests/Fixtures/GameImport/elements_gas.yaml'), 'gas');

    $oxygen = Element::where('element_id', 'Oxygen')->first();
    expect($oxygen->gasProperties->is_breathable)->toBeTrue();
});
```

- [ ] **Run test to verify it fails**

```bash
php artisan test --compact --filter=ElementImporterTest
```

Expected: FAIL — class not found.

- [ ] **Create ElementImporter**

```php
<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Element;
use App\Models\ElementGasProperty;
use App\Models\ElementLiquidProperty;
use App\Models\ElementSolidProperty;
use App\Models\ElementSpecialProperty;
use App\Models\ElementThermalProperty;
use Symfony\Component\Yaml\Yaml;

final class ElementImporter
{
    public function __construct(private readonly StringResolver $stringResolver) {}

    public function importYaml(string $yamlPath, string $state): void
    {
        $data = Yaml::parseFile($yamlPath);

        foreach ($data['elements'] as $raw) {
            if ($raw['isDisabled'] ?? false) {
                continue;
            }

            $nameJson = $this->stringResolver->resolveToJson($raw['localizationID'])
                ?? ['en' => $raw['elementId']];

            $element = Element::create([
                'element_id' => $raw['elementId'],
                'state' => strtolower($raw['state']),
                'molar_mass' => $raw['molarMass'],
                'toxicity' => $raw['toxicity'] ?? 0,
                'material_category' => $raw['materialCategory'] ?? '',
                'tags' => $raw['tags'] ?? [],
                'low_temp_transition_target' => $raw['lowTempTransitionTarget'] ?? null,
                'high_temp_transition_target' => $raw['highTempTransitionTarget'] ?? null,
                'name' => $nameJson,
                'dlc_id' => ($raw['dlcId'] ?? '') ?: null,
                'is_disabled' => false,
            ]);

            ElementThermalProperty::create([
                'element_id' => $element->element_id,
                'specific_heat_capacity' => $raw['specificHeatCapacity'],
                'thermal_conductivity' => $raw['thermalConductivity'],
                'low_temp' => $raw['lowTemp'] ?? null,
                'high_temp' => $raw['highTemp'] ?? null,
                'default_temperature' => $raw['defaultTemperature'] ?? 300,
                'light_absorption_factor' => $raw['lightAbsorptionFactor'] ?? 0,
                'radiation_absorption_factor' => $raw['radiationAbsorptionFactor'] ?? 0,
                'radiation_per_1000_mass' => $raw['radiationPer1000Mass'] ?? 0,
            ]);

            $this->createStateProperties($element->element_id, $state, $raw);
        }
    }

    /** @param array<string, mixed> $raw */
    private function createStateProperties(string $elementId, string $state, array $raw): void
    {
        $tags = $raw['tags'] ?? [];

        match ($state) {
            'gas' => ElementGasProperty::create([
                'element_id' => $elementId,
                'flow' => $raw['flow'] ?? 0,
                'default_pressure' => $raw['defaultPressure'] ?? 0,
                'gas_surface_area_multiplier' => $raw['gasSurfaceAreaMultiplier'] ?? 1,
                'is_breathable' => in_array('Breathable', $tags, true),
                'is_toxic' => ($raw['toxicity'] ?? 0) > 0,
            ]),
            'liquid' => ElementLiquidProperty::create([
                'element_id' => $elementId,
                'flow' => $raw['flow'] ?? 0,
                'liquid_surface_area_multiplier' => $raw['liquidSurfaceAreaMultiplier'] ?? 1,
            ]),
            'solid' => ElementSolidProperty::create([
                'element_id' => $elementId,
                'solid_surface_area_multiplier' => $raw['solidSurfaceAreaMultiplier'] ?? 1,
                'hardness' => $raw['hardness'] ?? null,
                'is_ore' => in_array('Ore', $tags, true),
                'is_metal' => in_array('Metal', $tags, true),
                'is_refined_metal' => in_array('RefinedMetal', $tags, true),
            ]),
            'special' => ElementSpecialProperty::create(['element_id' => $elementId]),
            default => null,
        };
    }
}
```

- [ ] **Run tests to verify passing**

```bash
php artisan test --compact --filter=ElementImporterTest
```

Expected: 2 passed.

- [ ] **Run pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/GameImport/ElementImporter.php tests/Feature/GameImport/ElementImporterTest.php
git commit --no-gpg-sign -m "feat: add ElementImporter for YAML parsing"
```

---

## Task 9: JSON importers (Buildings, Recipes, Critters, Plants, Geysers, Duplicants, Rockets)

**Files:**
- Create: `app/Services/GameImport/BuildingImporter.php`
- Create: `app/Services/GameImport/RecipeImporter.php`
- Create: `app/Services/GameImport/CritterImporter.php`
- Create: `app/Services/GameImport/PlantImporter.php`
- Create: `app/Services/GameImport/GeyserImporter.php`
- Create: `app/Services/GameImport/DuplicantImporter.php`
- Create: `app/Services/GameImport/RocketImporter.php`
- Create: `tests/Feature/GameImport/BuildingImporterTest.php`
- Create: `tests/Feature/GameImport/CritterImporterTest.php`
- Create: `tests/Feature/GameImport/PlantImporterTest.php`
- Create: `tests/Feature/GameImport/GeyserImporterTest.php`

- [ ] **Write failing BuildingImporter test**

```bash
php artisan make:test --pest Feature/GameImport/BuildingImporterTest --no-interaction
```

```php
<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Element;
use App\Services\GameImport\BuildingImporter;
use App\Services\GameImport\RecipeImporter;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Recipes reference elements — seed required elements first
    Element::create([
        'element_id' => 'Carbon', 'state' => 'solid', 'molar_mass' => 12.0,
        'toxicity' => 0, 'material_category' => 'Mineral', 'tags' => [],
        'name' => ['en' => 'Carbon'], 'is_disabled' => false,
    ]);
    Element::create([
        'element_id' => 'CarbonDioxide', 'state' => 'gas', 'molar_mass' => 44.01,
        'toxicity' => 0, 'material_category' => 'Unbreathable', 'tags' => [],
        'name' => ['en' => 'Carbon Dioxide'], 'is_disabled' => false,
    ]);
});

it('imports buildings from json', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    $importer = new BuildingImporter($resolver);

    $importer->import(base_path('tests/Fixtures/GameImport/buildings.json'));

    expect(Building::count())->toBe(1);
    $building = Building::first();
    expect($building->building_id)->toBe('CoalGenerator')
        ->and($building->category)->toBe('Power')
        ->and($building->power_generation)->toBe(600.0)
        ->and($building->getTranslation('name', 'en'))->toBe('Coal Generator');
});

it('imports recipes with items from json', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    (new BuildingImporter($resolver))->import(base_path('tests/Fixtures/GameImport/buildings.json'));
    (new RecipeImporter())->import(base_path('tests/Fixtures/GameImport/recipes.json'));

    expect(Recipe::count())->toBe(1)
        ->and(RecipeItem::count())->toBe(2);

    $recipe = Recipe::first();
    expect($recipe->inputs)->toHaveCount(1)
        ->and($recipe->outputs)->toHaveCount(1)
        ->and($recipe->inputs->first()->element_id)->toBe('Carbon');
});
```

- [ ] **Run to verify failure**

```bash
php artisan test --compact --filter=BuildingImporterTest
```

Expected: FAIL.

- [ ] **Create BuildingImporter**

```php
<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Building;

final class BuildingImporter
{
    public function __construct(private readonly StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $buildings */
        $buildings = json_decode(file_get_contents($jsonPath), true);

        foreach ($buildings as $raw) {
            $nameJson = isset($raw['name_localization_id'])
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['building_id']])
                : ['en' => $raw['building_id']];

            Building::create([
                'building_id' => $raw['building_id'],
                'category' => $raw['category'],
                'power_consumption' => $raw['power_consumption'] ?? null,
                'power_generation' => $raw['power_generation'] ?? null,
                'heat_generation' => $raw['heat_generation'] ?? 0,
                'width' => $raw['width'] ?? 1,
                'height' => $raw['height'] ?? 1,
                'construction_time' => $raw['construction_time'] ?? 0,
                'tags' => $raw['tags'] ?? [],
                'name' => $nameJson,
                'dlc_id' => ($raw['dlc_id'] ?? '') ?: null,
            ]);
        }
    }
}
```

- [ ] **Create RecipeImporter**

```php
<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Building;
use App\Models\Recipe;
use App\Models\RecipeItem;

final class RecipeImporter
{
    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $recipes */
        $recipes = json_decode(file_get_contents($jsonPath), true);

        foreach ($recipes as $raw) {
            $building = Building::where('building_id', $raw['building_id'])->first();
            if ($building === null) {
                continue;
            }

            $recipe = Recipe::create([
                'building_id' => $building->id,
                'duration' => $raw['duration'],
                'fabricators' => $raw['fabricators'] ?? null,
                'name' => $raw['name_localization_id'] ? ['en' => $raw['name_localization_id']] : null,
            ]);

            foreach ($raw['inputs'] ?? [] as $item) {
                RecipeItem::create([
                    'recipe_id' => $recipe->id,
                    'element_id' => $item['element_id'],
                    'amount' => $item['amount'],
                    'role' => 'input',
                ]);
            }

            foreach ($raw['outputs'] ?? [] as $item) {
                RecipeItem::create([
                    'recipe_id' => $recipe->id,
                    'element_id' => $item['element_id'],
                    'amount' => $item['amount'],
                    'role' => 'output',
                ]);
            }
        }
    }
}
```

- [ ] **Create CritterImporter**

```php
<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;

final class CritterImporter
{
    public function __construct(private readonly StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $critters */
        $critters = json_decode(file_get_contents($jsonPath), true);

        foreach ($critters as $raw) {
            $parentId = $raw['parent_critter_id'] ?? $raw['critter_id'];

            $critter = Critter::firstOrCreate(
                ['critter_id' => $parentId],
                ['name' => ['en' => $parentId]],
            );

            $nameJson = isset($raw['name_localization_id'])
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['critter_id']])
                : ['en' => $raw['critter_id']];

            $morph = CritterMorph::create([
                'morph_id' => $raw['critter_id'],
                'critter_id' => $critter->id,
                'is_base' => $raw['is_base'] ?? false,
                'min_temp' => $raw['min_temp'],
                'max_temp' => $raw['max_temp'],
                'calories_per_cycle' => $raw['calories_per_cycle'],
                'incubation_time' => $raw['incubation_time'],
                'lifespan' => $raw['lifespan'],
                'overcrowding_threshold' => $raw['overcrowding_threshold'] ?? 0,
                'name' => $nameJson,
            ]);

            foreach ($raw['diets'] ?? [] as $diet) {
                CritterMorphDiet::create([
                    'critter_morph_id' => $morph->id,
                    'consumed_element_id' => $diet['consumed_element_id'],
                    'amount_per_cycle' => $diet['amount_per_cycle'],
                    'produced_element_id' => $diet['produced_element_id'] ?? null,
                    'conversion_ratio' => $diet['conversion_ratio'] ?? null,
                ]);
            }

            foreach ($raw['outputs'] ?? [] as $output) {
                CritterMorphOutput::create([
                    'critter_morph_id' => $morph->id,
                    'element_id' => $output['element_id'],
                    'amount_per_cycle' => $output['amount_per_cycle'],
                    'output_type' => $output['output_type'],
                ]);
            }
        }
    }
}
```

- [ ] **Create PlantImporter**

```php
<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Plant;
use App\Models\PlantVariant;
use App\Models\PlantVariantInput;
use App\Models\PlantVariantOutput;

final class PlantImporter
{
    public function __construct(private readonly StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $plants */
        $plants = json_decode(file_get_contents($jsonPath), true);

        foreach ($plants as $raw) {
            $plant = Plant::firstOrCreate(
                ['plant_id' => $raw['plant_id']],
                ['name' => ['en' => $raw['plant_id']], 'dlc_id' => $raw['dlc_id'] ?? null],
            );

            $nameJson = isset($raw['name_localization_id'])
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['variant_id']])
                : ['en' => $raw['variant_id']];

            $variant = PlantVariant::create([
                'variant_id' => $raw['variant_id'],
                'plant_id' => $plant->id,
                'is_base' => $raw['is_base'] ?? false,
                'min_temp' => $raw['min_temp'],
                'max_temp' => $raw['max_temp'],
                'min_pressure' => $raw['min_pressure'],
                'max_pressure' => $raw['max_pressure'],
                'atmosphere_element_id' => $raw['atmosphere_element_id'] ?? null,
                'light_required' => $raw['light_required'] ?? false,
                'growth_time' => $raw['growth_time'],
                'name' => $nameJson,
            ]);

            foreach ($raw['inputs'] ?? [] as $input) {
                PlantVariantInput::create([
                    'plant_variant_id' => $variant->id,
                    'element_id' => $input['element_id'],
                    'amount_per_cycle' => $input['amount_per_cycle'],
                    'input_type' => $input['input_type'],
                ]);
            }

            foreach ($raw['outputs'] ?? [] as $output) {
                PlantVariantOutput::create([
                    'plant_variant_id' => $variant->id,
                    'element_id' => $output['element_id'],
                    'amount_per_harvest' => $output['amount_per_harvest'],
                    'output_type' => $output['output_type'],
                ]);
            }
        }
    }
}
```

- [ ] **Create GeyserImporter**

```php
<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\GeyserType;

final class GeyserImporter
{
    public function __construct(private readonly StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<int, array<string, mixed>> $geysers */
        $geysers = json_decode(file_get_contents($jsonPath), true);

        foreach ($geysers as $raw) {
            $nameJson = isset($raw['name_localization_id'])
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['geyser_id']])
                : ['en' => $raw['geyser_id']];

            GeyserType::create([
                'geyser_id' => $raw['geyser_id'],
                'type' => $raw['type'],
                'element_id' => $raw['element_id'],
                'temperature' => $raw['temperature'],
                'max_pressure' => $raw['max_pressure'],
                'min_yield_rate' => $raw['min_yield_rate'],
                'max_yield_rate' => $raw['max_yield_rate'],
                'min_eruption_duration' => $raw['min_eruption_duration'],
                'max_eruption_duration' => $raw['max_eruption_duration'],
                'min_eruption_period' => $raw['min_eruption_period'],
                'max_eruption_period' => $raw['max_eruption_period'],
                'dormancy_min_cycles' => $raw['dormancy_min_cycles'],
                'dormancy_max_cycles' => $raw['dormancy_max_cycles'],
                'name' => $nameJson,
                'dlc_id' => ($raw['dlc_id'] ?? '') ?: null,
            ]);
        }
    }
}
```

- [ ] **Create DuplicantImporter**

```php
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
```

- [ ] **Create RocketImporter**

```php
<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\RocketEngine;
use App\Models\RocketModule;

final class RocketImporter
{
    public function __construct(private readonly StringResolver $stringResolver) {}

    public function import(string $jsonPath): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(file_get_contents($jsonPath), true);

        foreach ($data['engines'] ?? [] as $raw) {
            $nameJson = isset($raw['name_localization_id'])
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['engine_id']])
                : ['en' => $raw['engine_id']];

            RocketEngine::create([
                'engine_id' => $raw['engine_id'],
                'fuel_element_id' => $raw['fuel_element_id'] ?? null,
                'oxidizer_element_id' => $raw['oxidizer_element_id'] ?? null,
                'max_range' => $raw['max_range'],
                'fuel_consumption_rate' => $raw['fuel_consumption_rate'],
                'oxidizer_consumption_rate' => $raw['oxidizer_consumption_rate'] ?? null,
                'exhaust_temperature' => $raw['exhaust_temperature'],
                'name' => $nameJson,
                'dlc_id' => ($raw['dlc_id'] ?? '') ?: null,
            ]);
        }

        foreach ($data['modules'] ?? [] as $raw) {
            $nameJson = isset($raw['name_localization_id'])
                ? ($this->stringResolver->resolveToJson($raw['name_localization_id']) ?? ['en' => $raw['module_id']])
                : ['en' => $raw['module_id']];

            RocketModule::create([
                'module_id' => $raw['module_id'],
                'module_type' => $raw['module_type'],
                'mass' => $raw['mass'],
                'capacity' => $raw['capacity'],
                'power_consumption' => $raw['power_consumption'] ?? null,
                'name' => $nameJson,
                'dlc_id' => ($raw['dlc_id'] ?? '') ?: null,
            ]);
        }
    }
}
```

- [ ] **Write and run CritterImporter test**

```bash
php artisan make:test --pest Feature/GameImport/CritterImporterTest --no-interaction
```

```php
<?php

declare(strict_types=1);

use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\Element;
use App\Services\GameImport\CritterImporter;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports a critter morph with diets and outputs', function (): void {
    foreach (['Sand', 'Carbon', 'EggShell'] as $eid) {
        Element::create([
            'element_id' => $eid, 'state' => 'solid', 'molar_mass' => 1.0,
            'toxicity' => 0, 'material_category' => 'Mineral', 'tags' => [],
            'name' => ['en' => $eid], 'is_disabled' => false,
        ]);
    }

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    (new CritterImporter($resolver))->import(base_path('tests/Fixtures/GameImport/critters.json'));

    expect(Critter::count())->toBe(1)
        ->and(CritterMorph::count())->toBe(1)
        ->and(CritterMorphDiet::count())->toBe(1);

    $morph = CritterMorph::first();
    expect($morph->morph_id)->toBe('HatchHardSkin')
        ->and($morph->calories_per_cycle)->toBe(700.0)
        ->and($morph->diets->first()->consumed_element_id)->toBe('Sand');
});
```

```bash
php artisan test --compact --filter=CritterImporterTest
```

Expected: 1 passed.

- [ ] **Run all tests**

```bash
php artisan test --compact
```

Expected: all pass.

- [ ] **Run pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/GameImport/ tests/Feature/GameImport/
git commit --no-gpg-sign -m "feat: add all JSON domain importers"
```

---

## Task 10: GameImportCommand

**Files:**
- Create: `app/Console/Commands/GameImportCommand.php`
- Create: `tests/Feature/GameImport/GameImportCommandTest.php`

- [ ] **Create command**

```bash
php artisan make:command GameImportCommand --no-interaction
```

- [ ] **Write failing test**

```bash
php artisan make:test --pest Feature/GameImport/GameImportCommandTest --no-interaction
```

```php
<?php

declare(strict_types=1);

use App\Models\Element;
use App\Models\Building;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports elements from game data yaml files', function (): void {
    $this->artisan('game:import', [
        '--game-path' => base_path('game-data/OxygenNotIncluded_Data'),
        '--skip-extractor' => true,
        '--cache-path' => base_path('tests/Fixtures/GameImport'),
    ])
        ->assertSuccessful();

    expect(Element::count())->toBeGreaterThan(0);
});

it('truncates existing data before reimport', function (): void {
    Element::create([
        'element_id' => 'OldElement', 'state' => 'gas', 'molar_mass' => 1.0,
        'toxicity' => 0, 'material_category' => 'Test', 'tags' => [],
        'name' => ['en' => 'Old'], 'is_disabled' => false,
    ]);

    $this->artisan('game:import', [
        '--game-path' => base_path('game-data/OxygenNotIncluded_Data'),
        '--skip-extractor' => true,
        '--cache-path' => base_path('tests/Fixtures/GameImport'),
    ])
        ->assertSuccessful();

    expect(Element::where('element_id', 'OldElement')->exists())->toBeFalse();
});
```

- [ ] **Run to verify failure**

```bash
php artisan test --compact --filter=GameImportCommandTest
```

Expected: FAIL.

- [ ] **Fill GameImportCommand**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\BuildingConstructionMaterial;
use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use App\Models\DuplicantBaseStat;
use App\Models\DuplicantTrait;
use App\Models\DuplicantTraitEffect;
use App\Models\Element;
use App\Models\ElementGasProperty;
use App\Models\ElementLiquidProperty;
use App\Models\ElementSolidProperty;
use App\Models\ElementSpecialProperty;
use App\Models\ElementThermalProperty;
use App\Models\FoodProperty;
use App\Models\GeyserType;
use App\Models\Plant;
use App\Models\PlantMutation;
use App\Models\PlantMutationEffect;
use App\Models\PlantVariant;
use App\Models\PlantVariantInput;
use App\Models\PlantVariantOutput;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RocketEngine;
use App\Models\RocketModule;
use App\Services\GameImport\BuildingImporter;
use App\Services\GameImport\CritterImporter;
use App\Services\GameImport\DuplicantImporter;
use App\Services\GameImport\ElementImporter;
use App\Services\GameImport\GeyserImporter;
use App\Services\GameImport\PlantImporter;
use App\Services\GameImport\RecipeImporter;
use App\Services\GameImport\RocketImporter;
use App\Services\GameImport\StringResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class GameImportCommand extends Command
{
    protected $signature = 'game:import
        {--game-path=game-data/OxygenNotIncluded_Data : Path to OxygenNotIncluded_Data directory}
        {--cache-path=json-cache : Path to json-cache directory (extractor output)}
        {--skip-extractor : Skip running the dotnet-extractor binary}';

    protected $description = 'Import all ONI game data into the database (destructive reimport)';

    public function handle(): int
    {
        $gamePath = $this->option('game-path');
        $cachePath = $this->option('cache-path');

        if (! $this->option('skip-extractor')) {
            $this->info('Running dotnet-extractor...');
            $result = 0;
            passthru(base_path('dotnet-extractor/bin/dotnet-extractor')." \"{$gamePath}\" \"{$cachePath}\"", $result);
            if ($result !== 0) {
                $this->error('dotnet-extractor failed.');

                return self::FAILURE;
            }
        }

        $this->info('Truncating game data tables...');
        $this->truncateAll();

        $stringsPath = $gamePath.'/StreamingAssets/strings/strings_template.pot';
        $resolver = new StringResolver($stringsPath);

        $this->info('Importing elements...');
        $elementImporter = new ElementImporter($resolver);
        $streamingAssets = $gamePath.'/StreamingAssets/elements';
        foreach (['gas', 'liquid', 'solid', 'special'] as $state) {
            $yamlPath = $streamingAssets."/{$state}.yaml";
            if (file_exists($yamlPath)) {
                $elementImporter->importYaml($yamlPath, $state);
            }
        }

        $this->info('Importing buildings...');
        (new BuildingImporter($resolver))->import($cachePath.'/buildings.json');

        $this->info('Importing recipes...');
        (new RecipeImporter())->import($cachePath.'/recipes.json');

        $this->info('Importing critters...');
        (new CritterImporter($resolver))->import($cachePath.'/critters.json');

        $this->info('Importing plants...');
        (new PlantImporter($resolver))->import($cachePath.'/plants.json');

        $this->info('Importing geysers...');
        (new GeyserImporter($resolver))->import($cachePath.'/geyser_types.json');

        $this->info('Importing duplicant data...');
        (new DuplicantImporter($resolver))->import($cachePath.'/duplicant_traits.json');

        $this->info('Importing rocket components...');
        (new RocketImporter($resolver))->import($cachePath.'/rocket_components.json');

        $this->info('');
        $this->info('Import complete:');
        $this->line('  Elements: '.Element::count());
        $this->line('  Buildings: '.Building::count());
        $this->line('  Recipes: '.Recipe::count());
        $this->line('  Critters: '.Critter::count().' species / '.CritterMorph::count().' morphs');
        $this->line('  Plants: '.Plant::count().' species / '.PlantVariant::count().' variants');
        $this->line('  Geysers: '.GeyserType::count());

        return self::SUCCESS;
    }

    private function truncateAll(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        $tables = [
            'plant_variant_mutations', 'plant_mutation_effects', 'plant_mutations',
            'plant_variant_outputs', 'plant_variant_inputs', 'plant_variants', 'plants',
            'critter_morph_outputs', 'critter_morph_diets', 'critter_morphs', 'critters',
            'building_construction_materials', 'recipe_items', 'recipes', 'buildings',
            'geyser_types', 'duplicant_trait_effects', 'duplicant_traits', 'duplicant_base_stats',
            'food_properties', 'element_special_properties', 'element_solid_properties',
            'element_liquid_properties', 'element_gas_properties', 'element_thermal_properties',
            'elements', 'rocket_engines', 'rocket_modules',
        ];

        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('PRAGMA foreign_keys = ON');
    }
}
```

Note: `PRAGMA foreign_keys = OFF` is SQLite-specific. For PostgreSQL, wrap in a transaction with `SET session_replication_role = replica` or use `TRUNCATE ... CASCADE`. Update `truncateAll()` when switching to PostgreSQL.

- [ ] **Run tests to verify passing**

```bash
php artisan test --compact --filter=GameImportCommandTest
```

Expected: 2 passed.

- [ ] **Run all tests**

```bash
php artisan test --compact
```

Expected: all pass.

- [ ] **Run pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/GameImportCommand.php tests/Feature/GameImport/GameImportCommandTest.php
git commit --no-gpg-sign -m "feat: add GameImportCommand orchestrating full pipeline"
```

---

## Task 11: dotnet-extractor project

**Files:**
- Create: `dotnet-extractor/DotnetExtractor.csproj`
- Create: `dotnet-extractor/Program.cs`
- Create: `dotnet-extractor/Extractors/*.cs`

This task creates the C# project skeleton. The exact reflection logic for each extractor requires decompiling `Assembly-CSharp.dll` to map class names and properties — use `ilspycmd` (see step below) to inspect the assembly, then fill in the extractors.

- [ ] **Install ilspycmd to inspect the assembly (one-time)**

```bash
dotnet tool install -g ilspycmd 2>/dev/null || true
ilspycmd game-data/OxygenNotIncluded_Data/Managed/Assembly-CSharp.dll \
  --outputdir /tmp/oni-decompiled/ 2>/dev/null | head -5
ls /tmp/oni-decompiled/ | head -20
```

This gives you the C# source to identify class names, property names, and collection accessors.

- [ ] **Create C# project**

```bash
mkdir -p dotnet-extractor/Extractors dotnet-extractor/Dtos
```

```xml
<!-- dotnet-extractor/DotnetExtractor.csproj -->
<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup>
    <OutputType>Exe</OutputType>
    <TargetFramework>net8.0</TargetFramework>
    <Nullable>enable</Nullable>
    <ImplicitUsings>enable</ImplicitUsings>
    <PublishSingleFile>true</PublishSingleFile>
    <SelfContained>true</SelfContained>
    <RuntimeIdentifier>linux-x64</RuntimeIdentifier>
  </PropertyGroup>
  <ItemGroup>
    <PackageReference Include="Mono.Cecil" Version="0.11.5" />
    <PackageReference Include="AssetRipper.IO.Files" Version="1.1.0" />
    <PackageReference Include="System.Text.Json" Version="8.0.0" />
  </ItemGroup>
</Project>
```

- [ ] **Create Program.cs entry point**

```csharp
// dotnet-extractor/Program.cs
using System.Text.Json;

if (args.Length < 2)
{
    Console.Error.WriteLine("Usage: dotnet-extractor <game-data-path> <output-cache-path>");
    return 1;
}

string gamePath = args[0];
string cachePath = args[1];
string managedPath = Path.Combine(gamePath, "Managed");
string streamingAssetsPath = Path.Combine(gamePath, "StreamingAssets");

Directory.CreateDirectory(cachePath);

var options = new JsonSerializerOptions { WriteIndented = true };

Console.WriteLine("Extracting buildings...");
var buildings = new BuildingExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "buildings.json"), JsonSerializer.Serialize(buildings, options));

Console.WriteLine("Extracting recipes...");
var recipes = new RecipeExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "recipes.json"), JsonSerializer.Serialize(recipes, options));

Console.WriteLine("Extracting critters...");
var critters = new CritterExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "critters.json"), JsonSerializer.Serialize(critters, options));

Console.WriteLine("Extracting plants...");
var plants = new PlantExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "plants.json"), JsonSerializer.Serialize(plants, options));

Console.WriteLine("Extracting geysers...");
var geysers = new GeyserExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "geyser_types.json"), JsonSerializer.Serialize(geysers, options));

Console.WriteLine("Extracting duplicant traits...");
var dupes = new DuplicantTraitExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "duplicant_traits.json"), JsonSerializer.Serialize(dupes, options));

Console.WriteLine("Extracting rocket components...");
var rockets = new RocketExtractor(managedPath).Extract();
File.WriteAllText(Path.Combine(cachePath, "rocket_components.json"), JsonSerializer.Serialize(rockets, options));

Console.WriteLine("Extracting assets...");
new AssetExtractor(gamePath).Extract(Path.Combine("..", "public", "assets", "game"));

Console.WriteLine("Done.");
return 0;
```

- [ ] **Create BuildingExtractor skeleton**

```csharp
// dotnet-extractor/Extractors/BuildingExtractor.cs
using Mono.Cecil;
using System.Text.Json.Serialization;

public record BuildingDto(
    [property: JsonPropertyName("building_id")] string BuildingId,
    [property: JsonPropertyName("category")] string Category,
    [property: JsonPropertyName("power_generation")] float? PowerGeneration,
    [property: JsonPropertyName("power_consumption")] float? PowerConsumption,
    [property: JsonPropertyName("heat_generation")] float HeatGeneration,
    [property: JsonPropertyName("width")] int Width,
    [property: JsonPropertyName("height")] int Height,
    [property: JsonPropertyName("construction_time")] float ConstructionTime,
    [property: JsonPropertyName("tags")] List<string> Tags,
    [property: JsonPropertyName("name_localization_id")] string? NameLocalizationId,
    [property: JsonPropertyName("dlc_id")] string? DlcId
);

public sealed class BuildingExtractor
{
    private readonly string _managedPath;

    public BuildingExtractor(string managedPath) => _managedPath = managedPath;

    public List<BuildingDto> Extract()
    {
        // Load Assembly-CSharp.dll using Mono.Cecil for static analysis.
        // After running ilspycmd, identify the IBuildingConfig implementations
        // and their BuildingDef fields (PrefabID, BuildingComplete.ID, etc.)
        //
        // Pattern:
        //   var assembly = AssemblyDefinition.ReadAssembly(Path.Combine(_managedPath, "Assembly-CSharp.dll"));
        //   var buildingConfigs = assembly.MainModule.Types
        //       .Where(t => t.Interfaces.Any(i => i.InterfaceType.Name == "IBuildingConfig"))
        //       .ToList();
        //   // Extract fields from each type definition
        //
        // IMPORTANT: Fill this in after inspecting the decompiled output from ilspycmd.
        // The fixture JSON in tests/Fixtures/GameImport/buildings.json documents the expected shape.

        throw new NotImplementedException(
            "Fill in after inspecting Assembly-CSharp.dll with ilspycmd. " +
            "See tests/Fixtures/GameImport/buildings.json for the expected output shape."
        );
    }
}
```

- [ ] **Create AssetExtractor skeleton**

```csharp
// dotnet-extractor/Extractors/AssetExtractor.cs
// Uses AssetRipper.IO.Files to read Unity bundle files and export sprites as PNG.
// Sprites are saved to public/assets/game/{type}/{id}.png

public sealed class AssetExtractor
{
    private readonly string _gamePath;

    public AssetExtractor(string gamePath) => _gamePath = gamePath;

    public void Extract(string outputPath)
    {
        Directory.CreateDirectory(Path.Combine(outputPath, "elements"));
        Directory.CreateDirectory(Path.Combine(outputPath, "buildings"));
        Directory.CreateDirectory(Path.Combine(outputPath, "critters"));
        Directory.CreateDirectory(Path.Combine(outputPath, "plants"));
        Directory.CreateDirectory(Path.Combine(outputPath, "geysers"));

        // Load hires_base_bundle and other bundle files from StreamingAssets/
        // Use AssetRipper.IO.Files to enumerate Texture2D assets
        // Match asset names to entity IDs and export as PNG
        //
        // Implementation depends on AssetRipper.IO.Files API — see their docs/samples.
        // Expected output: public/assets/game/elements/Water.png, buildings/CoalGenerator.png, etc.

        Console.WriteLine("Asset extraction: implement after reviewing AssetRipper.IO.Files API.");
    }
}
```

- [ ] **Create remaining extractor stubs** (CritterExtractor, PlantExtractor, GeyserExtractor, DuplicantTraitExtractor, RecipeExtractor, RocketExtractor — same pattern as BuildingExtractor, with appropriate DTO records and NotImplementedException pointing to the fixture JSON as reference shape)

- [ ] **Add dotnet-extractor to .gitignore output + verify project builds**

Add to `.gitignore`:
```
dotnet-extractor/bin/
dotnet-extractor/obj/
json-cache/
public/assets/game/
```

```bash
cd dotnet-extractor && dotnet build 2>&1 | tail -5
```

Expected: `Build succeeded.` (NotImplementedException is a runtime error, not a compile error.)

- [ ] **Commit**

```bash
git add dotnet-extractor/ .gitignore
git commit --no-gpg-sign -m "feat: add dotnet-extractor C# project skeleton with Mono.Cecil + AssetRipper"
```

---

## Task 12: Factories + run full test suite

**Files:**
- Create: `database/factories/ElementFactory.php` and key factories

- [ ] **Create element factory**

```bash
php artisan make:factory ElementFactory --model=Element --no-interaction
```

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Element;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Element> */
final class ElementFactory extends Factory
{
    protected $model = Element::class;

    public function definition(): array
    {
        return [
            'element_id' => $this->faker->unique()->word(),
            'state' => $this->faker->randomElement(['gas', 'liquid', 'solid', 'special']),
            'molar_mass' => $this->faker->randomFloat(2, 1, 200),
            'toxicity' => 0,
            'material_category' => 'Mineral',
            'tags' => [],
            'name' => ['en' => $this->faker->word()],
            'is_disabled' => false,
        ];
    }

    public function gas(): static
    {
        return $this->state(['state' => 'gas']);
    }

    public function liquid(): static
    {
        return $this->state(['state' => 'liquid']);
    }

    public function solid(): static
    {
        return $this->state(['state' => 'solid']);
    }
}
```

- [ ] **Create GeyserTypeFactory**

```bash
php artisan make:factory GeyserTypeFactory --model=GeyserType --no-interaction
```

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GeyserType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GeyserType> */
final class GeyserTypeFactory extends Factory
{
    protected $model = GeyserType::class;

    public function definition(): array
    {
        return [
            'geyser_id' => $this->faker->unique()->slug(),
            'type' => $this->faker->randomElement(['geyser', 'vent', 'volcano', 'fissure']),
            'element_id' => 'Water',
            'temperature' => $this->faker->randomFloat(2, 300, 3000),
            'max_pressure' => 500,
            'min_yield_rate' => 1,
            'max_yield_rate' => 4,
            'min_eruption_duration' => 60,
            'max_eruption_duration' => 1140,
            'min_eruption_period' => 167,
            'max_eruption_period' => 833,
            'dormancy_min_cycles' => 25,
            'dormancy_max_cycles' => 75,
            'name' => ['en' => $this->faker->words(2, true)],
        ];
    }
}
```

- [ ] **Run full test suite**

```bash
php artisan test --compact
```

Expected: all tests pass.

- [ ] **Run pint on all modified files**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Final commit**

```bash
git add database/factories/
git commit --no-gpg-sign -m "feat: add Element and GeyserType factories"
```

---

## Self-Review Notes

**Spec coverage check:**
- ✅ 27-table schema: all migrations and models covered
- ✅ Destructive reimport: `truncateAll()` in GameImportCommand
- ✅ YAML element parsing: ElementImporter + StringResolver
- ✅ JSON importers for all domains: Tasks 9-10
- ✅ spatie/laravel-translatable: Task 1 + used in all translatable models
- ✅ EN/FR JSON columns: `name`/`description` on all entities
- ✅ dotnet-extractor skeleton: Task 11
- ✅ Asset extraction: AssetExtractor stub with output directory structure
- ✅ `--skip-extractor` flag: allows testing Laravel pipeline without binary
- ✅ min/max geyser ranges: in GeyserType migration and importer
- ✅ `amount_per_harvest` (not `amount_per_cycle`) on plant_variant_outputs: correct in migration + importer
- ✅ `material_category` XOR `element_id` constraint documented in migration comment

**PostgreSQL note:** `truncateAll()` uses SQLite PRAGMA. When switching to PostgreSQL, replace with:
```php
DB::statement('SET session_replication_role = replica');
// truncate...
DB::statement('SET session_replication_role = DEFAULT');
```
Or use `DB::table($table)->delete()` which works on both drivers.
