<?php

declare(strict_types=1);

use App\Models\Colony;
use App\Models\Zone;
use App\Services\Mcp\ZoneMapRenderer;

/**
 * The sodicane farm (box 22x4 @ (99,200)): oxygen above a row of 13 farm tiles and 7 tiles
 * between two dirt cells, 13 sodicanes on the farm tiles, a 2x2 farm station anchored at
 * (114,201); the zone is the 20x2 room above the floor.
 */
function sodicaneMap(?array $rect = null, array $layers = ['terrain', 'occupancy']): string
{
    $colony = Colony::factory()->create();
    $zone = Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid]);

    return app(ZoneMapRenderer::class)->render($zone, $colony, $rect, $layers);
}

it('draws the terrain grid with tiles, gases, solids and the border ring', function (): void {
    $map = sodicaneMap();

    expect($map)->toContain('origin w(99,200) · 22x4')
        ->toContain('T  3 |::::::::::::::::::::::|')
        ->toContain('T  1 |:....................:|')
        ->toContain('T  0 |D+++++++++++++#######D|');
});

it('draws buildings over their whole footprint and plants on their cell', function (): void {
    $map = sodicaneMap();

    expect($map)->toContain('O  2 |...............FF.....|')
        ->toContain('O  1 |.sssssssssssss.FF.....|')
        ->toContain('F FarmStation 2x2: #7001 w(114,201)');
});

it('legends only the glyphs present', function (): void {
    $map = sodicaneMap();

    expect($map)->toContain('# Tile')
        ->toContain('+ FarmTile')
        ->toContain('D Dirt')
        ->toContain('. Oxygen')
        ->not->toContain('~ Water');
});

it('renders only the requested rect, in world coordinates', function (): void {
    $map = sodicaneMap(['x' => 113, 'y' => 200, 'width' => 4, 'height' => 3]);

    expect($map)->toContain('origin w(113,200) · 4x3')
        ->toContain('O  1 |.FF.|')
        ->toContain('T  0 |####|');
});

it('renders one layer when asked', function (): void {
    expect(sodicaneMap(layers: ['occupancy']))->not->toContain('T  0 |')->toContain('O  0 |');
});

it('asks for a rect when the box exceeds 40x25', function (): void {
    $colony = Colony::factory()->create();
    $zone = Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid]);
    $zone->update(['layout' => [...$zone->layout, 'box' => ['x' => 0, 'y' => 0, 'width' => 60, 'height' => 10]]]);

    $map = app(ZoneMapRenderer::class)->render($zone, $colony, null, ['terrain', 'occupancy']);

    expect($map)->toContain('too large')->toContain('rect')->not->toContain('T  0 |');
});

it('says unavailable when the layout is missing', function (): void {
    $colony = Colony::factory()->create();
    $zone = Zone::factory()->create(['colony_guid' => $colony->guid]);

    expect(app(ZoneMapRenderer::class)->render($zone, $colony, null, ['terrain', 'occupancy']))->toContain('layout unavailable');
});
