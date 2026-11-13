<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Enums\UplinkMessageType;
use App\Services\Uplink\UplinkMessageReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** `client-command.ack`: the mod's answer to a command of the site. */
final class ReceiveCommandAck implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $frame) {}

    public function handle(UplinkMessageReader $reader): void
    {
        $message = $reader->read($this->frame, UplinkMessageType::CommandAck);

        if ($message === null) {
            return;
        }
    }
}
