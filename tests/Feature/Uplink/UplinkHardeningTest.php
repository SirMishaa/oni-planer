<?php

declare(strict_types=1);

use App\Actions\Uplink\StoreZoneState;
use App\Events\Uplink\UplinkCommandIssued;
use App\Events\Uplink\UplinkMessageAccepted;
use App\Events\Uplink\ZoneStateStored;
use App\Listeners\Uplink\ResyncZoneWithoutLayout;
use App\Models\Colony;
use App\Models\UplinkCommand;
use App\Models\UplinkMessage;
use App\Models\Zone;
use App\Services\Uplink\ColonyIngestor;
use Illuminate\Support\Facades\Event;

it('drops a zone state whose sessionId is not a short identifier', function (string $sessionId): void {
    Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a']);

    $stored = app(StoreZoneState::class)->handle('fast', ['schema' => 1, 'colonyId' => 'colony-1', 'zoneId' => 'zone-a', 'gameSeconds' => 1.0, 'sessionId' => $sessionId]);

    expect($stored)->toBeFalse()->and(Zone::query()->sole()->fast_state)->toBeNull();
})->with(['too long' => [str_repeat('a', 300)], 'nul byte' => ["abc\0def"], 'empty' => ['']]);

it('refuses a colony state whose sessionId is not a short identifier', function (): void {
    $message = app(ColonyIngestor::class)->ingest(uplinkFixture(['sessionId' => str_repeat('a', 300), 'gameSeconds' => 1.0]));

    expect($message->outcome)->toBe('refused')->and($message->reason)->toBe('invalid_session')
        ->and(Colony::query()->count())->toBe(0);
});

it('journals unreadable colony frames', function (string $frame, string $reason): void {
    receiveUplinkData($frame);

    expect(UplinkMessage::query()->sole())->outcome->toBe('refused')->reason->toBe($reason);
})->with([
    'broken json' => ['{"event":"client-colony.state","channel":"uplink","data":{', 'invalid_json'],
    'wrong channel' => ['{"event":"client-colony.state","channel":"uplink-data","data":{"schema":1}}', 'wrong_channel'],
]);

it('acks and drops a zone list with a malformed entry', function (): void {
    Event::fake([UplinkMessageAccepted::class]);

    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a'), ['zoneId' => 'zone-b']])));

    Event::assertDispatched(UplinkMessageAccepted::class, fn (UplinkMessageAccepted $e): bool => $e->id === 'msg-list-1');
    expect(Zone::query()->count())->toBe(0);
});

it('acks and drops a layout with missing fields', function (string $missing): void {
    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')])));
    Event::fake([UplinkMessageAccepted::class]);
    $layout = zoneLayoutMessage([10, 11]);
    unset($layout[$missing]);

    receiveUplinkData(uplinkData('client-zone.layout', $layout));

    Event::assertDispatched(UplinkMessageAccepted::class);
    expect(Zone::query()->sole()->layout)->toBeNull();
})->with(['version', 'gameSeconds']);

it('acks and drops a layout whose cells are not integers', function (): void {
    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')])));
    Event::fake([UplinkMessageAccepted::class]);

    receiveUplinkData(uplinkData('client-zone.layout', zoneLayoutMessage(['x', 11])));

    Event::assertDispatched(UplinkMessageAccepted::class);
    expect(Zone::query()->sole()->layout)->toBeNull();
});

it('shows an unanswered command as expired without waiting for the next command', function (): void {
    $command = UplinkCommand::factory()->create(['expires_at' => now()->addSeconds(60)]);

    $this->travel(61)->seconds();

    expect($command->fresh()->status)->toBe('expired');
});

it('stops resyncing a zone the mod never answers for', function (): void {
    Event::fake([UplinkCommandIssued::class]);
    Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a', 'layout' => null, 'created_at' => now()->subMinutes(5)]);
    $listener = app(ResyncZoneWithoutLayout::class);

    $listener->handle(new ZoneStateStored('colony-1', 'zone-a', 'fast', 1.0));
    $this->travel(61)->seconds();
    $listener->handle(new ZoneStateStored('colony-1', 'zone-a', 'fast', 2.0));

    expect(UplinkCommand::query()->count())->toBe(1);

    $this->travel(10)->minutes();
    $listener->handle(new ZoneStateStored('colony-1', 'zone-a', 'fast', 3.0));

    expect(UplinkCommand::query()->count())->toBe(2);
});

it('sends one resync when two workers store states of the same zone at once', function (): void {
    Event::fake([UplinkCommandIssued::class]);
    Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a', 'layout' => null, 'created_at' => now()->subMinutes(5)]);
    $lock = Illuminate\Support\Facades\Cache::lock('uplink:resync:colony-1:zone-a', 10);
    $lock->get();

    app(ResyncZoneWithoutLayout::class)->handle(new ZoneStateStored('colony-1', 'zone-a', 'fast', 1.0));

    expect(UplinkCommand::query()->count())->toBe(0);
    $lock->release();
});
