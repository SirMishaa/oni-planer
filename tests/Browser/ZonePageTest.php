<?php

declare(strict_types=1);

use App\Models\Colony;
use App\Models\Zone;

it('opens a zone from the list and shows its map and entities', function (): void {
    $colony = Colony::factory()->create(['name' => 'DeepWaterGo']);
    Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Ferme sodicane']);

    $page = visit(route('zones.index'));

    $page->assertSee('Ferme sodicane')
        ->click('Ferme sodicane')
        ->assertPresent('canvas[data-paint-ms]')
        ->assertSee('Insufficient Resources: Fertilizer')
        ->click('#zone-tab-entities')
        ->assertSee(__('zones.entities.columns.harvest_in'))
        ->assertNoJavaScriptErrors();
});

it('draws a 2000-cell zone', function (): void {
    $width = 50;
    $height = 40;
    $gridWidth = 606;
    $box = ['x' => 100, 'y' => 100, 'width' => $width, 'height' => $height];
    $cells = [];
    for ($y = 100; $y < 100 + $height; $y++) {
        for ($x = 100; $x < 100 + $width; $x++) {
            $cells[] = $y * $gridWidth + $x;
        }
    }

    $zone = Zone::factory()->create([
        'cell_count' => count($cells),
        'layout' => ['schema' => 1, 'gridWidth' => $gridWidth, 'box' => $box, 'cells' => $cells, 'tiles' => [], 'buildings' => []],
        'slow_state' => [
            'schema' => 1,
            'box' => $box,
            'palette' => ['Oxygen', 'Water'],
            'element' => array_map(fn (int $index): int => $index % 7 === 0 ? 1 : 0, range(0, count($cells) - 1)),
            'mass' => array_fill(0, count($cells), 1.2),
            'temperature' => array_map(fn (int $index): float => 280 + $index % 40, range(0, count($cells) - 1)),
        ],
        'layout_at' => now(),
        'slow_state_at' => now(),
    ]);

    visit(route('zones.show', $zone))
        ->assertPresent('canvas[data-paint-ms]')
        ->click(__('zones.layers.temperature'))
        ->assertPresent('canvas[data-paint-ms]')
        ->click(__('zones.map.modes.state_change'))
        ->assertPresent('canvas[data-paint-ms]')
        ->click(__('zones.map.modes.heat_flow'))
        ->assertSee(__('zones.map.heat_flow_waiting'))
        ->assertNoJavaScriptErrors();
});

it('toggles the grid', function (): void {
    $colony = Colony::factory()->create();
    $zone = Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid]);

    visit(route('zones.show', $zone))
        ->assertPresent('canvas[data-paint-ms]')
        ->click(__('zones.map.grid'))
        ->assertAttribute('[data-test="zone-map-grid"]', 'aria-pressed', 'true')
        ->assertNoJavaScriptErrors();
});
