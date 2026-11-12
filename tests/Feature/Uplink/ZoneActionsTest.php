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

it('keeps the previous slow temperatures when a new slow state arrives', function (): void {
    Event::fake([ZoneUpdated::class]);
    Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a']);
    $boxA = ['x' => 1, 'y' => 2, 'width' => 2, 'height' => 1];
    $slow = fn (array $box, array $temperature): array => ['colonyId' => 'colony-1', 'zoneId' => 'zone-a', 'box' => $box, 'temperature' => $temperature];

    app(StoreZoneState::class)->handle('slow', $slow($boxA, [280.0, 290.0]));
    expect(Zone::query()->sole()->previous_slow_temperature)->toBeNull();

    $this->travel(30)->seconds();
    app(StoreZoneState::class)->handle('slow', $slow([...$boxA, 'x' => 5], [300.0, 310.0]));
    app(StoreZoneState::class)->handle('fast', ['colonyId' => 'colony-1', 'zoneId' => 'zone-a', 'plants' => []]);

    $previous = Zone::query()->sole()->previous_slow_temperature;
    expect($previous['temperature'])->toEqual([280.0, 290.0])
        ->and($previous['box'])->toBe($boxA)
        ->and($previous['at'])->toBeString();
});
