<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Models\Colony;
use App\Models\Zone;
use Carbon\CarbonInterface;

/**
 * The first line of every MCP response: which colony, which cycle, and how old the
 * data is, so advice is never given on a state that stopped updating.
 */
final class McpHeader
{
    /** Beyond this many seconds without a message, the colony is flagged STALE. */
    public const int STALE_SECONDS = 60;

    /** Beyond this many seconds without a message, the game is said to look offline (the colony state repeats every ~15 s). */
    public const int OFFLINE_SECONDS = 30;

    public static function line(Colony $colony, ?Zone $zone = null): string
    {
        $line = sprintf('Colony "%s" cycle %d · seen %s', $colony->name, $colony->cycle, self::ago($colony->last_seen_at));

        if ($zone instanceof Zone) {
            $line .= sprintf(
                ' · zone fast %s, slow %s, layout %s',
                self::age($zone->fast_state_at),
                self::age($zone->slow_state_at),
                self::age($zone->layout_at),
            );
        }

        if ($colony->last_seen_at === null || $colony->last_seen_at->diffInSeconds(now(), true) > self::STALE_SECONDS) {
            $line .= ' · STALE';
        }

        return $line;
    }

    /** "4 s ago", "3 min ago", "never". */
    public static function ago(?CarbonInterface $at): string
    {
        return $at === null ? 'never' : self::age($at).' ago';
    }

    /** "4 s", "3 min", "2 h", "5 d", "never". */
    public static function age(?CarbonInterface $at): string
    {
        if ($at === null) {
            return 'never';
        }

        $seconds = (int) $at->diffInSeconds(now(), true);

        return match (true) {
            $seconds < 120 => $seconds.' s',
            $seconds < 7_200 => intdiv($seconds, 60).' min',
            $seconds < 172_800 => intdiv($seconds, 3_600).' h',
            default => intdiv($seconds, 86_400).' d',
        };
    }
}
