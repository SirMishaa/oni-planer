<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

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
        File::copyDirectory($exportSource, $exportPath);
        $this->info(sprintf('Mirrored export: %d files into %s', count(File::allFiles($exportPath)), $exportPath));

        foreach ((array) config('game.supported_mods') as $workshopId) {
            $this->syncMod((string) $workshopId);
        }

        return self::SUCCESS;
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
