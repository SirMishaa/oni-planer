<?php

declare(strict_types=1);

namespace App\Actions\Uplink;

use App\Models\UplinkSession;

/**
 * Records the start of a game session (a save load). Idempotent on `sessionId`.
 */
final class StartUplinkSession
{
    /**
     * @param  array{colonyId: string, sessionId: string, loadedGameSeconds: int|float, cycle: int}  $message
     */
    public function handle(array $message): void
    {
        UplinkSession::query()->firstOrCreate(
            ['session_id' => $message['sessionId']],
            [
                'colony_guid' => $message['colonyId'],
                'loaded_game_seconds' => (float) $message['loadedGameSeconds'],
                'cycle' => (int) $message['cycle'],
                'started_at' => now(),
            ],
        );
    }
}
