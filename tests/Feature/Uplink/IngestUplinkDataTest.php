<?php

declare(strict_types=1);

use App\Events\ZoneUpdated;
use App\Listeners\IngestUplinkData;
use App\Models\Colony;
use App\Models\UplinkSession;
use App\Models\Zone;
use App\Models\ZoneVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Events\MessageReceived;
use Ratchet\RFC6455\Messaging\Frame;

uses(RefreshDatabase::class);

/** Builds the raw Pusher frame the mod sends: base64(gzip(json)) under `payload`. */
function uplinkData(string $event, array $inner, string $channel = 'uplink-data'): string
{
    return (string) json_encode([
        'event' => $event,
        'channel' => $channel,
        'data' => ['payload' => base64_encode((string) gzencode((string) json_encode($inner)))],
    ]);
}

/**
 * Hands a raw client message to the listener and returns the frames sent back.
 *
 * @return list<string>
 */
function receiveUplinkData(string $message): array
{
    $connection = new class extends Connection
    {
        /** @var list<string> */
        public array $sent = [];

        public function __construct() {}

        public function identifier(): string
        {
            return 'test';
        }

        public function id(): string
        {
            return 'test';
        }

        public function send(string $message): void
        {
            $this->sent[] = $message;
        }

        public function control(string $type = Frame::OP_PING): void {}

        public function terminate(): void {}
    };

    app(IngestUplinkData::class)->handle(new MessageReceived($connection, $message));

    return $connection->sent;
}

/** @return array<string, mixed> */
function zoneListMessage(array $zones, string $id = 'msg-list-1', string $colonyId = 'colony-1'): array
{
    return [
        'schema' => 1,
        'id' => $id,
        'colonyId' => $colonyId,
        'sessionId' => 'session-1',
        'zones' => $zones,
    ];
}

/** @return array<string, mixed> */
function zoneEntry(string $zoneId, array $override = []): array
{
    return [
        'zoneId' => $zoneId,
        'name' => 'Ferme',
        'worldId' => 0,
        'version' => 1,
        'cellCount' => 3,
        'orphaned' => false,
        ...$override,
    ];
}

/** @return array<string, mixed> */
function zoneLayoutMessage(array $cells, string $id = 'msg-layout-1', int $version = 1): array
{
    return [
        'schema' => 1,
        'id' => $id,
        'colonyId' => 'colony-1',
        'zoneId' => 'zone-a',
        'version' => $version,
        'gameSeconds' => 1200.5,
        'gridWidth' => 606,
        'box' => ['x' => 1, 'y' => 2, 'width' => 3, 'height' => 1],
        'cells' => $cells,
        'tiles' => [['cell' => $cells[0], 'prefabId' => 'Tile']],
        'buildings' => [],
    ];
}

function ackFrame(string $id): string
{
    return (string) json_encode([
        'event' => 'uplink.ack',
        'channel' => 'uplink-data',
        'data' => json_encode(['id' => $id]),
    ]);
}

it('acks a zone list after storing it', function (): void {
    $sent = receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')])));

    expect($sent)->toBe([ackFrame('msg-list-1')])
        ->and(Zone::query()->sole()->zone_guid)->toBe('zone-a');
});

it('stores a session start and acks it', function (): void {
    $sent = receiveUplinkData(uplinkData('client-session.start', [
        'schema' => 1, 'id' => 'msg-s', 'colonyId' => 'colony-1', 'sessionId' => 'session-1',
        'loadedGameSeconds' => 3000.0, 'cycle' => 5,
    ]));

    expect($sent)->toBe([ackFrame('msg-s')])
        ->and(UplinkSession::query()->sole()->cycle)->toBe(5);
});

it('is idempotent on the same message twice', function (): void {
    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')])));
    $layout = uplinkData('client-zone.layout', zoneLayoutMessage([10, 11, 12]));

    $first = receiveUplinkData($layout);
    $second = receiveUplinkData($layout);

    expect($first)->toBe([ackFrame('msg-layout-1')])
        ->and($second)->toBe([ackFrame('msg-layout-1')])
        ->and(ZoneVersion::query()->count())->toBe(1);
});

