<?php

declare(strict_types=1);

namespace App\Events\Uplink;

use App\Enums\UplinkMessageType;
use App\Models\UplinkCommand;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sends a command to the mod as `uplink.command` on the data channel. Every
 * running mod receives it; one whose loaded colony is not `colonyId`
 * ignores it.
 */
final class UplinkCommandIssued implements ShouldBroadcastNow
{
    use Dispatchable;

    public const int SCHEMA = 1;

    public function __construct(public readonly UplinkCommand $command) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel(UplinkMessageType::DATA_CHANNEL)];
    }

    public function broadcastAs(): string
    {
        return 'uplink.command';
    }

    /**
     * @return array{schema: int, id: string, colonyId: string, type: string, issuedAt: string, expiresAt: string, payload: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'schema' => self::SCHEMA,
            'id' => $this->command->id,
            'colonyId' => $this->command->colony_guid,
            'type' => $this->command->type,
            'issuedAt' => $this->command->issued_at->toIso8601ZuluString(),
            'expiresAt' => $this->command->expires_at->toIso8601ZuluString(),
            'payload' => $this->command->payload,
        ];
    }
}
