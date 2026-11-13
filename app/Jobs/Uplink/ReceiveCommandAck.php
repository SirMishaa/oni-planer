<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Uplink\UplinkMessageType;

/** `client-command.ack`: the mod's answer to a command of the site. */
final class ReceiveCommandAck extends UplinkMessageJob
{
    public static function type(): UplinkMessageType
    {
        return UplinkMessageType::CommandAck;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function process(array $message): bool
    {
        return false;
    }
}
