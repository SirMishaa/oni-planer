<?php

declare(strict_types=1);

namespace App\Actions\Uplink;

use App\Events\Uplink\ZoneStateStored;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;

/**
 * Stores the latest slow or fast state of a zone, in one conditional
 * statement so concurrent workers never let a late state win:
 *
 * - a state of another session than the colony's current one (the latest
 *   session start) is dropped: loading an older save makes game time go
 *   back, and its states must never mix with the new session's;
 * - a state of the current session replaces a state stored by another
 *   session whatever its game time, and within a session only a newer
 *   `gameSeconds` replaces the stored one;
 * - a state without `sessionId` (mod older than the field) counts as the
 *   current session's.
 *
 * `previous_slow_temperature` keeps the replaced slow temperatures only
 * when both states belong to the same session. A state for an unknown zone
 * is ignored.
 */
final class StoreZoneState
{
    /**
     * @param  'slow'|'fast'  $kind
     * @param  array{colonyId: string, zoneId: string, gameSeconds?: int|float, sessionId?: string}  $message
     * @return bool whether the state was written
     */
    public function handle(string $kind, array $message): bool
    {
        $sessionId = is_string($message['sessionId'] ?? null) ? $message['sessionId'] : null;
        $gameSeconds = (float) ($message['gameSeconds'] ?? 0);
        $current = '(select session_id from uplink_sessions where uplink_sessions.colony_guid = zones.colony_guid order by started_at desc, id desc limit 1)';
        $own = $sessionId === null ? $current : DB::getPdo()->quote($sessionId).'::varchar';

        $attributes = [
            "{$kind}_state" => json_encode($message, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            "{$kind}_state_at" => now(),
            "{$kind}_session_id" => DB::raw($own),
            "{$kind}_game_seconds" => $gameSeconds,
        ];

        if ($kind === 'slow') {
            $attributes['previous_slow_temperature'] = DB::raw(
                "case when slow_state_at is not null and slow_session_id is not distinct from {$own}"
                ." then json_build_object('box', slow_state->'box', 'temperature', slow_state->'temperature', 'at', to_char(slow_state_at, 'YYYY-MM-DD\"T\"HH24:MI:SS\"+00:00\"'))"
                .' else null end'
            );
        }

        $written = Zone::query()
            ->where('colony_guid', $message['colonyId'])
            ->where('zone_guid', $message['zoneId'])
            ->when($sessionId !== null, fn ($query) => $query->whereRaw("({$current} is null or {$current} = ?)", [$sessionId]))
            ->where(fn ($query) => $query
                ->whereRaw("{$kind}_session_id is distinct from {$own}")
                ->orWhereNull("{$kind}_game_seconds")
                ->orWhere("{$kind}_game_seconds", '<', $gameSeconds))
            ->update($attributes);

        if ($written === 0) {
            return false;
        }

        ZoneStateStored::dispatch($message['colonyId'], $message['zoneId'], $kind, $gameSeconds);

        return true;
    }
}
