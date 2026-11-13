<?php

declare(strict_types=1);

namespace App\Actions\Uplink;

use App\Events\Uplink\UplinkCommandIssued;
use App\Models\UplinkCommand;

/** Records a command for the mod of a colony and broadcasts it. */
final class SendUplinkCommand
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $colonyGuid, string $type, array $payload, int $ttlSeconds = 60): UplinkCommand
    {
        UplinkCommand::expireStale();

        $command = UplinkCommand::query()->create([
            'colony_guid' => $colonyGuid,
            'type' => $type,
            'payload' => $payload,
            'status' => UplinkCommand::PENDING,
            'issued_at' => now(),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        UplinkCommandIssued::dispatch($command);

        return $command;
    }
}
