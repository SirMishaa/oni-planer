<?php

declare(strict_types=1);

use App\Events\Uplink\UplinkCommandIssued;
use App\Models\UplinkCommand;
use App\Models\Zone;
use App\Models\ZoneCapture;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Sleep::fake();
    Storage::fake('public');
    $this->zone = Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a', 'name' => 'Bassin', 'layout' => ['signature' => 'current']]);
});

it('waits for the capture and prints its absolute path', function (): void {
    Event::listen(UplinkCommandIssued::class, function (UplinkCommandIssued $event): void {
        ZoneCapture::factory()->for($this->zone)->create([
            'path' => 'zone-captures/zone-a/shot.png', 'command_id' => $event->command->id, 'layout_signature' => 'current',
        ]);
    });

    $this->artisan('uplink:capture', ['zone' => $this->zone->id, '--timeout' => 1])
        ->expectsOutputToContain(Storage::disk('public')->path('zone-captures/zone-a/shot.png'))
        ->expectsOutputToContain('matches the current site layout')
        ->assertSuccessful();

    expect(UplinkCommand::query()->sole()->type)->toBe('zone.capture');
});

it('says when the capture shows an older layout', function (): void {
    Event::listen(UplinkCommandIssued::class, function (UplinkCommandIssued $event): void {
        ZoneCapture::factory()->for($this->zone)->create(['command_id' => $event->command->id, 'layout_signature' => 'older']);
    });

    $this->artisan('uplink:capture', ['zone' => 'zone-a', '--timeout' => 1])
        ->expectsOutputToContain('taken on an older layout')
        ->assertSuccessful();
});

it('fails with the reason when the mod refuses', function (): void {
    Event::listen(UplinkCommandIssued::class, function (UplinkCommandIssued $event): void {
        $event->command->update(['status' => UplinkCommand::REFUSED, 'reason' => 'zone on another world']);
    });

    $this->artisan('uplink:capture', ['zone' => $this->zone->id, '--timeout' => 1])
        ->expectsOutputToContain('zone on another world')
        ->assertFailed();
});

it('gives up after the timeout', function (): void {
    $this->artisan('uplink:capture', ['zone' => $this->zone->id, '--timeout' => 1])
        ->expectsOutputToContain('No capture after 1 s: is the game running and linked?')
        ->assertFailed();
});

it('fails for an unknown zone', function (): void {
    $this->artisan('uplink:capture', ['zone' => '999999'])
        ->expectsOutputToContain('No zone 999999.')
        ->assertFailed();
});
