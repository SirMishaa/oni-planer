<?php

declare(strict_types=1);

namespace App\Services\Uplink;

use App\Models\Colony;
use App\Models\UplinkCommand;
use App\Models\UplinkSession;
use App\Models\Zone;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * End-to-end ingest bench (`uplink:bench --ingest`): seeds a throwaway bench
 * colony, sends zone states through the running Reverb with k6 at fixed
 * rates, and reads the latencies the workers record (RecordBenchLatency)
 * once the queue drains. The bench colony is removed afterwards, also on
 * failure. Needs the real stack: Reverb and the queue workers running.
 */
final class IngestBench
{
    public const string LATENCY_KEY = 'uplink-bench:latency';

    public const string COLONY_PREFIX = 'bench-';

    private const string SCRIPT = 'tests/Performance/uplink-ingest.js';

    private const string TEMPLATES = 'tests/Fixtures/Uplink/perf';

    /** Half-second polls without a new latency before the queue counts as drained. */
    private const int QUIET_POLLS = 6;

    /** Upper bound of the drain wait, in half-second polls. */
    private const int MAX_POLLS = 240;

    /**
     * @param  list<int>  $rates  messages per second, one k6 run each
     * @param  Closure(string): void  $progress
     * @return list<array{rate: int, sent: int, stored: int, throughput: float, latencies: list<int>}>
     */
    public function run(string $url, array $rates, int $duration, int $zones, int $scale, Closure $progress): array
    {
        $colonyGuid = self::COLONY_PREFIX.Str::lower(Str::random(10));
        $sessionId = $colonyGuid.'-session';
        $directory = storage_path('app/private/bench');
        File::ensureDirectoryExists($directory);
        $payloads = $directory.'/ingest-payloads.json';
        $results = [];

        try {
            $this->seed($colonyGuid, $sessionId, $zones);
            File::put($payloads, (string) json_encode($this->templates($colonyGuid, $sessionId, $zones, $scale)));

            foreach ($rates as $rate) {
                $progress("Sending {$rate} msg/s for {$duration} s...");
                $results[] = $this->step($url, $payloads, $directory, $rate, $duration);
            }
        } finally {
            $this->cleanUp($colonyGuid);
            File::delete($payloads);
            Redis::del(self::LATENCY_KEY);
        }

        return $results;
    }

    /**
     * @return array{rate: int, sent: int, stored: int, throughput: float, latencies: list<int>}
     */
    private function step(string $url, string $payloads, string $directory, int $rate, int $duration): array
    {
        Redis::del(self::LATENCY_KEY);
        $summary = $directory.'/ingest-'.now()->format('Ymd-His').'-'.$rate.'.json';
        $started = Carbon::now();

        $result = Process::forever()
            ->env(['URL' => $url, 'RATE' => (string) $rate, 'DURATION' => (string) $duration, 'PAYLOADS' => $payloads, 'SUMMARY' => $summary])
            ->run(['k6', 'run', '--quiet', '--no-color', base_path(self::SCRIPT)]);

        if (! $result->successful() || ! File::exists($summary)) {
            throw new RuntimeException('k6 failed: '.mb_trim($result->errorOutput()."\n".$result->output()));
        }

        /** @var array{sent?: int} $counts */
        $counts = json_decode((string) File::get($summary), true, flags: JSON_THROW_ON_ERROR);
        $sent = (int) ($counts['sent'] ?? 0);
        File::delete($summary);

        $lastChange = $started;
        $previous = -1;
        $quiet = 0;

        for ($poll = 0; $poll < self::MAX_POLLS; $poll++) {
            $stored = (int) Redis::llen(self::LATENCY_KEY);

            if ($stored >= $sent) {
                $lastChange = Carbon::now();

                break;
            }

            if ($stored === $previous && ++$quiet >= self::QUIET_POLLS) {
                break;
            }

            if ($stored !== $previous) {
                $quiet = 0;
                $lastChange = Carbon::now();
            }

            $previous = $stored;
            Sleep::for(500)->milliseconds();
        }

        $latencies = array_map(intval(...), Redis::lrange(self::LATENCY_KEY, 0, -1));
        $elapsed = max((float) $duration, $started->diffInMilliseconds($lastChange) / 1000);

        return [
            'rate' => $rate,
            'sent' => $sent,
            'stored' => count($latencies),
            'throughput' => count($latencies) / $elapsed,
            'latencies' => $latencies,
        ];
    }

    private function seed(string $colonyGuid, string $sessionId, int $zones): void
    {
        UplinkSession::query()->create(['colony_guid' => $colonyGuid, 'session_id' => $sessionId, 'loaded_game_seconds' => 0.0, 'cycle' => 1, 'started_at' => now()]);

        foreach (range(0, $zones - 1) as $index) {
            Zone::query()->create([
                'colony_guid' => $colonyGuid,
                'zone_guid' => "bench-zone-{$index}",
                'name' => "Bench {$index}",
                'world_id' => 0,
                'version' => 1,
                'cell_count' => 1,
                // A layout, so the zone is not resynced while the bench runs.
                'layout' => ['cells' => [0]],
                'layout_at' => now(),
            ]);
        }
    }

    /**
     * One slow and one fast state per zone, as JSON text whose game time k6
     * fills in at send time (`__GAME_SECONDS__`): monotonic, so the ordering
     * guard stores every state, and it gives the send time to the recorder.
     *
     * @return list<array{slow: string, fast: string}>
     */
    private function templates(string $colonyGuid, string $sessionId, int $zones, int $scale): array
    {
        $templates = [];

        foreach (range(0, $zones - 1) as $index) {
            $zone = [];

            foreach (['slow' => 'zone-slow', 'fast' => 'zone-fast'] as $kind => $file) {
                /** @var array<string, mixed> $message */
                $message = json_decode((string) File::get(base_path(self::TEMPLATES."/{$file}.json")), true, flags: JSON_THROW_ON_ERROR);

                foreach ($message as $key => $value) {
                    if ($scale > 1 && is_array($value) && array_is_list($value) && $key !== 'palette') {
                        $message[$key] = array_merge(...array_fill(0, $scale, $value));
                    }
                }

                $message = [...$message, 'colonyId' => $colonyGuid, 'zoneId' => "bench-zone-{$index}", 'sessionId' => $sessionId, 'gameSeconds' => 0];
                $zone[$kind] = str_replace('"gameSeconds":0', '"gameSeconds":__GAME_SECONDS__', (string) json_encode($message, JSON_PRESERVE_ZERO_FRACTION));
            }

            $templates[] = $zone;
        }

        return $templates;
    }

    private function cleanUp(string $colonyGuid): void
    {
        Zone::query()->where('colony_guid', $colonyGuid)->delete();
        UplinkSession::query()->where('colony_guid', $colonyGuid)->delete();
        UplinkCommand::query()->where('colony_guid', $colonyGuid)->delete();
        Colony::query()->where('guid', $colonyGuid)->delete();
    }
}
