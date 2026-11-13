<?php

declare(strict_types=1);

namespace App\Events\Uplink;

use Illuminate\Foundation\Events\Dispatchable;

/** The zone list of a colony was stored: zones created, updated, archived. */
final class ZoneListSynced
{
    use Dispatchable;

    /**
     * @param  list<string>  $zoneGuids  zones listed and zones archived by this list
     */
    public function __construct(
        public readonly string $colonyGuid,
        public readonly array $zoneGuids,
    ) {}
}
