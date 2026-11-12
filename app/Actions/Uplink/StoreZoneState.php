<?php

declare(strict_types=1);

namespace App\Actions\Uplink;

use App\Events\ZoneUpdated;
use App\Models\Zone;
use Illuminate\Support\Facades\Cache;

/**
 * Stores the latest slow or fast state of a zone. A state for an unknown zone
 * is ignored. Fast states broadcast at most once per zone every 2 seconds.
 */
final class StoreZoneState
{
    private const int FAST_BROADCAST_SECONDS = 2;

    /**
     * @param  'slow'|'fast'  $kind
     * @param  array{colonyId: string, zoneId: string}  $message
     */
    public function handle(string $kind, array $message): void
    {
        $updated = Zone::query()
            ->where('colony_guid', $message['colonyId'])
            ->where('zone_guid', $message['zoneId'])
            ->first();

        if ($updated === null) {
            return;
        }

        $updated->update(["{$kind}_state" => $message, "{$kind}_state_at" => now()]);

        if ($kind === 'fast' && ! Cache::lock("zone-updated:{$message['zoneId']}", self::FAST_BROADCAST_SECONDS)->get()) {
            return;
        }

        ZoneUpdated::dispatch($message['zoneId'], $message['colonyId'], $kind);
    }
}
