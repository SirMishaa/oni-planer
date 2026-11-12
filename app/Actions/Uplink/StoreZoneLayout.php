<?php

declare(strict_types=1);

namespace App\Actions\Uplink;

use App\Events\ZoneUpdated;
use App\Models\Zone;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Stores the layout of a zone. A `zone_versions` row is added only when the
 * cell set differs from the latest version, so resends create no history.
 */
final class StoreZoneLayout
{
    /**
     * @param  array{colonyId: string, zoneId: string, version: int, gameSeconds: int|float, cells: list<int>}  $message
     * @return bool false when the zone is unknown (the list arrives first), true once stored or when stale (older version, kept as is)
     */
    public function handle(array $message): bool
    {
        $stored = DB::transaction(function () use ($message): ?bool {
            $zone = Zone::query()
                ->where('colony_guid', $message['colonyId'])
                ->where('zone_guid', $message['zoneId'])
                ->lockForUpdate()
                ->first();

            if ($zone === null) {
                return false;
            }

            $version = (int) $message['version'];

            if ($version < $zone->version) {
                return null;
            }

            $cells = array_map('intval', $message['cells']);
            sort($cells);
            $message['cells'] = $cells;

            $existing = $zone->versions()->where('version', $version)->first();

            if ($existing !== null) {
                if ($existing->cells !== $cells) {
                    $existing->update(['cells' => $cells, 'valid_from_game_seconds' => (float) $message['gameSeconds']]);
                }
            } elseif ($zone->versions()->orderByDesc('version')->first()?->cells !== $cells) {
                $zone->versions()->create([
                    'version' => $version,
                    'cells' => $cells,
                    'valid_from_game_seconds' => (float) $message['gameSeconds'],
                ]);
            }

            $zone->update([
                'layout' => Arr::except($message, ['id']),
                'layout_at' => now(),
                'version' => (int) $message['version'],
                'cell_count' => count($cells),
            ]);

            return true;
        });

        if ($stored === true) {
            ZoneUpdated::dispatch($message['zoneId'], $message['colonyId'], 'layout');
        }

        return $stored !== false;
    }
}
