<?php

declare(strict_types=1);

namespace App\Events\Uplink;

use Illuminate\Foundation\Events\Dispatchable;

/** A zone layout was stored (not raised for a stale or unknown-zone layout). */
final class ZoneLayoutStored
{
    use Dispatchable;

    public function __construct(
        public readonly string $colonyGuid,
        public readonly string $zoneGuid,
    ) {}
}
