<?php

declare(strict_types=1);

use App\Data\ZoneFeedingData;
use App\Data\ZoneFeedingLineData;
use App\Data\ZonePopulationData;
use App\Models\CritterMorph;
use App\Models\Zone;
use App\Services\Zones\RanchProblems;
use Illuminate\Support\Facades\File;

beforeEach(fn () => app()->setLocale('fr'));

function ranchFlock(?float $autonomy, ?float $deficit, ?bool $blocked = null): ZoneFeedingData
{
    return new ZoneFeedingData(
        groupKey: 'Hatch:adult', count: 4,
        foods: [new ZoneFeedingLineData('Algae', 'Algue', 12.0, 'item')],
        otherFoods: 0, produces: [], availableKg: 5.0,
        deficitKgPerCycle: $deficit, autonomyCycles: $autonomy, blockedByFilter: $blocked,
    );
}

/**
 * @param  list<ZoneFeedingData>  $feeding
 * @param  list<ZonePopulationData>  $population
 * @return list<App\Data\ZoneEntityData>
 */
function ranchProblems(Zone $zone, array $feeding, array $population): array
{
    return RanchProblems::of($zone, $feeding, $population, []);
}

/** @param  list<array<string, mixed>>  $buildings */
function ranchZone(array $buildings): Zone
{
    return Zone::factory()->make(['id' => 71, 'fast_state' => ['schema' => 1, 'buildings' => $buildings]]);
}

function ranchRegion(string $id = 'cavity:1', string $kind = 'cavity', int $cells = 40, int $occupied = 8, array $types = []): ZonePopulationData
{
    return new ZonePopulationData(
        regionId: $id, kind: $kind, cellCount: $cells, occupied: $occupied, eggOccupied: 0,
        overcrowded: false, cramped: false, confined: false, states: [], perType: [], types: $types,
    );
}

/** @param  list<string>|null  $filter */
function dropOff(?array $filter, ?float $limit = 20.0, string $prefab = 'CritterDropOff'): array
{
    return [
        'instanceId' => '501', 'prefabId' => $prefab,
        'settings' => ['filter' => $filter, 'capacity' => $limit === null ? null : ['limit' => $limit, 'max' => 50.0, 'stored' => 0.0, 'unit' => 'critters']],
    ];
}

it('warns of low stock while the feeder still holds food', function (): void {
    $problems = ranchProblems(ranchZone([]), [ranchFlock(1.25, null)], []);

    expect($problems)->toHaveCount(1)
        ->and($problems[0]->kind)->toBe('critter_group')
        ->and($problems[0]->groupKey)->toBe('Hatch:adult')
        ->and($problems[0]->problems)->toBe(['Stock bas : Algue pour 1,3 cycle (dérivé)']);
});

it('agrees the cycles of low stock in number and decimal separator', function (): void {
    expect(ranchProblems(ranchZone([]), [ranchFlock(1.0, null)], [])[0]->problems)->toBe(['Stock bas : Algue pour 1,0 cycle (dérivé)'])
        ->and(ranchProblems(ranchZone([]), [ranchFlock(1.94, null)], [])[0]->problems)->toBe(['Stock bas : Algue pour 1,9 cycle (dérivé)']);

    app()->setLocale('en');

    expect(ranchProblems(ranchZone([]), [ranchFlock(1.25, null)], [])[0]->problems)->toBe(['Low stock: Algue for 1.3 cycles (derived)']);
});

it('says out of stock when nothing is left', function (): void {
    expect(ranchProblems(ranchZone([]), [ranchFlock(0.0, 3.0)], [])[0]->problems)->toBe(['Plus de stock : Algue (dérivé)']);
});

it('ignores a stock of three cycles or more, or an unknown one', function (): void {
    expect(ranchProblems(ranchZone([]), [ranchFlock(3.5, null), ranchFlock(3.0, null), ranchFlock(null, null)], []))->toBe([]);
});

it('flags a flock blocked by filters', function (): void {
    $problems = ranchProblems(ranchZone([]), [ranchFlock(null, null, true), ranchFlock(null, null, false), ranchFlock(null, null, null)], []);

    expect($problems)->toHaveCount(1)
        ->and($problems[0]->problems)->toBe(["Aucun distributeur n'accepte sa nourriture (dérivé)"]);
});

it('flags a drop-off over its room', function (): void {
    CritterMorph::factory()->create(['morph_id' => 'Hatch', 'overcrowding_threshold' => 8]);
    CritterMorph::factory()->create(['morph_id' => 'Puft', 'overcrowding_threshold' => 4]);
    // 40 cells, 8 taken, 1 Puft in: room for floor(32 / 4) = 8 more, 4 accepted already there -> 12 supported.
    $region = ranchRegion(types: [['prefabId' => 'Puft', 'name' => 'Puft', 'count' => 1, 'personalSpace' => 4], ['prefabId' => 'Hatch', 'name' => 'Hatch', 'count' => 3, 'personalSpace' => 8]]);

    $problems = ranchProblems(ranchZone([dropOff(['Hatch', 'Puft'])]), [], [$region]);

    expect($problems)->toHaveCount(1)
        ->and($problems[0]->kind)->toBe('building')
        ->and($problems[0]->instanceId)->toBe('501')
        ->and($problems[0]->problems)->toBe(['Le point de dépôt amène jusqu\'à 20 critters, la pièce en supporte 12 (dérivé)']);
});

