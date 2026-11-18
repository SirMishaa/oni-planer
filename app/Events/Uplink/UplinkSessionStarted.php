<?php

declare(strict_types=1);

namespace App\Events\Uplink;

use Illuminate\Foundation\Events\Dispatchable;

/** A game session of a colony was recorded for the first time. */
final class UplinkSessionStarted
{
    use Dispatchable;

    public function __construct(
        public readonly string $colonyGuid,
        public readonly string $sessionId,
    ) {}
}
