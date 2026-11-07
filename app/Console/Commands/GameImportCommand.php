<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\CodexEntry;
use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\Element;
use App\Models\GeyserType;
use App\Models\Plant;
use App\Models\PlantVariant;
use App\Models\Recipe;
use App\Services\GameImport\BuildingImporter;
use App\Services\GameImport\CodexImporter;
use App\Services\GameImport\CritterImporter;
use App\Services\GameImport\DuplicantImporter;
use App\Services\GameImport\ElementImporter;
use App\Services\GameImport\GameDataTables;
use App\Services\GameImport\GameTranslationWriter;
use App\Services\GameImport\GeyserImporter;
use App\Services\GameImport\OniExport\BuildingReader;
use App\Services\GameImport\OniExport\CritterReader;
use App\Services\GameImport\OniExport\DuplicantReader;
use App\Services\GameImport\OniExport\ElementReader;
use App\Services\GameImport\OniExport\GeyserReader;
use App\Services\GameImport\OniExport\ModCatalog;
use App\Services\GameImport\OniExport\OniExport;
use App\Services\GameImport\OniExport\PlantReader;
use App\Services\GameImport\OniExport\RecipeReader;
use App\Services\GameImport\OniExport\RocketReader;
use App\Services\GameImport\OniExport\RoomTypeReader;
use App\Services\GameImport\PlantImporter;
use App\Services\GameImport\PrefabNameImporter;
use App\Services\GameImport\RecipeImporter;
use App\Services\GameImport\RocketImporter;
use App\Services\GameImport\RoomTypeImporter;
use App\Services\GameImport\StringResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

#[Description('Import all ONI game data from the OniExtract export into the database (destructive reimport)')]
#[Signature('game:import
        {--game-path=game-data/OxygenNotIncluded_Data : Path to OxygenNotIncluded_Data directory}
        {--export-path= : Synced OniExtract export directory (default: config game.export_path)}
        {--mods-path= : Synced supported mods directory (default: config game.mods_path)}
        {--game-build= : Expected game build; warns when the export was made with another one}
        {--translations-path=game-data/translations : Directory of <locale>.po translation files}
        {--lang-path=lang/game : Output directory of the generated JSON translation catalogs}')]
final class GameImportCommand extends Command
{
    public function handle(): int
    {
        $gamePath = (string) $this->option('game-path');
        $exportPath = (string) ($this->option('export-path') ?: config('game.export_path'));
        $modsPath = (string) ($this->option('mods-path') ?: config('game.mods_path'));
        /** @var list<string> $supportedMods */
        $supportedMods = array_map(strval(...), (array) config('game.supported_mods'));

        $export = new OniExport($exportPath);

        if (! $this->validateSources($export, $modsPath, $supportedMods)) {
            return self::FAILURE;
        }

        $report = [];

        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::transaction(function () use ($gamePath, $export, $modsPath, $supportedMods, &$report): void {
                $report = $this->importAll($gamePath, $export, $modsPath, $supportedMods);
            });
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        $this->forgetBuildingIndexCache();
        $this->report($report);
        $this->reloadOctaneWorkers();

        return self::SUCCESS;
    }

    /** Drops the cached building index of every catalog locale once the import is committed. */
    private function forgetBuildingIndexCache(): void
    {
        $locales = array_map(
            fn (string $catalog): string => pathinfo($catalog, PATHINFO_FILENAME),
            File::glob($this->option('lang-path').'/*.json'),
        );

        foreach (array_unique([...$locales, (string) config('app.locale')]) as $locale) {
            Cache::forget(Building::indexCacheKey($locale));
        }
    }

    /**
     * Octane workers keep the translation catalogs they already loaded, so a
     * running server is reloaded to serve the new ones. The import itself has
     * succeeded at this point: a failed reload only prints the manual step.
     */
    private function reloadOctaneWorkers(): void
    {
        try {
            if ($this->callSilently('octane:status') !== self::SUCCESS) {
                $this->line('Octane is not running: no worker to reload.');

                return;
            }

            if ($this->call('octane:reload') === self::SUCCESS) {
                return;
            }

            $reason = 'octane:reload failed';
        } catch (Throwable $exception) {
            $reason = $exception->getMessage();
        }

        $this->warn(sprintf('Could not reload the Octane workers (%s). Run `php artisan octane:reload` so they serve the new translation catalogs.', $reason));
    }

    /** @param  list<string>  $supportedMods */
    private function validateSources(OniExport $export, string $modsPath, array $supportedMods): bool
    {
        foreach (['building.json', 'planner_extras.json'] as $file) {
            if (! File::isFile($export->path.'/database/'.$file)) {
                $this->error(sprintf('OniExtract export incomplete: %s/database/%s is missing. Run game:sync-export.', $export->path, $file));

                return false;
            }
        }

        foreach ($supportedMods as $workshopId) {
            if (! File::isDirectory($modsPath.'/'.$workshopId)) {
                $this->error(sprintf('Supported mod %s not found in %s. Run game:sync-export.', $workshopId, $modsPath));

                return false;
            }
        }

        $expectedBuild = $this->option('game-build');
        if (is_string($expectedBuild) && $expectedBuild !== '' && $expectedBuild !== $export->buildVersion()) {
            $this->warn(sprintf('Export build %s differs from the expected game build %s.', $export->buildVersion(), $expectedBuild));
        }

        return true;
    }

