<?php

declare(strict_types=1);

use App\Actions\Uplink\SendUplinkCommand;
use App\Events\Uplink\UplinkCommandIssued;
use App\Events\Uplink\UplinkMessageAccepted;
use App\Events\Uplink\ZoneStateStored;
use App\Listeners\Uplink\ResyncZoneWithoutLayout;
use App\Models\UplinkCommand;
use App\Models\Zone;
use Illuminate\Support\Facades\Event;

/** @return array<string, mixed> */
function commandAck(string $commandId, string $status, array $extra = []): array
{
    return ['schema' => 1, 'id' => 'msg-ack-1', 'colonyId' => 'colony-1', 'commandId' => $commandId, 'status' => $status, ...$extra];
}

it('broadcasts a command envelope', function (): void {
    Event::fake([UplinkCommandIssued::class]);

    $command = app(SendUplinkCommand::class)->handle('colony-1', 'zone.resync', ['zoneId' => 'zone-a']);

    Event::assertDispatched(UplinkCommandIssued::class, function (UplinkCommandIssued $event) use ($command): bool {
        $envelope = $event->broadcastWith();

        return array_keys($envelope) === ['schema', 'id', 'colonyId', 'type', 'issuedAt', 'expiresAt', 'payload']
            && $envelope['schema'] === 1
            && $envelope['id'] === $command->id
            && $envelope['colonyId'] === 'colony-1'
            && $envelope['type'] === 'zone.resync'
            && $envelope['payload'] === ['zoneId' => 'zone-a']
            && $envelope['expiresAt'] === now()->addSeconds(60)->toIso8601ZuluString()
            && $event->broadcastAs() === 'uplink.command'
            && $event->broadcastOn()[0]->name === 'uplink-data';
    });
    expect($command->fresh()->status)->toBe('pending');
});

it('records the ack of a command', function (): void {
    $command = UplinkCommand::factory()->create(['colony_guid' => 'colony-1']);
    Event::fake([UplinkMessageAccepted::class]);

    receiveUplinkData(uplinkData('client-command.ack', commandAck($command->id, 'done')));

    expect($command->fresh())->status->toBe('done')->acked_at->not->toBeNull();
    Event::assertDispatched(UplinkMessageAccepted::class, fn (UplinkMessageAccepted $e): bool => $e->id === 'msg-ack-1');
});

it('records a refusal with its reason', function (): void {
    $command = UplinkCommand::factory()->create(['colony_guid' => 'colony-1']);

    receiveUplinkData(uplinkData('client-command.ack', commandAck($command->id, 'refused', ['reason' => 'unknown zone'])));

    expect($command->fresh())->status->toBe('refused')->reason->toBe('unknown zone');
});

it('acks an ack for an unknown or already expired command without changing it', function (): void {
    $expired = UplinkCommand::factory()->create(['colony_guid' => 'colony-1', 'status' => 'expired']);
    Event::fake([UplinkMessageAccepted::class]);

    receiveUplinkData(uplinkData('client-command.ack', commandAck($expired->id, 'done')));
    receiveUplinkData(uplinkData('client-command.ack', commandAck('00000000-0000-0000-0000-000000000000', 'done')));

    expect($expired->fresh()->status)->toBe('expired');
    Event::assertDispatchedTimes(UplinkMessageAccepted::class, 2);
});

it('does not ack a malformed command ack', function (): void {
    Event::fake([UplinkMessageAccepted::class]);

    receiveUplinkData(uplinkData('client-command.ack', commandAck('x', 'maybe')));

    Event::assertNotDispatched(UplinkMessageAccepted::class);
});

it('expires unanswered commands', function (): void {
    $command = UplinkCommand::factory()->create(['colony_guid' => 'colony-1', 'expires_at' => now()->addSeconds(60)]);

    $this->travel(61)->seconds();
    UplinkCommand::expireStale();
    receiveUplinkData(uplinkData('client-command.ack', commandAck($command->id, 'done')));

    expect($command->fresh()->status)->toBe('expired');
});

it('asks for a resync when a zone still has no layout 30 s after creation', function (): void {
    Event::fake([UplinkCommandIssued::class]);
    Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a', 'layout' => null, 'created_at' => now()->subSeconds(31)]);

    app(ResyncZoneWithoutLayout::class)->handle(new ZoneStateStored('colony-1', 'zone-a', 'fast', 10.0));

    expect(UplinkCommand::query()->sole())->type->toBe('zone.resync')->payload->toBe(['zoneId' => 'zone-a']);
});

it('does not resync a zone created less than 30 s ago or with a layout', function (): void {
    Event::fake([UplinkCommandIssued::class]);
    Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a', 'layout' => null, 'created_at' => now()->subSeconds(10)]);
    Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-b', 'layout' => ['cells' => [1]], 'created_at' => now()->subMinutes(5)]);

    app(ResyncZoneWithoutLayout::class)->handle(new ZoneStateStored('colony-1', 'zone-a', 'fast', 10.0));
    app(ResyncZoneWithoutLayout::class)->handle(new ZoneStateStored('colony-1', 'zone-b', 'fast', 10.0));

    expect(UplinkCommand::query()->count())->toBe(0);
});

it('does not send a second resync while one is pending', function (): void {
    Event::fake([UplinkCommandIssued::class]);
    Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a', 'layout' => null, 'created_at' => now()->subMinutes(1)]);
    $listener = app(ResyncZoneWithoutLayout::class);

    $listener->handle(new ZoneStateStored('colony-1', 'zone-a', 'fast', 10.0));
    $listener->handle(new ZoneStateStored('colony-1', 'zone-a', 'slow', 11.0));
    expect(UplinkCommand::query()->count())->toBe(1);

    $this->travel(61)->seconds();
    $listener->handle(new ZoneStateStored('colony-1', 'zone-a', 'fast', 12.0));
    expect(UplinkCommand::query()->count())->toBe(2);
});

it('resyncs a zone by hand', function (): void {
    Event::fake([UplinkCommandIssued::class]);
    $zone = Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a']);

    $this->artisan('uplink:resync-zone', ['zone' => (string) $zone->id])->assertSuccessful();
    $this->artisan('uplink:resync-zone', ['zone' => 'zone-a'])->assertSuccessful();
    $this->artisan('uplink:resync-zone', ['zone' => 'nope'])->assertFailed();

    expect(UplinkCommand::query()->where('type', 'zone.resync')->count())->toBe(2);
});
