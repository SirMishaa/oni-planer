<?php

declare(strict_types=1);

use App\Models\PrefabName;
use App\Models\Zone;
use App\Services\Zones\ZoneCritterState;
use Illuminate\Support\Facades\File;

/**
 * @param  array<string, mixed>  $fast
 * @param  array<string, mixed>  $slow
 */
function zoneWithCritterStates(array $fast = [], array $slow = []): Zone
{
    return Zone::factory()->create(['fast_state' => ['schema' => 1, ...$fast], 'slow_state' => ['schema' => 1, ...$slow]]);
}

/** @return array<string, mixed> */
function pacuRow(array $overrides = []): array
{
    return [
        'instanceId' => '1234', 'prefabId' => 'Pacu', 'cell' => 133920, 'x' => 176.42, 'y' => 221.0,
        'facing' => 'left', 'navType' => 'Swim', 'moving' => true, 'speed' => 1.0,
        'path' => [['cell' => 133919, 'navType' => 'Swim']],
        'aquatic' => true, 'tame' => true, 'baby' => false,
        'age' => ['value' => 12.4, 'max' => 25], 'calories' => ['value' => 720, 'max' => 1000],
        'fertility' => ['value' => 64, 'max' => 100], 'breeding' => [['egg' => 'PacuEgg', 'weight' => 0.94]],
        'happiness' => ['value' => 3, 'state' => 'happy'],
        'stomach' => [['food' => 'Algae', 'calories' => 1200]], 'nextPoop' => 'ToxicSand', 'hungry' => false,
        'statuses' => [['id' => 'Cramped', 'bad' => true]], 'effects' => [['id' => 'FutureOvercrowded', 'bad' => true]],
        'regionId' => 'pond:1201',
        ...$overrides,
    ];
}

it('parses critters, eggs and regions from the zone states', function (): void {
    $assets = sys_get_temp_dir().'/critter-assets-'.uniqid();
    File::ensureDirectoryExists("{$assets}/critters");
    File::put("{$assets}/critters/Pacu.webp", '');
    File::ensureDirectoryExists("{$assets}/eggs");
    File::put("{$assets}/eggs/PacuEgg.webp", '');
    config(['game.assets_path' => $assets]);

    $zone = zoneWithCritterStates([
        'critters' => [pacuRow()],
        'crowding' => [[
            'id' => 'pond:1201', 'kind' => 'pond', 'cellCount' => 104, 'occupied' => 88, 'eggOccupied' => 24,
            'counts' => ['Pacu' => 11],
            'states' => ['Pacu' => ['overcrowded' => false, 'cramped' => true, 'confined' => false, 'modifier' => 0]],
        ]],
    ], [
        'eggs' => [[
            'instanceId' => '5678', 'prefabId' => 'PacuEgg', 'cell' => 133900, 'incubation' => 38.0, 'viability' => 100.0,
            'tame' => true, 'spawns' => 'PacuBaby', 'location' => 'floor', 'holderId' => null, 'regionId' => 'pond:1201',
        ]],
    ]);

    $critter = ZoneCritterState::critters($zone)[0];
    expect($critter->instanceId)->toBe('1234')
        ->and($critter->prefabId)->toBe('Pacu')
        ->and($critter->sprite)->toEndWith('critters/Pacu.webp')
        ->and($critter->cell)->toBe(133920)
        ->and($critter->facing)->toBe('left')
        ->and($critter->navType)->toBe('Swim')
        ->and($critter->moving)->toBeTrue()
        ->and($critter->path)->toBe([['cell' => 133919, 'navType' => 'Swim']])
        ->and($critter->aquatic)->toBeTrue()
        ->and($critter->baby)->toBeFalse()
        ->and($critter->ageCycles)->toBe(12.4)
        ->and($critter->lifespanCycles)->toBe(25.0)
        ->and($critter->caloriesRatio)->toBe(0.72)
        ->and($critter->fertilityRatio)->toBe(0.64)
        ->and($critter->breeding)->toBe([['egg' => 'PacuEgg', 'name' => 'PacuEgg', 'weight' => 0.94]])
        ->and($critter->happiness)->toBe(3)
        ->and($critter->happinessState)->toBe('happy')
        ->and($critter->stomach)->toBe([['food' => 'Algae', 'calories' => 1200.0]])
        ->and($critter->nextPoop)->toBe('ToxicSand')
        ->and($critter->hungry)->toBeFalse()
        ->and($critter->statuses)->toBe([['id' => 'Cramped', 'name' => 'Cramped', 'bad' => true]])
        ->and($critter->regionId)->toBe('pond:1201');

    $egg = ZoneCritterState::eggs($zone)[0];
    expect($egg->instanceId)->toBe('5678')
        ->and($egg->prefabId)->toBe('PacuEgg')
        ->and($egg->sprite)->toEndWith('eggs/PacuEgg.webp')
        ->and($egg->incubation)->toBe(38.0)
        ->and($egg->viability)->toBe(100.0)
        ->and($egg->spawns)->toBe('PacuBaby')
        ->and($egg->location)->toBe('floor')
        ->and($egg->holderId)->toBeNull()
        ->and($egg->regionId)->toBe('pond:1201');

    $region = ZoneCritterState::regions($zone)[0];
    expect($region->id)->toBe('pond:1201')
        ->and($region->kind)->toBe('pond')
        ->and($region->cellCount)->toBe(104)
        ->and($region->occupied)->toBe(88)
        ->and($region->eggOccupied)->toBe(24)
        ->and($region->counts)->toBe(['Pacu' => 11])
        ->and($region->states['Pacu'])->toBe(['overcrowded' => false, 'cramped' => true, 'confined' => false, 'modifier' => 0]);

    File::deleteDirectory($assets);
});

