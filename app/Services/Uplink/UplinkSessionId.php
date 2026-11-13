<?php

declare(strict_types=1);

namespace App\Services\Uplink;

/** The mod's session ids are 32 hex characters; anything that is not a short identifier is refused. */
final class UplinkSessionId
{
    public static function isValid(mixed $sessionId): bool
    {
        return is_string($sessionId) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sessionId) === 1;
    }
}
