<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use App\Models\Element;
use App\Models\Food;
use App\Services\GameImport\OniExport\AssetConverter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Description('Convert OniExtract building renders and connection sprites to webp assets')]
#[Signature('game:import-assets
    {--force : Reconvert files even when the destination is up to date}
    {--export-path= : OniExtract export containing the source images}
    {--diet-items-only : Convert only item sprites referenced by critter diets}')]
final class GameImportAssetsCommand extends Command
{
    private const int CONNECTION_MASKS = 16;

    private const int MIN_IMAGE_BYTES = 200;

    public function handle(AssetConverter $converter): int
    {
        $export = (string) ($this->option('export-path') ?: config('game.export_path'));
        $assets = (string) config('game.assets_path');

        if (! File::isDirectory($export.'/ui_image')) {
            $this->error("No ui_image directory in the export: {$export}.");

            return self::FAILURE;
        }

        $dietItems = $this->importDietItems($converter, $export, $assets);
        $this->info("Converted {$dietItems} diet item icons.");
        if ($this->option('diet-items-only')) {
            return self::SUCCESS;
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

        $connections += $this->importStairCaps($converter, $export, $assets);

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

        [$eggs, $eggsWithoutImage, $emptyEggImages] = $this->importEggs($converter, $export, $assets);
        [$critterImages, $emptyCritterImages] = $this->repairEmptyCritterImages($converter, $export, $assets);
        [$critterPortraits, $morphsWithoutImage] = $this->importMissingCritterPortraits($converter, $export, $assets);

        [$terrains, $solidsWithoutTexture, $solidsWithoutAsset, $missingRenderFiles] = $this->importTerrain($converter, $export, $assets);

        $this->info("Converted {$buildings} building renders, {$connections} connection sprites, {$elements} missing element icons, {$foods} food icons, {$eggs} egg images, {$critterImages} repaired critter images, {$critterPortraits} fallback critter portraits, {$terrains} terrain textures.");

        if ($foodsWithoutIcon !== []) {
            $this->warn('Missing food icons: '.implode(', ', $foodsWithoutIcon));
        }

        if ($eggsWithoutImage !== []) {
            $this->warn('Missing egg images: '.implode(', ', $eggsWithoutImage));
        }

        if ($emptyEggImages !== []) {
            $this->warn('Empty egg images: '.implode(', ', $emptyEggImages));
        }

        if ($emptyCritterImages !== []) {
            $this->warn('Empty critter images without a source: '.implode(', ', $emptyCritterImages));
        }

        if ($morphsWithoutImage !== []) {
            $this->warn('Critter morphs without an image: '.implode(', ', $morphsWithoutImage));
        }

        if ($solidsWithoutTexture !== []) {
            $this->warn('Solid elements without a terrain texture in the game (drawn flat with a stripe): '.implode(', ', $solidsWithoutTexture));
        }

        if ($solidsWithoutAsset !== []) {
            $this->error('Solid elements whose terrain texture asset is missing: '.implode(', ', $solidsWithoutAsset));
        }

        if ($missingRenderFiles !== []) {
            $this->error('Missing render files in the export: '.implode(', ', $missingRenderFiles));
        }

        return $solidsWithoutAsset === [] && $missingRenderFiles === [] && $eggsWithoutImage === [] && $emptyEggImages === [] && $emptyCritterImages === []
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function importDietItems(AssetConverter $converter, string $export, string $assets): int
    {
        $converted = 0;
        $ids = CritterMorphDiet::query()->where('food_kind', 'item')->distinct()->pluck('consumed_element_id');

        foreach ($ids as $id) {
            if ($id === '' || basename($id) !== $id
                || File::isFile("{$assets}/elements/{$id}.webp")
                || File::isFile("{$assets}/foods/{$id}.webp")
                || File::isFile("{$assets}/plants/{$id}.webp")) {
                continue;
            }

            $converted += (int) $this->convert($converter, "{$export}/ui_image/{$id}.png", "{$assets}/items/{$id}.webp");
        }

        return $converted;
    }

    /**
     * Converts the egg icons: one per distinct egg prefab of the critter outputs. An existing image under
     * {@see self::MIN_IMAGE_BYTES} is an empty placeholder: it is converted again even when newer than its source.
     *
     * @return array{int, list<string>, list<string>} converted images, prefabs without an image in the export, prefabs whose image is still empty
     */
    private function importEggs(AssetConverter $converter, string $export, string $assets): array
    {
        $converted = 0;
        $missing = [];
        $empty = [];

        $prefabs = CritterMorphOutput::query()->where('output_type', 'egg')->distinct()->orderBy('element_id')->pluck('element_id');

        foreach ($prefabs as $prefabId) {
            $prefabId = (string) $prefabId;
            $source = "{$export}/ui_image/{$prefabId}.png";

            if (! File::isFile($source)) {
                $missing[] = $prefabId;

                continue;
            }

            $destination = "{$assets}/eggs/{$prefabId}.webp";
            if (File::exists($destination) && File::size($destination) < self::MIN_IMAGE_BYTES) {
                $converter->pngToWebp($source, $destination);
                $converted++;
            } else {
                $converted += (int) $this->convert($converter, $source, $destination);
            }

            clearstatcache(true, $destination);
            if (File::size($destination) < self::MIN_IMAGE_BYTES) {
                $empty[] = $prefabId;
            }
        }

        return [$converted, $missing, $empty];
    }

    /**
     * Re-creates critter images that are empty placeholders (under {@see self::MIN_IMAGE_BYTES})
     * from the export's UI image of the same critter.
     *
     * It calls the converter directly rather than {@see self::convert()}: the empty image is usually newer
     * than its source, so the staleness check would keep it.
     *
     * @return array{int, list<string>} repaired images, empty images with no source in the export
     */
    private function repairEmptyCritterImages(AssetConverter $converter, string $export, string $assets): array
    {
        $repaired = 0;
        $unrepaired = [];

        foreach (File::glob("{$assets}/critters/*.webp") ?: [] as $image) {
            if (File::size($image) >= self::MIN_IMAGE_BYTES) {
                continue;
            }

            $critterId = pathinfo($image, PATHINFO_FILENAME);
            $source = "{$export}/ui_image/{$critterId}.png";

            if (! File::isFile($source)) {
                $unrepaired[] = $critterId;

                continue;
            }

            $converter->pngToWebp($source, $image);
            $repaired++;
        }

        return [$repaired, $unrepaired];
    }

    /**
     * Fills the portrait of every critter morph the kanim renderer has not drawn with the export's UI image
     * (`ui_image/{morph}.png`). A rendered portrait is never overwritten: only a missing webp is created.
     *
     * @return array{int, list<string>} converted portraits, morphs with neither a portrait nor a UI image
     */
    private function importMissingCritterPortraits(AssetConverter $converter, string $export, string $assets): array
    {
        $converted = 0;
        $missing = [];

        foreach (CritterMorph::query()->orderBy('morph_id')->pluck('morph_id') as $morphId) {
            $morphId = (string) $morphId;
            $destination = "{$assets}/critters/{$morphId}.webp";

            if (File::exists($destination)) {
                continue;
            }

            $source = "{$export}/ui_image/{$morphId}.png";
            if (! File::isFile($source)) {
                $missing[] = $morphId;

                continue;
            }

            $converter->pngToWebp($source, $destination);
            $converted++;
        }

        return [$converted, $missing];
    }

    /**
     * Publishes the Stairs mod's end-cap variants (`stair_caps/{prefab}/{mask}.png`) and their
     * placement (`stair_caps.json`, read by the zone presenter as `stair_caps/rects.json`).
     *
     * @return int converted variants
     */
    private function importStairCaps(AssetConverter $converter, string $export, string $assets): int
    {
        $converted = 0;

        foreach (array_filter(File::glob("{$export}/stair_caps/*/*.png") ?: [], is_string(...)) as $source) {
            $prefabId = basename(dirname($source));
            $converted += (int) $this->convert($converter, $source, "{$assets}/stair_caps/{$prefabId}/".pathinfo($source, PATHINFO_FILENAME).'.webp');
        }

        $rects = "{$export}/stair_caps.json";
        if (File::isFile($rects) && $this->isStale($rects, "{$assets}/stair_caps/rects.json")) {
            File::ensureDirectoryExists("{$assets}/stair_caps");
            File::copy($rects, "{$assets}/stair_caps/rects.json");
        }

        return $converted;
    }

    /**
     * Publishes the terrain textures, the biome mask atlas and `render.json`.
     *
     * @return array{int, list<string>, list<string>, list<string>} converted textures, solids without a
     *                                                              terrain texture, solids whose texture has no webp,
     *                                                              missing render files of the export
     */
    private function importTerrain(AssetConverter $converter, string $export, string $assets): array
    {
        $converted = 0;

        foreach (File::glob("{$export}/render/terrain/*.png") ?: [] as $source) {
            $converted += (int) $this->convert($converter, $source, "{$assets}/terrain/".pathinfo($source, PATHINFO_FILENAME).'.webp');
        }

        $missing = array_values(array_filter(
            ['render/mask_atlas.png', 'database/planner_render.json'],
            fn (string $file): bool => ! File::isFile("{$export}/{$file}"),
        ));

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

        return [$converted, $withoutTexture, $withoutAsset, $missing];
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
