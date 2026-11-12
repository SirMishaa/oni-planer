<?php

declare(strict_types=1);

use App\Models\UplinkSession;
use App\Models\Zone;
use App\Models\ZoneVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a zone with versions and casts its json columns', function (): void {
    $zone = Zone::factory()->create();
    ZoneVersion::factory()->for($zone)->create(['version' => 1]);

    $zone->refresh();

    expect($zone->name)->toBe('Ferme sodicane')
        ->and($zone->orphaned)->toBeFalse()
        ->and($zone->versions)->toHaveCount(1)
        ->and($zone->versions->first()->cells)->toBeArray()->not->toBeEmpty()
        ->and($zone->versions->first()->cells)->toBe(collect($zone->versions->first()->cells)->sort()->values()->all());
});

it('scopes active zones to those not archived', function (): void {
    $active = Zone::factory()->create();
    Zone::factory()->create(['archived_at' => now()]);

    expect(Zone::query()->active()->pluck('id')->all())->toBe([$active->id]);
});

it('keeps a zone guid unique per colony only', function (): void {
    Zone::factory()->create(['colony_guid' => 'c1', 'zone_guid' => 'z1']);
    Zone::factory()->create(['colony_guid' => 'c2', 'zone_guid' => 'z1']);

    expect(fn () => Zone::factory()->create(['colony_guid' => 'c1', 'zone_guid' => 'z1']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps a version number unique per zone and cascades on delete', function (): void {
    $zone = Zone::factory()->create();
    ZoneVersion::factory()->for($zone)->create(['version' => 1]);

    expect(fn () => ZoneVersion::factory()->for($zone)->create(['version' => 1]))
        ->toThrow(UniqueConstraintViolationException::class);

    $zone->delete();
    expect(ZoneVersion::query()->count())->toBe(0);
});

it('keeps a session id unique', function (): void {
    UplinkSession::factory()->create(['session_id' => 's1']);

    expect(fn () => UplinkSession::factory()->create(['session_id' => 's1']))
        ->toThrow(UniqueConstraintViolationException::class);
});