it('opens a new version only when cells change', function (): void {
    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')])));
    receiveUplinkData(uplinkData('client-zone.layout', zoneLayoutMessage([10, 11, 12])));
    receiveUplinkData(uplinkData('client-zone.layout', zoneLayoutMessage([10, 11, 12], 'msg-layout-2')));
    receiveUplinkData(uplinkData('client-zone.layout', zoneLayoutMessage([10, 11, 12, 13], 'msg-layout-3', 2)));

    expect(ZoneVersion::query()->orderBy('version')->pluck('cells', 'version')->all())
        ->toBe([1 => [10, 11, 12], 2 => [10, 11, 12, 13]])
        ->and(Zone::query()->sole()->version)->toBe(2);
});

it('archives zones missing from the list and restores them when they return', function (): void {
    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a'), zoneEntry('zone-b')])));
    $other = Zone::factory()->create(['colony_guid' => 'colony-2', 'zone_guid' => 'zone-x']);

    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')], 'msg-list-2')));

    expect(Zone::query()->where('zone_guid', 'zone-b')->sole()->archived_at)->not->toBeNull()
        ->and(Zone::query()->where('zone_guid', 'zone-a')->sole()->archived_at)->toBeNull()
        ->and($other->fresh()->archived_at)->toBeNull();

    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a'), zoneEntry('zone-b')], 'msg-list-3')));

    expect(Zone::query()->where('zone_guid', 'zone-b')->sole()->archived_at)->toBeNull();
});

it('stores the orphaned flag', function (): void {
    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a', ['orphaned' => true])])));

    expect(Zone::query()->sole()->orphaned)->toBeTrue();
});

it('does not ack and stores nothing for a newer schema', function (): void {
    $sent = receiveUplinkData(uplinkData('client-zone.list', [...zoneListMessage([zoneEntry('zone-a')]), 'schema' => 2]));

    expect($sent)->toBe([])->and(Zone::query()->count())->toBe(0);
});

it('ignores frames on other channels and the colony state', function (): void {
    $other = receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')]), 'uplink'));
    $state = receiveUplinkData('{"event":"client-colony.state","channel":"uplink","data":{"schema":1}}');

    expect($other)->toBe([])->and($state)->toBe([])->and(Zone::query()->count())->toBe(0);
});

it('never throws on garbage and does not ack it', function (): void {
    $sent = receiveUplinkData('{"event":"client-zone.list","channel":"uplink-data","data":{"payload":"!!!"}}');

    expect($sent)->toBe([]);
});

it('stores latest-only states without acking and ignores unknown zones', function (): void {
    Event::fake([ZoneUpdated::class]);
    Colony::factory()->create(['guid' => 'colony-1']);
    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')])));

    $sent = receiveUplinkData(uplinkData('client-zone.slow', [
        'schema' => 1, 'colonyId' => 'colony-1', 'zoneId' => 'zone-a', 'gameSeconds' => 5.0, 'palette' => [1],
    ]));
    receiveUplinkData(uplinkData('client-zone.fast', [
        'schema' => 1, 'colonyId' => 'colony-1', 'zoneId' => 'unknown', 'gameSeconds' => 5.0, 'plants' => [],
    ]));

    $zone = Zone::query()->sole();
    expect($sent)->toBe([])
        ->and($zone->slow_state['palette'])->toBe([1])
        ->and($zone->slow_state_at)->not->toBeNull()
        ->and($zone->fast_state)->toBeNull();
});

it('broadcasts zone.updated on the uplink channel', function (): void {
    Event::fake([ZoneUpdated::class]);

    receiveUplinkData(uplinkData('client-zone.list', zoneListMessage([zoneEntry('zone-a')])));
    receiveUplinkData(uplinkData('client-zone.layout', zoneLayoutMessage([10, 11, 12])));

    Event::assertDispatched(ZoneUpdated::class, function (ZoneUpdated $event): bool {
        return $event->zoneGuid === 'zone-a' && $event->colonyGuid === 'colony-1' && $event->kind === 'layout'
            && $event->broadcastAs() === 'zone.updated'
            && $event->broadcastOn()[0]->name === 'uplink'
            && $event->broadcastWith() === ['zoneGuid' => 'zone-a', 'colonyGuid' => 'colony-1', 'kind' => 'layout'];
    });
});