    /**
     * @param  list<string>  $supportedMods
     * @return array{counts: array<string, string>, skippedMods: list<array{workshop_id: string, title: string, entities: int}>, skipped: array<string, int>}
     */
    private function importAll(string $gamePath, OniExport $export, string $modsPath, array $supportedMods): array
    {
        $this->info('Truncating game data tables...');
        foreach (GameDataTables::all() as $table) {
            DB::table($table)->truncate();
        }

        $catalog = ModCatalog::fromExport($export, $modsPath, $supportedMods);

        $stringsPath = $gamePath.'/StreamingAssets/strings/strings_template.pot';

        $this->info('Writing translation catalogs...');
        $translationsPath = (string) $this->option('translations-path');
        $locales = array_map(
            fn (string $po): string => pathinfo($po, PATHINFO_FILENAME),
            File::glob($translationsPath.'/*.po'),
        );
        $poPathsByLocale = [];
        foreach ($locales as $locale) {
            $poPathsByLocale[$locale] = $catalog->poPaths($locale);
        }
        new GameTranslationWriter()->write($stringsPath, $translationsPath, (string) $this->option('lang-path'), $catalog->potPaths(), $poPathsByLocale);
        $resolver = new StringResolver($stringsPath, $catalog->potPaths());

        $this->info('Importing mods...');
        $catalog->persist();

        $this->info('Importing elements...');
        new ElementImporter($resolver)->importRecords(new ElementReader($export, $catalog)->records());

        $this->info('Importing buildings...');
        $buildingReader = new BuildingReader($export, $catalog);
        $buildings = $buildingReader->records();
        new BuildingImporter($resolver)->importRecords($buildings);

        $this->info('Importing room types...');
        new RoomTypeImporter()->importRecords(new RoomTypeReader($export, $catalog)->records());

        $this->info('Importing recipes...');
        $recipeReader = new RecipeReader($export, $catalog);
        $recipeImporter = new RecipeImporter();
        $recipeImporter->importRecords($recipeReader->records());

        $this->info('Importing critters...');
        $critterReader = new CritterReader($export, $catalog);
        $critters = $critterReader->records();
        new CritterImporter($resolver)->importRecords($critters);

        $this->info('Importing plants...');
        $plantReader = new PlantReader($export, $catalog);
        $plants = $plantReader->records();
        $plantImporter = new PlantImporter($resolver);
        $plantImporter->importRecords($plants);

        $this->info('Importing geysers...');
        $geysers = new GeyserReader($export, $catalog)->records();
        new GeyserImporter($resolver)->importRecords($geysers);

        $this->info('Indexing prefab names...');
        new PrefabNameImporter($resolver, $catalog)->import(compact('buildings', 'critters', 'plants', 'geysers'));

        $this->info('Importing duplicant data...');
        new DuplicantImporter($resolver)->importRecords(new DuplicantReader($export)->records());

        $this->info('Importing rocket components...');
        $rocketReader = new RocketReader($export, $catalog);
        $rocketImporter = new RocketImporter($resolver);
        $rocketImporter->importRecords($rocketReader->records());

        $this->info('Importing codex...');
        $codexDir = $gamePath.'/StreamingAssets/codex';
        if (is_dir($codexDir)) {
            new CodexImporter($resolver)->import($codexDir);
        }

        return [
            'counts' => [
                'Elements' => (string) Element::query()->count(),
                'Buildings' => (string) Building::query()->count(),
                'Recipes' => (string) Recipe::query()->count(),
                'Critters' => Critter::query()->count().' species / '.CritterMorph::query()->count().' morphs',
                'Plants' => Plant::query()->count().' species / '.PlantVariant::query()->count().' variants',
                'Geysers' => (string) GeyserType::query()->count(),
                'Codex' => CodexEntry::query()->count().' entries',
            ],
            'skippedMods' => $catalog->skippedReport(),
            'skipped' => [
                'buildings' => count($buildingReader->skippedBuildings()),
                'recipes' => $recipeReader->skippedRecipes(),
                'recipe references' => $recipeReader->skippedReferences() + $recipeImporter->skippedReferences(),
                'critter references' => $critterReader->skippedReferences(),
                'plant references' => $plantReader->skippedReferences() + $plantImporter->skippedReferences(),
                'rocket references' => $rocketReader->skippedReferences() + $rocketImporter->skippedReferences(),
            ],
        ];
    }

    /** @param  array{counts: array<string, string>, skippedMods: list<array{workshop_id: string, title: string, entities: int}>, skipped: array<string, int>}  $report */
    private function report(array $report): void
    {
        $this->info('');
        $this->info('Import complete:');
        foreach ($report['counts'] as $label => $count) {
            $this->line(sprintf('  %s: %s', $label, $count));
        }

        $this->info('Skipped unsupported mods:');
        foreach ($report['skippedMods'] as $mod) {
            $this->line(sprintf('  %s (%s): %d entities', $mod['title'], $mod['workshop_id'], $mod['entities']));
        }

        $this->info('Skipped references:');
        foreach ($report['skipped'] as $label => $count) {
            $this->line(sprintf('  %s: %d', $label, $count));
        }
    }
}