it('keeps a drop-off the room can hold', function (): void {
    CritterMorph::factory()->create(['morph_id' => 'Hatch', 'overcrowding_threshold' => 8]);

    expect(ranchProblems(ranchZone([dropOff(['Hatch'], 4.0)]), [], [ranchRegion()]))->toBe([]);
});

it('skips a drop-off in a zone with two cavities', function (): void {
    CritterMorph::factory()->create(['morph_id' => 'Hatch', 'overcrowding_threshold' => 8]);

    expect(ranchProblems(ranchZone([dropOff(['Hatch'])]), [], [ranchRegion(), ranchRegion('cavity:2')]))->toBe([]);
});

it('skips a drop-off with nothing ticked, no limit, no known space or no region', function (): void {
    CritterMorph::factory()->create(['morph_id' => 'Hatch', 'overcrowding_threshold' => 8]);

    expect(ranchProblems(ranchZone([dropOff([])]), [], [ranchRegion()]))->toBe([])
        ->and(ranchProblems(ranchZone([dropOff(['Hatch'], null)]), [], [ranchRegion()]))->toBe([])
        ->and(ranchProblems(ranchZone([dropOff(['Ghost'])]), [], [ranchRegion()]))->toBe([])
        ->and(ranchProblems(ranchZone([dropOff(['Hatch'])]), [], []))->toBe([])
        ->and(ranchProblems(ranchZone([dropOff(['Hatch'])]), [], [ranchRegion('pond:1', 'pond')]))->toBe([])
        ->and(ranchProblems(ranchZone([dropOff(null)]), [], [ranchRegion()]))->toBe([])
        ->and(ranchProblems(ranchZone([dropOff(['Hatch'], 20.0, 'CritterPickUp')]), [], [ranchRegion()]))->toBe([]);
});

it('checks a fish delivery point against the pond', function (): void {
    CritterMorph::factory()->create(['morph_id' => 'Pacu', 'overcrowding_threshold' => 8]);

    $problems = ranchProblems(ranchZone([dropOff(['Pacu'], 20.0, 'FishDeliveryPoint')]), [], [ranchRegion('pond:1', 'pond')]);

    expect($problems)->toHaveCount(1)->and($problems[0]->problems[0])->toContain('la pièce en supporte 4');
});

it('marks every ranch problem derived', function (): void {
    CritterMorph::factory()->create(['morph_id' => 'Hatch', 'overcrowding_threshold' => 8]);

    $problems = ranchProblems(ranchZone([dropOff(['Hatch'])]), [ranchFlock(1.0, null), ranchFlock(null, null, true)], [ranchRegion()]);

    expect($problems)->toHaveCount(3);
    foreach ($problems as $problem) {
        expect($problem->problems[0])->toEndWith(' (dérivé)');
    }
});

it('shows a drop-off problem with the translated name and the sprite of its building', function (): void {
    $assets = sys_get_temp_dir().'/ranch-assets-'.uniqid();
    File::ensureDirectoryExists("{$assets}/buildings");
    File::put("{$assets}/buildings/CritterDropOff.webp", '');
    config(['game.assets_path' => $assets]);
    App\Models\PrefabName::factory()->create(['prefab_id' => 'CritterDropOff', 'name_key' => 'STRINGS.ELEMENTS.ALGAE.NAME']);
    CritterMorph::factory()->create(['morph_id' => 'Hatch', 'overcrowding_threshold' => 8]);

    $problems = ranchProblems(ranchZone([dropOff(['Hatch'])]), [], [ranchRegion()]);

    expect($problems[0]->name)->toBe(__('STRINGS.ELEMENTS.ALGAE.NAME'))
        ->and($problems[0]->name)->not->toBe('CritterDropOff')
        ->and($problems[0]->sprite)->toBe('/assets/game/buildings/CritterDropOff.webp');

    File::deleteDirectory($assets);
});

it('leaves the sprite of a drop-off problem empty when the building has no render', function (): void {
    config(['game.assets_path' => sys_get_temp_dir().'/ranch-assets-none-'.uniqid()]);
    CritterMorph::factory()->create(['morph_id' => 'Hatch', 'overcrowding_threshold' => 8]);

    $problems = ranchProblems(ranchZone([dropOff(['Hatch'])]), [], [ranchRegion()]);

    expect($problems[0]->sprite)->toBeNull();
});
