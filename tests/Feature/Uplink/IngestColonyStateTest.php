<?php

declare(strict_types=1);

use App\Listeners\IngestColonyState;
use App\Models\Colony;
use App\Models\UplinkMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Events\MessageReceived;
use Ratchet\RFC6455\Messaging\Frame;

uses(RefreshDatabase::class);

/** Hands a raw client message to the listener, as Reverb would. */
function receive(string $message): void
{
    $connection = new class extends Connection
    {
        public function __construct() {}

        public function identifier(): string
        {
            return 'test';
        }

        public function id(): string
        {
            return 'test';
        }

        public function send(string $message): void {}

        public function control(string $type = Frame::OP_PING): void {}

        public function terminate(): void {}
    };

    app(IngestColonyState::class)->handle(new MessageReceived($connection, $message));
}

it('ingests a colony state whose event comes first', function (): void {
    receive(uplinkFixture());

    expect(Colony::query()->sole()->guid)->toBe('guid-deepwater')
        ->and(UplinkMessage::query()->sole()->outcome)->toBe('stored');
});

it('ignores a colony state whose event is not within the first 200 bytes', function (): void {
    $envelope = json_decode(uplinkFixture(), true);
    $late = json_encode(['padding' => str_repeat('x', 200), ...$envelope]);

    receive($late);

    expect(UplinkMessage::query()->count())->toBe(0)
        ->and(Colony::query()->count())->toBe(0);
});

it('ignores other events', function (): void {
    receive('{"event":"pusher:ping","data":{}}');
    receive('{"event":"client-other.event","channel":"uplink","data":{"schema":1}}');

    expect(UplinkMessage::query()->count())->toBe(0);
});

it('refuses a colony state whose data is not an object', function (string $data): void {
    receive('{"event":"client-colony.state","channel":"uplink","data":'.$data.'}');

    expect(UplinkMessage::query()->sole())->outcome->toBe('refused')->reason->toBe('invalid_json')
        ->and(Colony::query()->count())->toBe(0);
})->with([
    'a list' => ['[1,2]'],
    'a string that is not json' => ['"not json"'],
    'a number' => ['42'],
]);
