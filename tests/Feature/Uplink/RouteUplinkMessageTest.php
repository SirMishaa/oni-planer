<?php

declare(strict_types=1);

use App\Listeners\RouteUplinkMessage;
use App\Uplink\UplinkMessageType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

function routeFrame(string $frame): void
{
    app(RouteUplinkMessage::class)->handle(reverbMessage($frame));
}

it('queues each message type on its queue', function (UplinkMessageType $type): void {
    routeFrame(uplinkData($type->value, ['schema' => 1], $type->channel()));

    Queue::assertPushedOn($type->queue(), $type->job(), fn ($job): bool => $job->frame === uplinkData($type->value, ['schema' => 1], $type->channel()));
})->with(UplinkMessageType::cases());

it('puts reliable messages on the ordered queue and states on the parallel one', function (): void {
    expect(UplinkMessageType::SessionStart->queue())->toBe('uplink-reliable')
        ->and(UplinkMessageType::ZoneList->queue())->toBe('uplink-reliable')
        ->and(UplinkMessageType::ZoneLayout->queue())->toBe('uplink-reliable')
        ->and(UplinkMessageType::CommandAck->queue())->toBe('uplink-reliable')
        ->and(UplinkMessageType::ZoneSlowState->queue())->toBe('uplink-state')
        ->and(UplinkMessageType::ZoneFastState->queue())->toBe('uplink-state')
        ->and(UplinkMessageType::ColonyState->queue())->toBe('uplink-state');
});

it('does not decode the payload', function (): void {
    routeFrame('{"event":"client-zone.slow","channel":"uplink-data","data":{"payload":"!! not base64 !!"}}');

    Queue::assertPushed(UplinkMessageType::ZoneSlowState->job());
});

it('does not touch the database', function (): void {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    foreach (range(1, 10) as $index) {
        routeFrame(uplinkData('client-zone.fast', ['schema' => 1, 'index' => $index]));
    }

    expect($queries)->toBe(0);
    Queue::assertPushed(UplinkMessageType::ZoneFastState->job(), 10);
});

it('ignores malformed frames without throwing', function (string $frame): void {
    routeFrame($frame);

    Queue::assertNothingPushed();
})->with([
    'empty' => [''],
    'broken json' => ['{'],
    'no channel' => ['{"event":"client-zone.slow"}'],
    'ping' => ['{"event":"pusher:ping","data":{}}'],
    'unknown client event' => ['{"event":"client-foo","channel":"uplink-data","data":{}}'],
    'colony state on the data channel' => ['{"event":"client-colony.state","channel":"uplink-data","data":{}}'],
    'zone state on the colony channel' => ['{"event":"client-zone.slow","channel":"uplink","data":{}}'],
    'json list' => ['[1,2]'],
]);

it('ignores a frame whose event is not within the first 200 bytes', function (): void {
    routeFrame((string) json_encode(['padding' => str_repeat('x', 200), 'event' => 'client-zone.slow', 'channel' => 'uplink-data', 'data' => []]));

    Queue::assertNothingPushed();
});
