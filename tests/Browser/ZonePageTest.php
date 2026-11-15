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

it('lists a door on the zone border apart and knows its state on the map', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create(['name' => 'Bassin']);
    $layout = $zone->layout;
    $anchor = end($layout['cells']);
    $layout['buildings'][] = ['instanceId' => '7101', 'prefabId' => 'Door', 'anchorCell' => $anchor, 'width' => 1, 'height' => 2, 'orientation' => 'Neutral'];
    $zone->update([
        'layout' => $layout,
        'fast_state' => [...$zone->fast_state, 'buildings' => [
            ...$zone->fast_state['buildings'],
            ['instanceId' => '7101', 'prefabId' => 'Door', 'operational' => true, 'active' => false, 'statusItems' => [], 'storage' => null],
        ]],
    ]);

    $page = visit(route('zones.show', $zone));

    $page->click('#zone-tab-entities')
        ->click('.zone-filters button:nth-child(4)')
        ->assertSee(__('zones.entities.groups.border'))
        ->click('[aria-label="'.__('zones.entities.show_on_map', ['name' => 'Door']).'"]')
        ->assertSeeIn('.zone-panel', 'Door')
        ->assertDontSee(__('zones.panel.state_unknown'))
        ->assertNoJavaScriptErrors();
});

it('zooms the map with its buttons and ctrl + wheel, never with a plain wheel', function (): void {
    $zone = Zone::factory()->sodicaneFarm()->create();

    $page = visit(route('zones.show', $zone));
    $width = fn (): string => $page->script("document.querySelector('.zone-map__stage').style.width");
    $wheel = fn (bool $ctrl): mixed => $page->script("document.querySelector('.zone-map__scroll').dispatchEvent(new WheelEvent('wheel', {deltaY: -100, ctrlKey: ".($ctrl ? 'true' : 'false').', bubbles: true, cancelable: true}))');

    $page->assertSeeIn('[data-test=zone-map-zoom-reset]', '100 %');
    $initial = $width();

    $wheel(false);
    expect($width())->toBe($initial);

    $wheel(true);
    $page->assertSeeIn('[data-test=zone-map-zoom-reset]', '125 %');
    expect((int) $width())->toBeGreaterThan((int) $initial);

    $page->click('[data-test=zone-map-zoom-reset]')
        ->assertSeeIn('[data-test=zone-map-zoom-reset]', '100 %')
        ->click('[data-test=zone-map-zoom-out]')
        ->assertSeeIn('[data-test=zone-map-zoom-reset]', '90 %')
        ->click('[data-test=zone-map-zoom-in]')
        ->click('[data-test=zone-map-zoom-in]')
        ->assertSeeIn('[data-test=zone-map-zoom-reset]', '110 %')
        ->assertNoJavaScriptErrors();
});

it('shows what each flock band eats and how long its stock lasts', function (): void {
    $pacu = App\Models\CritterMorph::factory()->for(App\Models\Critter::factory())->create(['morph_id' => 'Pacu']);
    App\Models\CritterMorphDiet::factory()->create(['critter_morph_id' => $pacu->id, 'consumed_element_id' => 'Algae', 'amount_per_cycle' => 7.5, 'produced_element_id' => 'ToxicSand', 'conversion_ratio' => 0.5, 'food_kind' => 'item']);
    App\Models\CritterMorphDiet::factory()->create(['critter_morph_id' => $pacu->id, 'consumed_element_id' => 'Kelp', 'amount_per_cycle' => 20.0, 'produced_element_id' => 'ToxicSand', 'conversion_ratio' => 0.5, 'food_kind' => 'item']);
    App\Models\CritterMorphOutput::factory()->create(['critter_morph_id' => $pacu->id, 'element_id' => 'PacuEgg', 'amount_per_cycle' => 0.05, 'output_type' => 'egg']);
    $drecko = App\Models\CritterMorph::factory()->for(App\Models\Critter::factory())->create(['morph_id' => 'Drecko']);
    App\Models\CritterMorphDiet::factory()->create(['critter_morph_id' => $drecko->id, 'consumed_element_id' => 'SaltySticksPlant', 'amount_per_cycle' => 0.75, 'produced_element_id' => 'Phosphorite', 'conversion_ratio' => 13.3, 'food_kind' => 'plant']);
    $zone = Zone::factory()->sodicaneFarm()->create();
    $critter = fn (int $index, string $prefabId): array => [
        'instanceId' => (string) (1200 + $index), 'prefabId' => $prefabId, 'cell' => 201 * 606 + 100 + $index % 10, 'x' => 100.0 + $index % 10, 'y' => 201.0, 'tame' => true,
    ];
    $zone->update(['fast_state' => [...$zone->fast_state,
        'critters' => [...array_map(fn (int $index): array => $critter($index, 'Pacu'), range(1, 38)), $critter(40, 'Drecko'), $critter(41, 'Drecko')],
        'buildings' => [...$zone->fast_state['buildings'], ['instanceId' => '9001', 'prefabId' => 'CreatureFeeder', 'operational' => true, 'active' => false, 'statusItems' => [], 'storage' => [['id' => 'Algae', 'mass' => 456.0]]]],
    ]]);

    visit(route('zones.show', $zone))
        ->click('#zone-tab-entities')
        ->click('.zone-filters button:nth-child(5)')
        ->assertSee(__('zones.flock.feeding.eats'))
        ->assertSee(__('zones.flock.feeding.grazes'))
        ->assertPresent('.zone-band__feeding .zone-chip--warn')
        ->assertNoJavaScriptErrors();
});
