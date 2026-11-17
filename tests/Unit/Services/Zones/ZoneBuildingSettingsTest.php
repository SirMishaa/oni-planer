<?php

declare(strict_types=1);

use App\Models\Zone;
use App\Services\Zones\ZoneBuildingSettings;

/** @param  list<array<string, mixed>>  $buildings */
function settingsZone(array $buildings): Zone
{
    return Zone::factory()->make(['id' => 62, 'fast_state' => ['schema' => 1, 'buildings' => $buildings]]);
}

it('parses filter and capacity', function (): void {
    $settings = ZoneBuildingSettings::of(['settings' => [
        'filter' => ['Algae', 'Kelp'],
        'capacity' => ['limit' => 500.0, 'max' => 2000.0, 'stored' => 340.0, 'unit' => 'kg'],
    ]]);

    expect($settings->toArray())->toBe([
        'filter' => ['Algae', 'Kelp'],
        'capacity' => ['limit' => 500.0, 'max' => 2000.0, 'stored' => 340.0, 'unit' => 'kg'],
    ]);
});

it('keeps an empty filter as empty', function (): void {
    $settings = ZoneBuildingSettings::of(['settings' => ['filter' => [], 'capacity' => null]]);

    expect($settings->filter)->toBe([])
        ->and($settings->capacity)->toBeNull();
});

it('returns null without settings', function (): void {
    expect(ZoneBuildingSettings::of(['prefabId' => 'CreatureFeeder']))->toBeNull()
        ->and(ZoneBuildingSettings::of(['settings' => 'nope']))->toBeNull();
});

it('drops a capacity with an unknown unit', function (): void {
    $settings = ZoneBuildingSettings::of(['settings' => [
        'filter' => ['Algae'],
        'capacity' => ['limit' => 5.0, 'max' => 9.0, 'stored' => 1.0, 'unit' => 'liters'],
    ]]);

    expect($settings->filter)->toBe(['Algae'])
        ->and($settings->capacity)->toBeNull();
});

it('maps feeder filters by instance and ignores other buildings', function (): void {
    $filters = ZoneBuildingSettings::feederFilters(settingsZone([
        ['instanceId' => '1', 'prefabId' => 'CreatureFeeder', 'settings' => ['filter' => ['Algae'], 'capacity' => null]],
        ['instanceId' => '2', 'prefabId' => 'FishFeeder', 'settings' => ['filter' => [], 'capacity' => null]],
        ['instanceId' => '3', 'prefabId' => 'CreatureFeeder'],
        ['instanceId' => '4', 'prefabId' => 'StorageLocker', 'settings' => ['filter' => ['Sand'], 'capacity' => null]],
    ]));

    expect($filters)->toBe(['1' => ['Algae'], '2' => [], '3' => null]);
});

it('returns null feeder filters for an older mod', function (): void {
    expect(ZoneBuildingSettings::feederFilters(settingsZone([['instanceId' => '1', 'prefabId' => 'CreatureFeeder']])))->toBeNull()
        ->and(ZoneBuildingSettings::feederFilters(Zone::factory()->make(['fast_state' => null])))->toBeNull();
});
