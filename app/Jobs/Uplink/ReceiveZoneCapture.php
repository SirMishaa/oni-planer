<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Actions\Uplink\StoreZoneCapture;
use App\Enums\UplinkMessageType;
use App\Services\Uplink\UplinkMessageReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * `client-zone.capture`: a picture of a zone the game rendered into the mod folder, or why it
 * could not. Always acked: a capture that cannot be stored now never can (the file or the zone
 * is gone), so the mod must stop resending it.
 */
final class ReceiveZoneCapture implements ShouldQueue
{
    use Queueable;

    /** A file name as the mod writes it, so a message can never point outside the zone's captures folder. */
    private const string FILE_NAME = '/^[\w.-]+\.png$/';

    public int $tries = 1;

    public function __construct(public readonly string $frame) {}

    public function handle(UplinkMessageReader $reader, StoreZoneCapture $storeCapture): void
    {
        $message = $reader->read($this->frame, UplinkMessageType::ZoneCapture);

        if ($message === null || ! is_string($message['id'] ?? null)) {
            return;
        }

        $failure = $message['failure'] ?? null;
        $wellFormed = is_string($message['colonyId'] ?? null)
            && is_string($message['zoneId'] ?? null)
            && in_array($message['trigger'] ?? null, ['manual', 'auto'], true)
            && (is_int($message['gameSeconds'] ?? null) || is_float($message['gameSeconds'] ?? null))
            && (($message['commandId'] ?? null) === null || is_string($message['commandId']))
            && (($message['signature'] ?? null) === null || is_string($message['signature']))
            && (is_string($failure) || (
                is_string($message['fileName'] ?? null) && preg_match(self::FILE_NAME, $message['fileName']) === 1
                && is_int($message['width'] ?? null) && is_int($message['height'] ?? null)
            ));

        if ($wellFormed) {
            $storeCapture->handle($message);
        } else {
            $reader->drop($message, UplinkMessageType::ZoneCapture, 'malformed capture');
        }

        $reader->acknowledge($message);
    }
}
