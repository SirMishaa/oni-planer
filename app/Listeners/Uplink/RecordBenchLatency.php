<?php

declare(strict_types=1);

namespace App\Listeners\Uplink;

use App\Events\Uplink\ZoneStateStored;
use App\Services\Uplink\IngestBench;
use Illuminate\Support\Facades\Redis;

/**
 * Records send-to-stored latency for `uplink:bench --ingest`: bench states
 * carry their send time as game time. It runs in the workers, so it cannot
 * be registered only while the bench runs; outside a bench colony its cost
 * is one string prefix check.
 */
final class RecordBenchLatency
{
    public function handle(ZoneStateStored $event): void
    {
        if (! str_starts_with($event->colonyGuid, IngestBench::COLONY_PREFIX)) {
            return;
        }

        Redis::rpush(IngestBench::LATENCY_KEY, (string) (int) round(now()->getTimestampMs() - $event->gameSeconds * 1000));
    }
}
