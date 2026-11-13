<?php

declare(strict_types=1);

namespace App\Services\Uplink;

use App\Models\Colony;
use App\Models\UplinkMessage;
use App\Models\UplinkSession;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;

/**
 * Turns a raw Pusher message received by Reverb into the colony's last state
 * and a journal entry. Messages that are not a colony state are ignored;
 * colony states that cannot be used (including those sent on another channel
 * than Uplink's) are journaled as refused with a reason code. Only the most
 * recently seen colonies are kept, plus any colony that owns zones.
 */
final class ColonyIngestor
{
    /** Number of journaled messages kept. */
    public const int KEEP = 50;

    /** Number of colonies kept, by most recently seen. */
    public const int KEEP_COLONIES = 10;

    /** Highest state schema this site understands. */
    public const int SCHEMA = 1;

    public const string EVENT = 'client-colony.state';

    public const string CHANNEL = 'uplink';

    /** Bytes of the raw message kept in the journal. */
    private const int PAYLOAD_LIMIT = 2_000_000;

    public function ingest(string $rawMessage): ?UplinkMessage
    {
        $envelope = json_decode($rawMessage, true);

        if (! is_array($envelope)) {
            return $this->looksLikeColonyState($rawMessage)
                ? $this->journal($rawMessage, ['event' => null, 'outcome' => 'refused', 'reason' => 'invalid_json'])
                : null;
        }

        if (($envelope['event'] ?? null) !== self::EVENT) {
            return null;
        }

        if (($envelope['channel'] ?? null) !== self::CHANNEL) {
            return $this->journal($rawMessage, ['outcome' => 'refused', 'reason' => 'wrong_channel']);
        }

        $data = $envelope['data'] ?? null;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            return $this->journal($rawMessage, ['outcome' => 'refused', 'reason' => 'invalid_json']);
        }

        $colony = is_array($data['colony'] ?? null) ? $data['colony'] : [];
        $guid = is_string($colony['id'] ?? null) && $colony['id'] !== '' ? $colony['id'] : null;
        $name = is_string($colony['name'] ?? null) ? $colony['name'] : null;
        $cycle = is_int($colony['cycle'] ?? null) || is_float($colony['cycle'] ?? null) ? (int) $colony['cycle'] : null;
        $schema = $data['schema'] ?? null;

        $identity = ['colony_guid' => $guid, 'colony_name' => $name, 'cycle' => $cycle];

        $reason = match (true) {
            ! is_int($schema) || $schema < 1 => 'schema_missing',
            $schema > self::SCHEMA => 'schema_newer',
            $guid === null => 'colony_missing',
            default => null,
        };

        if ($reason !== null) {
            return $this->journal($rawMessage, [...$identity, 'outcome' => 'refused', 'reason' => $reason]);
        }

        $sessionId = is_string($data['sessionId'] ?? null) ? $data['sessionId'] : null;
        $gameSeconds = is_int($data['gameSeconds'] ?? null) || is_float($data['gameSeconds'] ?? null) ? (float) $data['gameSeconds'] : null;

        return DB::transaction(function () use ($rawMessage, $identity, $guid, $name, $cycle, $schema, $data, $sessionId, $gameSeconds): UplinkMessage {
            $colony = Colony::query()->where('guid', $guid)->lockForUpdate()->first();

            if ($sessionId !== null && $gameSeconds !== null && $this->isStale($guid, $colony, $sessionId, $gameSeconds)) {
                return $this->journal($rawMessage, [...$identity, 'outcome' => 'refused', 'reason' => 'stale']);
            }

            Colony::query()->updateOrCreate(['guid' => $guid], [
                'name' => $name ?? '',
                'cycle' => $cycle ?? 0,
                'state' => $data,
                'schema' => $schema,
                'session_id' => $sessionId,
                'game_seconds' => $gameSeconds,
                'last_seen_at' => now(),
            ]);

            $keptIds = Colony::query()->latest('last_seen_at')->latest('id')->limit(self::KEEP_COLONIES)->pluck('id');
            Colony::query()
                ->whereNotIn('id', $keptIds)
                ->whereNotIn('guid', Zone::query()->select('colony_guid'))
                ->delete();

            return $this->journal($rawMessage, [
                ...$identity,
                'outcome' => 'stored',
                'section_errors' => $this->sectionErrors($data),
            ]);
        });
    }

    /** Whether an unparsable message names the colony-state event near its start. */
    /**
     * A state of another session than the colony's current one (latest session
     * start), or strictly older than the stored state of the same session, is stale (a paused
     * game resends the same game time: it is stored, so the colony stays live). A
     * state without session fields (older mod) is never stale: last write wins.
     */
    private function isStale(string $guid, ?Colony $colony, string $sessionId, float $gameSeconds): bool
    {
        $current = UplinkSession::query()->where('colony_guid', $guid)->latest('started_at')->latest('id')->value('session_id');

        if ($current !== null && $current !== $sessionId) {
            return true;
        }

        return $colony !== null && $colony->session_id === $sessionId
            && $colony->game_seconds !== null && $colony->game_seconds > $gameSeconds;
    }

    private function looksLikeColonyState(string $rawMessage): bool
    {
        return str_contains(mb_substr($rawMessage, 0, 200, '8bit'), self::EVENT);
    }

    /**
     * Names of the sections the mod reported as failed: an object carrying an
     * `error` key instead of its data.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function sectionErrors(array $data): array
    {
        $errors = [];

        foreach ($data as $section => $value) {
            if (is_array($value) && ! array_is_list($value) && array_key_exists('error', $value)) {
                $errors[] = (string) $section;
            }
        }

        return $errors;
    }

    /**
     * Writes the journal entry, then drops all but the last {@see self::KEEP}.
     *
     * @param  array{event?: ?string, colony_guid?: ?string, colony_name?: ?string, cycle?: ?int, outcome: string, reason?: ?string, section_errors?: list<string>}  $attributes
     */
    private function journal(string $rawMessage, array $attributes): UplinkMessage
    {
        $message = UplinkMessage::query()->create([
            'event' => self::EVENT,
            'size' => mb_strlen($rawMessage, '8bit'),
            'section_errors' => [],
            'payload' => mb_strcut($rawMessage, 0, self::PAYLOAD_LIMIT, 'UTF-8'),
            'received_at' => now(),
            ...$attributes,
        ]);

        $oldestKeptId = UplinkMessage::query()->orderByDesc('id')->skip(self::KEEP - 1)->value('id');
        if ($oldestKeptId !== null) {
            UplinkMessage::query()->where('id', '<', $oldestKeptId)->delete();
        }

        return $message;
    }
}
