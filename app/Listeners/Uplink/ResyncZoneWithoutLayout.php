<?php

declare(strict_types=1);

namespace App\Listeners\Uplink;

use App\Actions\Uplink\SendUplinkCommand;
use App\Events\Uplink\ZoneStateStored;
use App\Models\UplinkCommand;
use App\Models\Zone;
use Illuminate\Support\Facades\Cache;

/**
 * Asks the mod to resend a zone whose layout never arrived. Waits 30 s after
 * the zone was created, so the mod's own resend of unacked layouts (15 s)
 * gets its chance first after a reconnect; sends one `zone.resync` at a time
 * per zone (a lock covers two workers storing states of the same zone); and
 * after a resync expired unanswered (a mod without commands), waits 10
 * minutes before asking again.
 */
final class ResyncZoneWithoutLayout
{
    private const int GRACE_SECONDS = 30;

    private const int BACKOFF_MINUTES = 10;

    public function __construct(private readonly SendUplinkCommand $sendCommand) {}

    public function handle(ZoneStateStored $event): void
    {
        $needsLayout = Zone::query()
            ->where('colony_guid', $event->colonyGuid)
            ->where('zone_guid', $event->zoneGuid)
            ->whereNull('layout')
            ->where('created_at', '<=', now()->subSeconds(self::GRACE_SECONDS))
            ->exists();

        if (! $needsLayout) {
            return;
        }

        $lock = Cache::lock("uplink:resync:{$event->colonyGuid}:{$event->zoneGuid}", 10);

        if (! $lock->get()) {
            return;
        }

        try {
            UplinkCommand::expireStale();

            $blocked = UplinkCommand::query()
                ->where('colony_guid', $event->colonyGuid)
                ->where('type', 'zone.resync')
                ->where('payload->zoneId', $event->zoneGuid)
                ->where(fn ($query) => $query
                    ->where('status', UplinkCommand::PENDING)
                    ->orWhere(fn ($query) => $query
                        ->where('status', UplinkCommand::EXPIRED)
                        ->where('expires_at', '>', now()->subMinutes(self::BACKOFF_MINUTES))))
                ->exists();

            if (! $blocked) {
                $this->sendCommand->handle($event->colonyGuid, 'zone.resync', ['zoneId' => $event->zoneGuid]);
            }
        } finally {
            $lock->release();
        }
    }
}
