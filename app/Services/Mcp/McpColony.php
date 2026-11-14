<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Models\Colony;

/**
 * The colony an MCP call is about: the one named by its guid, or the colony the game
 * spoke to last. Read on every call; the stdio process is long-lived and colony
 * state changes every few seconds.
 */
final class McpColony
{
    public const string NOT_SYNCED = 'No colony synced yet: start the game with the Uplink mod linked.';

    public static function unknown(string $guid): string
    {
        return sprintf('No colony with guid "%s": call colony_status without colony for the current one.', $guid);
    }

    public static function resolve(?string $guid): ?Colony
    {
        $query = Colony::query();

        if ($guid !== null && $guid !== '') {
            return $query->where('guid', $guid)->first();
        }

        return $query->latest('last_seen_at')->first();
    }
}
