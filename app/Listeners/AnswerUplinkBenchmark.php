<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Uplink\UplinkPayload;
use Laravel\Reverb\Events\MessageReceived;
use Throwable;

/**
 * Server side of `php artisan uplink:bench`. Runs inside the Reverb process
 * for every client message; only `client-bench.ping` messages are measured.
 * It decodes the message the way a real ingestion would, then answers the
 * sender directly with a `bench.ack` carrying the correlation id and the
 * server-side measures (timestamps in milliseconds since the Unix epoch).
 */
final class AnswerUplinkBenchmark
{
    public const string PING_EVENT = 'client-bench.ping';

    public const string ACK_EVENT = 'bench.ack';

    public function handle(MessageReceived $event): void
    {
        $listenerStartedAt = microtime(true) * 1000;

        if (! str_contains(mb_substr($event->message, 0, 200, '8bit'), self::PING_EVENT)) {
            return;
        }

        try {
            $decodeStartedAt = hrtime(true);
            $envelope = json_decode($event->message, true, flags: JSON_THROW_ON_ERROR);
            $data = UplinkPayload::decode($envelope['data'] ?? []);
            $decodeMs = (hrtime(true) - $decodeStartedAt) / 1e6;

            $event->connection->send((string) json_encode([
                'event' => self::ACK_EVENT,
                'channel' => $envelope['channel'] ?? null,
                'data' => json_encode([
                    'id' => $data['id'] ?? null,
                    'bytes' => mb_strlen($event->message, '8bit'),
                    'listenerStartedAt' => $listenerStartedAt,
                    'decodeMs' => $decodeMs,
                    'peakMemoryMb' => memory_get_peak_usage(true) / 1_048_576,
                ]),
            ]));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
