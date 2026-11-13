<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Actions\Uplink\StartUplinkSession;
use App\Uplink\UplinkMessageType;

/** `client-session.start`: records the session the mod just started. */
final class ReceiveSessionStart extends UplinkMessageJob
{
    public static function type(): UplinkMessageType
    {
        return UplinkMessageType::SessionStart;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function process(array $message): bool
    {
        if (! is_string($message['id'] ?? null) || ! is_string($message['colonyId'] ?? null) || ! is_string($message['sessionId'] ?? null)) {
            return false;
        }

        app(StartUplinkSession::class)->handle($message);

        return true;
    }
}
