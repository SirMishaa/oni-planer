# ONI Planner

**Oxygen Not Included planner** — Calculate oxygen, recipes, critters, and duplicant needs. Built with Laravel 13, powered by real game data extracted directly from the game files.

## What is this?

ONI Planner is a web-based planning tool for [Oxygen Not Included](https://www.klei.com/games/oxygen-not-included), similar to what [Factorio Planner](https://factoriolab.github.io) or [Satisfactory Tools](https://satisfactory.tools) are for their respective games. It lets you:

- Calculate oxygen and CO₂ production/consumption chains
- Plan building recipes and production ratios
- Manage critter populations and their diets/outputs
- Simulate plant growth cycles and resource inputs
- Analyze geyser outputs and sustainability
- Track duplicant traits and base stat requirements

## Architecture

### Two-stage data pipeline

```
Assembly-CSharp.dll ──┐
Unity asset bundles ──┤── dotnet-extractor ──► json-cache/ ──┐
StreamingAssets/ ─────┘                                       │
                                                              ▼
                                              php artisan game:import
                                                              │
                                                              ▼
                                                        SQLite / PostgreSQL
```

**Stage 1 — C# extractor** (`dotnet-extractor/`): Reads the game's managed DLL with Mono.Cecil and Unity asset bundles to extract raw game data into JSON and export sprites as PNG.

**Stage 2 — Laravel importer** (`php artisan game:import`): Reads YAML element files and the JSON cache to populate 27 database tables. Can run independently of the extractor using `--skip-extractor`.

### Database schema (27 tables)

| Domain | Tables |
|--------|--------|
| Elements | `elements`, `element_thermal_properties`, `element_gas_properties`, `element_liquid_properties`, `element_solid_properties`, `element_special_properties`, `food_properties` |
| Buildings | `buildings`, `recipes`, `recipe_items`, `building_construction_materials` |
| Critters | `critters`, `critter_morphs`, `critter_morph_diets`, `critter_morph_outputs` |
| Plants | `plants`, `plant_variants`, `plant_variant_inputs`, `plant_variant_outputs`, `plant_mutations`, `plant_mutation_effects`, `plant_variant_mutations` |
| Geysers | `geyser_types` |
| Duplicants | `duplicant_base_stats`, `duplicant_traits`, `duplicant_trait_effects` |
| Rockets | `rocket_engines`, `rocket_modules` |

Elements use a Class Table Inheritance (CTI) pattern — each state (gas, liquid, solid, special) has its own child table.

## Tech stack

- **PHP 8.5** / **Laravel 13**
- **Pest 5** for testing
- **spatie/laravel-translatable** for multilingual entity names
- **symfony/yaml** for parsing game element YAML files
- **.NET 8** / **Mono.Cecil** for DLL extraction (C# project)
- **SQLite** for development and tests, **PostgreSQL** for production
- **Tailwind CSS v4** / **Vite** for the frontend

## Getting started

### Requirements

- PHP 8.5+
- Composer
- Bun
- A copy of Oxygen Not Included (the game files)

### Setup

```bash
git clone <repo>
cd ony-planner

composer install
bun install

cp .env.example .env
php artisan key:generate

php artisan migrate
```

### Import game data

Place your game data directory at `game-data/OxygenNotIncluded_Data/` (or update the path in `.env`), then run:

```bash
# With the C# extractor (requires dotnet 8 + built binary)
php artisan game:import

# Skip the extractor if json-cache/ is already populated
php artisan game:import --skip-extractor

# Custom paths
php artisan game:import \
  --game-path=/path/to/OxygenNotIncluded_Data \
  --cache-path=/path/to/json-cache
```

The import is **destructive** — it truncates all game data tables and reimports from scratch every run.

### Building the C# extractor

```bash
cd dotnet-extractor
dotnet build
dotnet publish -r linux-x64 -o bin/
```

> **Note:** The extractor stubs are not yet implemented. They require decompiling `Assembly-CSharp.dll` with [ilspycmd](https://github.com/icsharpcode/ILSpy) to map class names and field accessors. See each `Extractors/*.cs` file for guidance.

### Development server

```bash
composer dev
# or
php artisan serve & bun run dev
```

### Testing

```bash
php artisan test --compact

# Filter a specific suite
php artisan test --compact --filter=ElementImporterTest
```

## Project structure

```
app/
├── Console/Commands/GameImportCommand.php   # game:import command
├── Models/                                  # 27 Eloquent models
└── Services/GameImport/                     # Import pipeline services
    ├── StringResolver.php                   # .pot localization parser
    ├── ElementImporter.php                  # YAML → elements tables
    ├── BuildingImporter.php
    ├── CritterImporter.php
    ├── PlantImporter.php
    ├── GeyserImporter.php
    ├── DuplicantImporter.php
    └── RocketImporter.php

database/
├── factories/                               # ElementFactory, GeyserTypeFactory
├── migrations/                              # 27 game data migrations
└── seeders/

dotnet-extractor/                            # C# .NET 8 extraction binary
├── DotnetExtractor.csproj
├── Program.cs
└── Extractors/                              # One extractor per entity type

tests/
├── Feature/
│   ├── GameImport/                          # Importer tests
│   └── Models/                             # Model tests
├── Fixtures/GameImport/                     # JSON/YAML fixtures for tests
└── Unit/ArchTest.php                        # Architecture preset checks

game-data/                                   # gitignored — your game files here
json-cache/                                  # gitignored — extractor output
```

## License

MIT