it('returns null when the section is absent and an empty list when it is empty', function (): void {
    $absent = zoneWithCritterStates();
    $empty = zoneWithCritterStates(['critters' => [], 'crowding' => []], ['eggs' => []]);

    expect(ZoneCritterState::critters($absent))->toBeNull()
        ->and(ZoneCritterState::eggs($absent))->toBeNull()
        ->and(ZoneCritterState::regions($absent))->toBeNull()
        ->and(ZoneCritterState::critters($empty))->toBe([])
        ->and(ZoneCritterState::eggs($empty))->toBe([])
        ->and(ZoneCritterState::regions($empty))->toBe([]);
});

it('skips malformed rows', function (): void {
    $zone = zoneWithCritterStates([
        'critters' => [pacuRow(['instanceId' => null]), pacuRow(['instanceId' => '2', 'cell' => 'x']), 'oops', pacuRow(['instanceId' => '3'])],
        'crowding' => [['kind' => 'pond'], 'oops'],
    ], ['eggs' => [['prefabId' => 'PacuEgg', 'cell' => 1], ['instanceId' => '9', 'prefabId' => 'PacuEgg', 'cell' => 'x']]]);

    expect(array_column(ZoneCritterState::critters($zone), 'instanceId'))->toBe(['3'])
        ->and(ZoneCritterState::eggs($zone))->toBe([])
        ->and(ZoneCritterState::regions($zone))->toBe([]);
});

it('names statuses from the game strings and falls back to the id', function (): void {
    $zone = zoneWithCritterStates(['critters' => [pacuRow([
        'statuses' => [['id' => 'Hungry', 'bad' => true], ['id' => 'Mystery', 'bad' => false]],
        'effects' => [['id' => 'Confined', 'bad' => true], ['id' => 'Overcrowded', 'bad' => true], ['id' => 'Hungry', 'bad' => true]],
    ])]]);

    $names = array_column(ZoneCritterState::critters($zone)[0]->statuses, 'name', 'id');

    expect($names)->toBe([
        'Hungry' => __('STRINGS.CREATURES.STATUSITEMS.HUNGRY.NAME'),
        'Mystery' => 'Mystery',
        'Confined' => __('STRINGS.CREATURES.MODIFIERS.OVERCROWDED.CONFINED.NAME'),
        'Overcrowded' => __('STRINGS.CREATURES.MODIFIERS.OVERCROWDED.CROWDED.NAME'),
    ])->and($names['Hungry'])->not->toStartWith('STRINGS.');
});

it('keeps a carried critter with a null region', function (): void {
    $zone = zoneWithCritterStates(['critters' => [pacuRow(['regionId' => null, 'path' => [], 'age' => null, 'fertility' => null])]]);

    $critter = ZoneCritterState::critters($zone)[0];

    expect($critter->regionId)->toBeNull()
        ->and($critter->ageCycles)->toBeNull()
        ->and($critter->fertilityRatio)->toBeNull();
});

it('uses the translated prefab name when the game text knows it', function (): void {
    PrefabName::query()->create(['prefab_id' => 'PacuEgg', 'name_key' => 'STRINGS.CREATURES.SPECIES.PACUEGG.NAME']);
    $zone = zoneWithCritterStates([], ['eggs' => [[
        'instanceId' => '5678', 'prefabId' => 'PacuEgg', 'cell' => 1, 'incubation' => 1.0, 'viability' => 100.0,
        'tame' => true, 'spawns' => 'PacuBaby', 'location' => 'floor', 'holderId' => null, 'regionId' => null,
    ]]]);

    expect(ZoneCritterState::eggs($zone)[0]->name)->toBe('Pacu Egg');
});
