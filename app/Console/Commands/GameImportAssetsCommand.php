<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\Element;
use App\Services\GameImport\OniExport\AssetConverter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Description('Convert OniExtract building renders and connection sprites to webp assets')]
#[Signature('game:import-assets {--force : Reconvert files even when the destination is up to date}')]
final class GameImportAssetsCommand extends Command
{
    private const int CONNECTION_MASKS = 16;

    public function handle(AssetConverter $converter): int
    {
        $export = (string) config('game.export_path');
        $assets = (string) config('game.assets_path');

        if (! File::isDirectory($export.'/ui_image')) {
            $this->error("No ui_image directory in the export: {$export}.");

            return self::FAILURE;
        }

        $buildings = 0;
        $connections = 0;

        foreach (Building::query()->pluck('building_id') as $buildingId) {
            $buildingId = (string) $buildingId;

            $buildings += (int) $this->convert($converter, "{$export}/ui_image/{$buildingId}.png", "{$assets}/buildings/{$buildingId}.webp");

            foreach (range(0, self::CONNECTION_MASKS - 1) as $mask) {
                $connections += (int) $this->convert(
                    $converter,
                    "{$export}/connection_sprites/{$buildingId}/{$mask}.png",
                    "{$assets}/connections/{$buildingId}/{$mask}.webp",
                );
            }
        }

        $cells = $export.'/connection_sprite_cells.json';
        if (File::isFile($cells) && $this->isStale($cells, $assets.'/connections/cells.json')) {
            File::ensureDirectoryExists($assets.'/connections');
            File::copy($cells, $assets.'/connections/cells.json');
        }

        $elements = 0;
        foreach (Element::query()->pluck('element_id') as $elementId) {
            $elementId = (string) $elementId;
            $destination = "{$assets}/elements/{$elementId}.webp";

            if (! File::exists($destination)) {
                $elements += (int) $this->convert($converter, "{$export}/ui_image/{$elementId}.png", $destination);
            }
        }

        $this->info("Converted {$buildings} building renders, {$connections} connection sprites, {$elements} missing element icons.");

        return self::SUCCESS;
    }

    private function convert(AssetConverter $converter, string $source, string $destination): bool
    {
        if (! File::isFile($source) || ! $this->isStale($source, $destination)) {
            return false;
        }

        $converter->pngToWebp($source, $destination);

        return true;
    }

    private function isStale(string $source, string $destination): bool
    {
        return $this->option('force')
            || ! File::exists($destination)
            || File::lastModified($source) > File::lastModified($destination);
    }
}
