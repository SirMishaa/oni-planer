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

### Data pipeline

```
OniExtract mod (in-game export) ──► game:sync-export ──► game-data/oniextract/current ──┐
Supported mods (Steam workshop) ──► game:sync-export ──► game-data/mods ────────────────┤
StreamingAssets/ (strings, codex) ──────────────────────────────────────────────────────┤
                                                                                         ▼
                                                                          php artisan game:import
                                                                                         │
                                                                                         ▼
                                                                                 SQLite / PostgreSQL
```

**Stage 1 — OniExtract export**: the OniExtract mod writes the game's runtime data (buildings, entities, elements, recipes, images) to JSON; `php artisan game:sync-export` mirrors it and the supported mods into `game-data/`.

**Stage 2 — Laravel importer** (`php artisan game:import`): readers in `app/Services/GameImport/OniExport/` turn the export into records that the importers persist into the database tables. The whole import runs in one transaction; the translation catalogs in `lang/game/` are generated alongside and only replace the previous ones once it commits. When Octane is running, the command then reloads its workers so they serve the new catalogs (if the reload fails it prints the `php artisan octane:reload` to run by hand).

**Stage 3 — Assets** (`php artisan game:import-assets`): converts the exported building renders and sprites to WebP under `public/assets/game/`.

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
- **symfony/yaml** for reading mod metadata (`mod.yaml`)
- **OniExtract** (in-game mod) for the game data export
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

Run the OniExtract export from the game's pause menu, place your game data directory at `game-data/OxygenNotIncluded_Data/` (or update the path in `.env`), then run the three steps in order:

```bash
php artisan game:sync-export     # mirror the OniExtract export and the supported mods into game-data/
php artisan game:import          # rebuild the game data tables and lang/game catalogs
php artisan game:import-assets   # convert the exported images to WebP

# Custom paths
php artisan game:import \
  --game-path=/path/to/OxygenNotIncluded_Data \
  --export-path=/path/to/oniextract/export \
  --mods-path=/path/to/mods
```

The import is **destructive** — it truncates all game data tables and reimports from scratch every run.

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
    ├── OniExport/                           # Readers: OniExtract export → records
    ├── StringResolver.php                   # .pot localization parser
    ├── ElementImporter.php                  # element records → elements tables
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

tests/
├── Feature/
│   ├── GameImport/                          # Importer tests
│   └── Models/                             # Model tests
├── Fixtures/OniExport/                      # OniExtract export slice for tests
├── Fixtures/GameImport/                     # strings, codex and translation fixtures
└── Unit/ArchTest.php                        # Architecture preset checks

game-data/                                   # gitignored — your game files here
```

## License

MIT
