<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Models\Colony;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Collection;

/** Finds an active zone of a colony from what the assistant typed: its id or its name. */
final class McpZones
{
    /** Candidates listed when a name is ambiguous. */
    private const int MAX_CANDIDATES = 10;

    /**
     * A numeric value is the zone id; otherwise the case-insensitive exact name wins, then a
     * unique case-insensitive fragment of the name.
     *
     * @throws McpLookupException when nothing or several zones match
     */
    public static function resolve(Colony $colony, string $zone): Zone
    {
        $zones = Zone::query()->active()->where('colony_guid', $colony->guid);
        $needle = mb_strtolower(mb_trim($zone));

        if (ctype_digit($needle)) {
            return (clone $zones)->whereKey((int) $needle)->first()
                ?? throw new McpLookupException(sprintf('No active zone #%s in colony "%s". Call list_zones.', $needle, $colony->name));
        }

        /** @var Collection<int, Zone> $named */
        $named = (clone $zones)->orderBy('name')->get(['id', 'name']);
        $exact = $named->filter(fn (Zone $candidate): bool => mb_strtolower($candidate->name) === $needle);
        $matches = $exact->isNotEmpty() ? $exact : $named->filter(fn (Zone $candidate): bool => str_contains(mb_strtolower($candidate->name), $needle));

        if ($matches->count() === 1) {
            return Zone::query()->findOrFail($matches->first()->id);
        }

        if ($matches->isEmpty()) {
            throw new McpLookupException(sprintf('No active zone matches "%s". Call list_zones.', $zone));
        }

        throw new McpLookupException(sprintf(
            '"%s" matches %d zones, use the id: %s',
            $zone,
            $matches->count(),
            $matches->take(self::MAX_CANDIDATES)->map(fn (Zone $candidate): string => sprintf('#%d %s', $candidate->id, $candidate->name))->implode(', '),
        ));
    }
}
