<?php

declare(strict_types=1);

use App\Actions\Uplink\StoreZoneState;
use App\Events\ZoneUpdated;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('throttles fast state broadcasts to one per zone per window', function (): void {
    Event::fake([ZoneUpdated::class]);
    Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a']);
    $message = ['schema' => 1, 'colonyId' => 'colony-1', 'zoneId' => 'zone-a', 'gameSeconds' => 1.0, 'plants' => []];

    app(StoreZoneState::class)->handle('fast', $message);
    app(StoreZoneState::class)->handle('fast', [...$message, 'gameSeconds' => 2.0]);

    Event::assertDispatchedTimes(ZoneUpdated::class, 1);
    expect(Zone::query()->sole()->fast_state['gameSeconds'])->toEqual(2.0);
});

it('ignores a state for an unknown colony zone pair', function (): void {
    Event::fake([ZoneUpdated::class]);
    Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a']);

    app(StoreZoneState::class)->handle('slow', ['colonyId' => 'colony-2', 'zoneId' => 'zone-a']);

    Event::assertNotDispatched(ZoneUpdated::class);
    expect(Zone::query()->sole()->slow_state)->toBeNull();
});
