<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Actions\Uplink\StartUplinkSession;
use App\Enums\UplinkMessageType;
use App\Services\Uplink\UplinkMessageReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** `client-session.start`: records the session the mod just started; acked once stored. */
final class ReceiveSessionStart implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $frame) {}

    public function handle(UplinkMessageReader $reader, StartUplinkSession $startSession): void
    {
        $message = $reader->read($this->frame, UplinkMessageType::SessionStart);

        if ($message === null || ! is_string($message['id'] ?? null) || ! is_string($message['colonyId'] ?? null) || ! is_string($message['sessionId'] ?? null)) {
            return;
        }

        $startSession->handle($message);
        $reader->acknowledge($message);
    }
}
