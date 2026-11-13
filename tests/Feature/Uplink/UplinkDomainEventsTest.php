<?php

declare(strict_types=1);

use App\Events\Uplink\UplinkMessageAccepted;
use App\Events\Uplink\ZoneLayoutStored;
use App\Events\Uplink\ZoneListSynced;
use App\Events\Uplink\ZoneStateStored;
use App\Events\ZoneUpdated;
use App\Models\Zone;
use Illuminate\Support\Facades\Event;

it('raises a domain event for a stored zone list', function (): void {
    Event::fake([ZoneListSynced::class, ZoneLayoutStored::class, ZoneStateStored::class]);

    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')])));
    Event::assertDispatched(ZoneListSynced::class, fn (ZoneListSynced $e): bool => $e->colonyGuid === 'colony-1' && $e->zoneGuids === ['zone-a']);
});

it('raises zone layout and state events after the write', function (): void {
    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')])));
    Event::fake([ZoneLayoutStored::class, ZoneStateStored::class]);

    receiveUplinkData(uplinkData('client-zone.layout', zoneLayoutMessage([10, 11, 12])));
    receiveUplinkData(uplinkData('client-zone.slow', ['schema' => 1, 'colonyId' => 'colony-1', 'zoneId' => 'zone-a', 'gameSeconds' => 50.5]));

    Event::assertDispatched(ZoneLayoutStored::class, fn (ZoneLayoutStored $e): bool => $e->colonyGuid === 'colony-1' && $e->zoneGuid === 'zone-a');
    Event::assertDispatched(ZoneStateStored::class, fn (ZoneStateStored $e): bool => $e->zoneGuid === 'zone-a' && $e->kind === 'slow' && $e->gameSeconds === 50.5);
});

it('does not ack a layout for an unknown zone', function (): void {
    Event::fake([UplinkMessageAccepted::class, ZoneLayoutStored::class]);

    receiveUplinkData(uplinkData('client-zone.layout', zoneLayoutMessage([10, 11, 12])));

    Event::assertNotDispatched(UplinkMessageAccepted::class);
    Event::assertNotDispatched(ZoneLayoutStored::class);
    expect(Zone::query()->count())->toBe(0);
});

it('never acks states', function (): void {
    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')])));
    Event::fake([UplinkMessageAccepted::class]);

    receiveUplinkData(uplinkData('client-zone.slow', ['schema' => 1, 'colonyId' => 'colony-1', 'zoneId' => 'zone-a', 'gameSeconds' => 1.0]));
    receiveUplinkData(uplinkData('client-zone.fast', ['schema' => 1, 'colonyId' => 'colony-1', 'zoneId' => 'zone-a', 'gameSeconds' => 1.0]));

    Event::assertNotDispatched(UplinkMessageAccepted::class);
});

it('broadcasts zone updates from domain events', function (): void {
    Event::fake([ZoneUpdated::class]);

    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')])));
    receiveUplinkData(uplinkData('client-zone.layout', zoneLayoutMessage([10, 11, 12])));
    receiveUplinkData(uplinkData('client-zone.slow', ['schema' => 1, 'colonyId' => 'colony-1', 'zoneId' => 'zone-a', 'gameSeconds' => 1.0]));

    foreach (['list', 'layout', 'slow'] as $kind) {
        Event::assertDispatched(ZoneUpdated::class, fn (ZoneUpdated $e): bool => $e->zoneGuid === 'zone-a' && $e->kind === $kind);
    }
});

it('throttles fast zone updates to one per 2 seconds', function (): void {
    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')])));
    Event::fake([ZoneUpdated::class]);
    $fast = fn (float $seconds): array => receiveUplinkData(uplinkData('client-zone.fast', ['schema' => 1, 'colonyId' => 'colony-1', 'zoneId' => 'zone-a', 'gameSeconds' => $seconds]));

    $fast(1.0);
    $this->travel(1)->seconds();
    $fast(2.0);
    Event::assertDispatchedTimes(ZoneUpdated::class, 1);

    $this->travel(3)->seconds();
    $fast(3.0);
    Event::assertDispatchedTimes(ZoneUpdated::class, 2);
});
