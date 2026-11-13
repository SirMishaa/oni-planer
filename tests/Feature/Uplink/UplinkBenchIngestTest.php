<?php

declare(strict_types=1);

use App\Events\Uplink\ZoneStateStored;
use App\Listeners\Uplink\RecordBenchLatency;
use App\Models\UplinkSession;
use App\Models\Zone;
use App\Services\Uplink\IngestBench;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    config(['reverb.apps.apps.0.key' => 'bench-test-key']);
    Redis::del(IngestBench::LATENCY_KEY);
});

afterEach(function (): void {
    Redis::del(IngestBench::LATENCY_KEY);
});

it('rejects invalid rates', function (string $rates): void {
    $this->artisan('uplink:bench', ['--ingest' => true, '--rates' => $rates])->assertFailed();

    Process::assertNothingRan();
})->with(['zero' => ['0'], 'text' => ['abc'], 'empty' => ['']]);

it('removes the bench colony after a run, even when k6 fails', function (): void {
    Process::fake(['*' => Process::result(errorOutput: 'k6 exploded', exitCode: 1)]);

    $this->artisan('uplink:bench', ['--ingest' => true, '--rates' => '7', '--duration' => '1'])
        ->expectsOutputToContain('k6 failed: k6 exploded')
        ->assertFailed();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'k6');

    expect(Zone::query()->where('colony_guid', 'like', 'bench-%')->count())->toBe(0)
        ->and(UplinkSession::query()->where('colony_guid', 'like', 'bench-%')->count())->toBe(0);
});

it('prints one row per rate from the recorded latencies', function (): void {
    Process::fake(['*' => function (PendingProcess $process) {
        $sent = ((int) $process->environment['RATE']) * ((int) $process->environment['DURATION']);
        foreach (range(1, $sent) as $index) {
            Redis::rpush(IngestBench::LATENCY_KEY, (string) (10 + $index % 5));
        }
        File::put($process->environment['SUMMARY'], (string) json_encode(['sent' => $sent]));

        return Process::result();
    }]);

    $this->artisan('uplink:bench', ['--ingest' => true, '--rates' => '7,50', '--duration' => '2', '--zones' => '3'])
        ->expectsTable(
            ['Rate', 'Sent', 'Stored', 'Lost', 'Throughput/s', 'Median', 'p95', 'Max'],
            [
                ['7/s', '14', '14', '0', '7.0', '12 ms', '14 ms', '14 ms'],
                ['50/s', '100', '100', '0', '50.0', '12 ms', '14 ms', '14 ms'],
            ],
        )
        ->assertSuccessful();

    Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command[0] === 'k6', 2);
});

it('records the latency of bench colonies only', function (): void {
    $this->travelTo(now()->setTimestamp(1_000));

    app(RecordBenchLatency::class)->handle(new ZoneStateStored('bench-x', 'zone', 'fast', 999.5));
    app(RecordBenchLatency::class)->handle(new ZoneStateStored('colony-1', 'zone', 'fast', 999.5));

    expect(Redis::lrange(IngestBench::LATENCY_KEY, 0, -1))->toBe(['500']);
});
