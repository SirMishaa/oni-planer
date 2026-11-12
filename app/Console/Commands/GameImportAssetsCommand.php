<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\Element;
use App\Models\Food;
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

        $foods = 0;
        $foodsWithoutIcon = [];
        foreach (Food::query()->orderBy('food_id')->pluck('food_id') as $foodId) {
            $foodId = (string) $foodId;
            $source = "{$export}/ui_image/{$foodId}.png";

            if (! File::isFile($source)) {
                $foodsWithoutIcon[] = $foodId;

                continue;
            }

            $foods += (int) $this->convert($converter, $source, "{$assets}/foods/{$foodId}.webp");
        }

        [$terrains, $solidsWithoutTexture, $solidsWithoutAsset] = $this->importTerrain($converter, $export, $assets);

        $this->info("Converted {$buildings} building renders, {$connections} connection sprites, {$elements} missing element icons, {$foods} food icons, {$terrains} terrain textures.");

        if ($foodsWithoutIcon !== []) {
            $this->warn('Missing food icons: '.implode(', ', $foodsWithoutIcon));
        }

        if ($solidsWithoutTexture !== []) {
            $this->error('Solid elements without a terrain texture: '.implode(', ', $solidsWithoutTexture));
        }

        if ($solidsWithoutAsset !== []) {
            $this->error('Solid elements whose terrain texture asset is missing: '.implode(', ', $solidsWithoutAsset));
        }

        return $solidsWithoutTexture === [] && $solidsWithoutAsset === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Publishes the terrain textures, the biome mask atlas and `render.json`.
     *
     * @return array{int, list<string>, list<string>} converted textures, solids without a
     *                                                terrain texture, solids whose texture has no webp
     */
    private function importTerrain(AssetConverter $converter, string $export, string $assets): array
    {
        $converted = 0;

        foreach (File::glob("{$export}/render/terrain/*.png") ?: [] as $source) {
            $converted += (int) $this->convert($converter, $source, "{$assets}/terrain/".pathinfo($source, PATHINFO_FILENAME).'.webp');
        }

        $converted += (int) $this->convert($converter, "{$export}/render/mask_atlas.png", "{$assets}/terrain/mask_atlas.webp");

        $renderJson = "{$export}/database/planner_render.json";
        if (File::isFile($renderJson) && $this->isStale($renderJson, "{$assets}/render.json")) {
            File::ensureDirectoryExists($assets);
            File::copy($renderJson, "{$assets}/render.json");
        }

        $withoutTexture = [];
        $withoutAsset = [];

        foreach (Element::query()->where('state', 'solid')->orderBy('element_id')->get(['element_id', 'terrain_texture']) as $solid) {
            if ($solid->terrain_texture === null) {
                $withoutTexture[] = $solid->element_id;
            } elseif (! File::isFile("{$assets}/terrain/{$solid->terrain_texture}.webp")) {
                $withoutAsset[] = $solid->element_id.' ('.$solid->terrain_texture.')';
            }
        }

        return [$converted, $withoutTexture, $withoutAsset];
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
