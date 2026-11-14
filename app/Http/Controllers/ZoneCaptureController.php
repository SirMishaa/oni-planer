<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Uplink\SendUplinkCommand;
use App\Models\Zone;
use Illuminate\Http\RedirectResponse;

/** Asks the game, through the Uplink mod, for a picture of a zone. */
final readonly class ZoneCaptureController
{
    public function store(Zone $zone, SendUplinkCommand $sendCommand): RedirectResponse
    {
        abort_if($zone->archived_at !== null, 404);

        $sendCommand->handle($zone->colony_guid, 'zone.capture', ['zoneId' => $zone->zone_guid]);

        return back();
    }
}
