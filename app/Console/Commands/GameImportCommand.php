<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\Element;
use App\Models\GeyserType;
use App\Models\Plant;
use App\Models\PlantVariant;
use App\Models\Recipe;
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
use Illuminate\Support\Facades\Process;

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
            $result = Process::run(
                base_path('dotnet-extractor/bin/dotnet-extractor')." \"{$gamePath}\" \"{$cachePath}\"",
            );
            $this->output->write($result->output());
            if ($result->failed()) {
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
        $buildingsJson = $cachePath.'/buildings.json';
        if (file_exists($buildingsJson)) {
            (new BuildingImporter($resolver))->import($buildingsJson);
        }

        $this->info('Importing recipes...');
        $recipesJson = $cachePath.'/recipes.json';
        if (file_exists($recipesJson)) {
            (new RecipeImporter())->import($recipesJson);
        }

        $this->info('Importing critters...');
        $crittersJson = $cachePath.'/critters.json';
        if (file_exists($crittersJson)) {
            (new CritterImporter($resolver))->import($crittersJson);
        }

        $this->info('Importing plants...');
        $plantsJson = $cachePath.'/plants.json';
        if (file_exists($plantsJson)) {
            (new PlantImporter($resolver))->import($plantsJson);
        }

        $this->info('Importing geysers...');
        $geysersJson = $cachePath.'/geyser_types.json';
        if (file_exists($geysersJson)) {
            (new GeyserImporter($resolver))->import($geysersJson);
        }

        $this->info('Importing duplicant data...');
        $dupeJson = $cachePath.'/duplicant_traits.json';
        if (file_exists($dupeJson)) {
            (new DuplicantImporter($resolver))->import($dupeJson);
        }

        $this->info('Importing rocket components...');
        $rocketJson = $cachePath.'/rocket_components.json';
        if (file_exists($rocketJson)) {
            (new RocketImporter($resolver))->import($rocketJson);
        }

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
