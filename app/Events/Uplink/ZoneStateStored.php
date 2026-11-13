<?php

declare(strict_types=1);

namespace App\Events\Uplink;

use Illuminate\Foundation\Events\Dispatchable;

/** A slow or fast zone state was written (not raised for a dropped one). */
final class ZoneStateStored
{
    use Dispatchable;

    /**
     * @param  'slow'|'fast'  $kind
     */
    public function __construct(
        public readonly string $colonyGuid,
        public readonly string $zoneGuid,
        public readonly string $kind,
        public readonly float $gameSeconds,
    ) {}
}
