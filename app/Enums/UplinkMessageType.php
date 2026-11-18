<?php

declare(strict_types=1);

namespace App\Enums;

use App\Jobs\Uplink\ReceiveChatAsk;
use App\Jobs\Uplink\ReceiveColonyState;
use App\Jobs\Uplink\ReceiveCommandAck;
use App\Jobs\Uplink\ReceiveSessionStart;
use App\Jobs\Uplink\ReceiveZoneCapture;
use App\Jobs\Uplink\ReceiveZoneFastState;
use App\Jobs\Uplink\ReceiveZoneLayout;
use App\Jobs\Uplink\ReceiveZoneList;
use App\Jobs\Uplink\ReceiveZoneSlowState;

/**
 * Every message the Uplink mod sends, keyed by its Pusher event name. Each
 * type has one job that handles it, a queue and its own schema version.
 * Reliable messages are acknowledged once stored and processed in order by
 * the single `uplink-reliable` worker; states are latest-only and spread
 * over the `uplink-state` workers.
 */
enum UplinkMessageType: string
{
    case SessionStart = 'client-session.start';
    case ZoneList = 'client-zone.list';
    case ZoneLayout = 'client-zone.layout';
    case ZoneSlowState = 'client-zone.slow';
    case ZoneFastState = 'client-zone.fast';
    case ColonyState = 'client-colony.state';
    case CommandAck = 'client-command.ack';
    case ZoneCapture = 'client-zone.capture';
    case ChatAsk = 'client-chat.ask';

    public const string DATA_CHANNEL = 'uplink-data';

    public const string COLONY_CHANNEL = 'uplink';

    public static function fromFrame(?string $channel, ?string $event): ?self
    {
        $type = $event === null ? null : self::tryFrom($event);

        return $type !== null && $type->channel() === $channel ? $type : null;
    }

    public function channel(): string
    {
        return $this === self::ColonyState ? self::COLONY_CHANNEL : self::DATA_CHANNEL;
    }

    public function isReliable(): bool
    {
        return in_array($this, [self::SessionStart, self::ZoneList, self::ZoneLayout, self::CommandAck, self::ZoneCapture, self::ChatAsk], true);
    }

    public function queue(): string
    {
        return $this->isReliable() ? 'uplink-reliable' : 'uplink-state';
    }

    /** Highest schema version of this message the site understands. */
    public function schema(): int
    {
        return 1;
    }

    /** @return class-string<ReceiveSessionStart|ReceiveZoneList|ReceiveZoneLayout|ReceiveZoneSlowState|ReceiveZoneFastState|ReceiveColonyState|ReceiveCommandAck|ReceiveZoneCapture|ReceiveChatAsk> */
    public function job(): string
    {
        return match ($this) {
            self::SessionStart => ReceiveSessionStart::class,
            self::ZoneList => ReceiveZoneList::class,
            self::ZoneLayout => ReceiveZoneLayout::class,
            self::ZoneSlowState => ReceiveZoneSlowState::class,
            self::ZoneFastState => ReceiveZoneFastState::class,
            self::ColonyState => ReceiveColonyState::class,
            self::CommandAck => ReceiveCommandAck::class,
            self::ZoneCapture => ReceiveZoneCapture::class,
            self::ChatAsk => ReceiveChatAsk::class,
        };
    }
}
