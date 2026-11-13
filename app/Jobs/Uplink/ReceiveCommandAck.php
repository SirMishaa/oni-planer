<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Enums\UplinkMessageType;
use App\Models\UplinkCommand;
use App\Services\Uplink\UplinkMessageReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * `client-command.ack`: the mod's answer to a command of the site. Records
 * done or refused on a pending command; an answer for an unknown, expired
 * or already answered command changes nothing but is still acked, so the
 * mod stops resending it.
 */
final class ReceiveCommandAck implements ShouldQueue
{
    use Queueable;

    private const array STATUSES = [UplinkCommand::DONE, UplinkCommand::REFUSED, UplinkCommand::EXPIRED];

    public int $tries = 1;

    public function __construct(public readonly string $frame) {}

    public function handle(UplinkMessageReader $reader): void
    {
        $message = $reader->read($this->frame, UplinkMessageType::CommandAck);

        if ($message === null
            || ! is_string($message['id'] ?? null)
            || ! is_string($message['colonyId'] ?? null)
            || ! is_string($message['commandId'] ?? null)
            || ! in_array($message['status'] ?? null, self::STATUSES, true)) {
            return;
        }

        UplinkCommand::expireStale();

        UplinkCommand::query()
            ->whereKey($this->uuidOrNull($message['commandId']))
            ->where('colony_guid', $message['colonyId'])
            ->where('status', UplinkCommand::PENDING)
            ->update([
                'status' => $message['status'],
                'reason' => is_string($message['reason'] ?? null) ? mb_substr($message['reason'], 0, 255) : null,
                'acked_at' => now(),
            ]);

        $reader->acknowledge($message);
    }

    /** PostgreSQL refuses a malformed uuid in a comparison: such an id matches nothing. */
    private function uuidOrNull(string $id): ?string
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1 ? $id : null;
    }
}
