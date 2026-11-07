<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Element;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function writePng(string $path, int $width = 8, int $height = 6): void
{
    File::ensureDirectoryExists(dirname($path));
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 200, 10, 10, 64));
    imagepng($image, $path);
}

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/oni-assets-'.uniqid();
    $this->export = $this->workDir.'/export';
    $this->assets = $this->workDir.'/assets';

    config(['game.export_path' => $this->export, 'game.assets_path' => $this->assets]);

    Building::factory()->create(['building_id' => 'Tile']);
    Building::factory()->create(['building_id' => 'NoRender']);

    writePng($this->export.'/ui_image/Tile.png', 8, 6);
    writePng($this->export.'/ui_image/Unimported.png');
    foreach (range(0, 15) as $mask) {
        writePng($this->export.'/connection_sprites/Tile/'.$mask.'.png', 4, 4);
    }
    File::put($this->export.'/connection_sprite_cells.json', '{"Tile":{}}');
});

afterEach(function (): void {
    File::deleteDirectory($this->workDir);
});

it('converts building renders and connection sprites to webp', function (): void {
    $this->artisan('game:import-assets')->assertSuccessful();

    $render = getimagesize($this->assets.'/buildings/Tile.webp');
    $sprite = getimagesize($this->assets.'/connections/Tile/15.webp');

    expect($render['mime'])->toBe('image/webp')
        ->and([$render[0], $render[1]])->toBe([8, 6])
        ->and($sprite['mime'])->toBe('image/webp')
        ->and($this->assets.'/connections/Tile/0.webp')->toBeFile()
        ->and($this->assets.'/connections/cells.json')->toBeFile()
        ->and($this->assets.'/buildings/NoRender.webp')->not->toBeFile()
        ->and($this->assets.'/buildings/Unimported.webp')->not->toBeFile();
});

it('skips up to date files unless forced', function (): void {
    $this->artisan('game:import-assets')->assertSuccessful();
    $target = $this->assets.'/buildings/Tile.webp';
    File::put($target, 'sentinel');
    touch($this->export.'/ui_image/Tile.png', time() - 100);
    touch($target, time());

    $this->artisan('game:import-assets')->assertSuccessful();
    expect(File::get($target))->toBe('sentinel');

    $this->artisan('game:import-assets', ['--force' => true])->assertSuccessful();
    expect(getimagesize($target)['mime'])->toBe('image/webp');
});

it('does not touch critter, plant and geyser sprites', function (): void {
    foreach (['critters', 'plants', 'geysers'] as $folder) {
        File::ensureDirectoryExists($this->assets.'/'.$folder);
        File::put($this->assets.'/'.$folder.'/Keep.webp', 'untouched');
    }
    writePng($this->export.'/ui_image/Keep.png');
    Building::factory()->create(['building_id' => 'Keep']);

    $this->artisan('game:import-assets', ['--force' => true])->assertSuccessful();

    foreach (['critters', 'plants', 'geysers'] as $folder) {
        expect(File::get($this->assets.'/'.$folder.'/Keep.webp'))->toBe('untouched');
    }
});

it('only fills missing element icons', function (): void {
    Element::factory()->create(['element_id' => 'Water']);
    Element::factory()->create(['element_id' => 'Algae']);
    writePng($this->export.'/ui_image/Water.png');
    writePng($this->export.'/ui_image/Algae.png');
    File::ensureDirectoryExists($this->assets.'/elements');
    File::put($this->assets.'/elements/Water.webp', 'existing');

    $this->artisan('game:import-assets', ['--force' => true])->assertSuccessful();

    expect(File::get($this->assets.'/elements/Water.webp'))->toBe('existing')
        ->and(getimagesize($this->assets.'/elements/Algae.webp')['mime'])->toBe('image/webp');
});
