<?php

declare(strict_types=1);

namespace App\Actions\Uplink;

use App\Events\Uplink\ZoneStateStored;
use App\Models\Zone;

/**
 * Stores the latest slow or fast state of a zone. A state for an unknown zone
 * is ignored.
 */
final class StoreZoneState
{
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

        $attributes = ["{$kind}_state" => $message, "{$kind}_state_at" => now()];

        if ($kind === 'slow' && $updated->slow_state_at !== null) {
            $attributes['previous_slow_temperature'] = [
                'box' => $updated->slow_state['box'] ?? null,
                'temperature' => $updated->slow_state['temperature'] ?? null,
                'at' => $updated->slow_state_at->toIso8601String(),
            ];
        }

        $updated->update($attributes);

        ZoneStateStored::dispatch($message['colonyId'], $message['zoneId'], $kind, (float) ($message['gameSeconds'] ?? 0));
    }
}
