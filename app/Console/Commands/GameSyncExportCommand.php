<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GameImport\OniExport\ModCatalog;
use App\Services\GameImport\OniExport\OniExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

#[Description('Mirror the OniExtract export and the supported mods files into game-data')]
#[Signature('game:sync-export')]
final class GameSyncExportCommand extends Command
{
    public function handle(): int
    {
        $exportSource = (string) config('game.export_source');
        $exportPath = (string) config('game.export_path');

        if (! File::isFile($exportSource.'/database/building.json')) {
            $this->error("No OniExtract export found: {$exportSource}/database/building.json is missing.");

            return self::FAILURE;
        }

        File::deleteDirectory($exportPath);
        File::ensureDirectoryExists($exportPath);
        Process::run(['cp', '-R', $exportSource.'/.', $exportPath])->throw();

        $expected = self::countFiles($exportSource);
        $mirrored = self::countFiles($exportPath);

        if ($mirrored !== $expected) {
            $this->error("Mirror incomplete: {$mirrored} of {$expected} files copied into {$exportPath}.");

            return self::FAILURE;
        }

        $this->info(sprintf('Mirrored export: %d files into %s', $mirrored, $exportPath));

        foreach (self::modsToSync($exportPath) as $workshopId) {
            $this->syncMod($workshopId);
        }

        return self::SUCCESS;
    }

    /**
     * Files under a directory, counted with scandir: PHP's directory iterators (and so
     * File::copyDirectory and File::allFiles) skip entries on the WSL mount of the
     * Windows drive, which once left the mirror without half of ui_image.
     */
    private static function countFiles(string $directory): int
    {
        $count = 0;
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            $count += is_dir($path) ? self::countFiles($path) : 1;
        }

        return $count;
    }

    /**
     * The listed workshop ids; with the wildcard, every workshop mod of the freshly mirrored export.
     *
     * @return list<string>
     */
    private static function modsToSync(string $exportPath): array
    {
        $supported = array_values(array_map(strval(...), (array) config('game.supported_mods')));

        if (! in_array(ModCatalog::EVERY_MOD, $supported, true)) {
            return $supported;
        }

        $exported = ModCatalog::fromExport(new OniExport($exportPath), (string) config('game.mods_path'), [])->workshopIds();

        return array_values(array_unique([...$exported, ...array_diff($supported, [ModCatalog::EVERY_MOD])]));
    }

    private function syncMod(string $workshopId): void
    {
        $source = config('game.mods_source').'/'.$workshopId;
        $target = config('game.mods_path').'/'.$workshopId;

        if (! File::isDirectory($source)) {
            $this->warn("Supported mod {$workshopId} not found in {$source}, skipped.");

            return;
        }

        File::deleteDirectory($target);
        File::ensureDirectoryExists($target);

        $files = [
            ...File::glob($source.'/mod.yaml'),
            ...File::glob($source.'/translation_template.pot'),
            ...File::glob($source.'/translations/*.po'),
        ];

        foreach ($files as $file) {
            $relative = mb_substr($file, mb_strlen($source) + 1);
            File::ensureDirectoryExists(dirname($target.'/'.$relative));
            File::copy($file, $target.'/'.$relative);
        }

        $this->info(sprintf('Synced mod %s: %d files', $workshopId, count($files)));
    }
}
