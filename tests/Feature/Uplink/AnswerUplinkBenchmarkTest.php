<?php

declare(strict_types=1);

use App\Listeners\AnswerUplinkBenchmark;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Events\MessageReceived;
use Ratchet\RFC6455\Messaging\Frame;

/**
 * Hands a raw client message to the benchmark listener and returns what it
 * sent back to the connection.
 *
 * @return list<string>
 */
function benchAnswers(string $message): array
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

    app(AnswerUplinkBenchmark::class)->handle(new MessageReceived($connection, $message));

    return $connection->sent;
}

/** @param  array<string, mixed>|string  $data */
function benchPing(array|string $data): string
{
    return (string) json_encode(['event' => AnswerUplinkBenchmark::PING_EVENT, 'channel' => 'uplink-bench', 'data' => $data]);
}

/** @return array<string, mixed> */
function benchAck(string $frame): array
{
    $ack = json_decode($frame, true, flags: JSON_THROW_ON_ERROR);
    expect($ack['event'])->toBe(AnswerUplinkBenchmark::ACK_EVENT)->and($ack['channel'])->toBe('uplink-bench');

    return json_decode($ack['data'], true, flags: JSON_THROW_ON_ERROR);
}

it('acknowledges a ping sent as JSON text with its correlation id and measures', function (): void {
    $message = benchPing((string) json_encode(['id' => 'abc', 'cells' => [1, 2]]));

    $answers = benchAnswers($message);

    expect($answers)->toHaveCount(1);
    $ack = benchAck($answers[0]);
    expect($ack['id'])->toBe('abc')
        ->and($ack['bytes'])->toBe(mb_strlen($message, '8bit'))
        ->and($ack['decodeMs'])->toBeFloat()
        ->and($ack['peakMemoryMb'])->toBeGreaterThan(0);
});

it('unwraps a payload sent as base64 or base64 of gzip', function (string $encoded): void {
    $ack = benchAck(benchAnswers(benchPing(['payload' => $encoded]))[0]);

    expect($ack['id'])->toBe('xyz');
})->with([
    'base64' => base64_encode('{"id":"xyz"}'),
    'base64 of gzip' => base64_encode((string) gzencode('{"id":"xyz"}')),
]);

it('ignores messages that are not benchmark pings', function (): void {
    expect(benchAnswers('{"event":"client-colony.state","channel":"uplink","data":{}}'))->toBe([]);
});
