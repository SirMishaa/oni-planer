<?php

declare(strict_types=1);

namespace App\Services\Uplink;

use JsonException;

/**
 * Decodes the `data` of a message sent by the game mod. Sent as
 * `{"payload": "<base64, optionally of gzip>"}`, Reverb keeps the content as
 * an opaque string instead of walking it; JSON text and plain arrays are
 * accepted too.
 */
final class UplinkPayload
{
    public const int MAX_DECODED_BYTES = 64 * 1024 * 1024;

    /**
     * @return array<string, mixed>|null the JSON object, or null when unreadable
     */
    public static function decode(mixed $data): ?array
    {
        if (is_array($data) && is_string($data['payload'] ?? null)) {
            $data = $data['payload'];
        }

        if (is_string($data)) {
            $data = self::unwrap($data);
        }

        if (! is_array($data) && ! is_string($data)) {
            return null;
        }

        if (is_string($data)) {
            $data = self::parseJson($data);
        }

        return is_array($data) && ($data === [] || ! array_is_list($data)) ? $data : null;
    }

    /**
     * Returns the JSON text held by a string that is either JSON itself or base64 (of gzip).
     */
    private static function unwrap(string $data): ?string
    {
        if ($data === '' || $data[0] === '{' || $data[0] === '[' || $data[0] === '"') {
            return $data;
        }

        $bytes = base64_decode($data, true);
        if ($bytes === false) {
            return null;
        }

        if (str_starts_with($bytes, "\x1f\x8b")) {
            $decoded = @gzdecode($bytes, self::MAX_DECODED_BYTES);

            return $decoded === false ? null : $decoded;
        }

        return $bytes;
    }

    private static function parseJson(?string $json): mixed
    {
        if ($json === null) {
            return null;
        }

        try {
            return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
