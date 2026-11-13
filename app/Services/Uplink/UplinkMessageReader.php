<?php

declare(strict_types=1);

namespace App\Services\Uplink;

use App\Enums\UplinkMessageType;
use App\Events\Uplink\UplinkMessageAccepted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shared by the message jobs: decodes a raw frame of the mod, refuses a
 * schema newer than its type knows, and acknowledges a stored reliable
 * message (the mod stops resending it).
 */
final class UplinkMessageReader
{
    /**
     * @return array<string, mixed>|null the message, or null when unreadable or refused
     */
    public function read(string $frame, UplinkMessageType $type): ?array
    {
        $envelope = json_decode($frame, true);
        $message = is_array($envelope) ? UplinkPayload::decode($envelope['data'] ?? null) : null;

        return $message !== null && $this->isAcceptedSchema($message, $type) ? $message : null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function acknowledge(array $message): void
    {
        if (is_string($message['id'] ?? null)) {
            UplinkMessageAccepted::dispatch($message['id']);
        }
    }

    /**
     * Whether every entry is an object whose listed keys pass their check.
     *
     * @param  array<mixed>  $entries
     * @param  array<string, callable(mixed): bool>  $checks
     */
    public function entriesMatch(array $entries, array $checks): bool
    {
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                return false;
            }

            foreach ($checks as $key => $check) {
                if (! $check($entry[$key] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Logs a message dropped as malformed (at most once per hour per type and colony).
     *
     * @param  array<string, mixed>  $message
     */
    public function drop(array $message, UplinkMessageType $type, string $reason): void
    {
        $colony = is_string($message['colonyId'] ?? null) ? $message['colonyId'] : '?';

        if (Cache::add("uplink:dropped:{$type->value}:{$colony}", true, now()->addHour())) {
            Log::warning("Uplink: dropped {$type->value} of colony {$colony}: {$reason}");
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isAcceptedSchema(array $message, UplinkMessageType $type): bool
    {
        $schema = $message['schema'] ?? null;

        if (! is_int($schema) || $schema < 1) {
            return false;
        }

        if ($schema > $type->schema()) {
            $colony = is_string($message['colonyId'] ?? null) ? $message['colonyId'] : '?';

            if (Cache::add("uplink:newer-schema:{$type->value}:{$colony}", true, now()->addHour())) {
                Log::warning("Uplink: refused {$type->value} of colony {$colony}, schema {$schema} is newer than {$type->schema()}");
            }

            return false;
        }

        return true;
    }
}
