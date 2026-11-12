<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Measures how the local Reverb handles Uplink-sized messages: runs the k6
 * script `tests/Performance/uplink-ws.js`, which sends `client-bench.ping`
 * events and waits for the acknowledgements of
 * {@see \App\Listeners\AnswerUplinkBenchmark}, then prints one row per size.
 * Reverb must be running (and restarted after a change to the listener).
 * Measure a Reverb started with XDEBUG_MODE=off: Xdebug's develop mode
 * roughly doubles the times; --profile always starts its server that way.
 */
#[Description('Benchmark Uplink messages through the local Reverb with k6 (round trip, transit, PHP decode, memory)')]
#[Signature('uplink:bench
    {--sizes=100,1000,4000 : Payload sizes in KB, comma separated}
    {--iterations=5 : Messages sent per size}
    {--channel=uplink-bench : Channel used; "uplink" also measures the fan-out to open pages}
    {--host=127.0.0.1 : Host Reverb listens on}
    {--port= : Port Reverb listens on, defaults to reverb.servers.reverb.port}
    {--timeout=30000 : Milliseconds to wait for one acknowledgement}
    {--encoding=json : How data is sent: json, or base64 (Reverb keeps it opaque)}
    {--profile : Start a temporary Reverb (on --port, default 8099) profiled with Excimer and print where the time goes}')]
final class UplinkBenchCommand extends Command
{
    private const string SCRIPT = 'tests/Performance/uplink-ws.js';

    private const string PROFILER = 'tests/Performance/excimer-prepend.php';

    private const int PROFILE_PORT = 8099;

    /** Functions listed in each profile table. */
    private const int PROFILE_ROWS = 15;

    public function handle(): int
    {
        $key = (string) config('reverb.apps.apps.0.key');
        if ($key === '') {
            $this->error('REVERB_APP_KEY is not set.');

            return self::FAILURE;
        }

        $sizes = array_values(array_filter(array_map(intval(...), explode(',', (string) $this->option('sizes'))), fn (int $size): bool => $size > 0));
        if ($sizes === []) {
            $this->error('--sizes must list at least one positive size in KB.');

            return self::FAILURE;
        }

        $summaryPath = storage_path('app/private/bench/uplink-'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($summaryPath));
        $profilePrefix = mb_substr($summaryPath, 0, -5);

        $server = null;
        if ($this->option('profile')) {
            if (! extension_loaded('excimer')) {
                $this->error('--profile needs the excimer extension (sudo pie install wikimedia/excimer).');

                return self::FAILURE;
            }

            $server = $this->startProfiledServer($profilePrefix);
            if ($server === null) {
                return self::FAILURE;
            }
        }

        $result = Process::forever()
            ->env([
                'URL' => $this->url($key),
                'CHANNEL' => (string) $this->option('channel'),
                'SIZES_KB' => implode(',', $sizes),
                'ITERATIONS' => (string) max(1, (int) $this->option('iterations')),
                'TIMEOUT_MS' => (string) max(1000, (int) $this->option('timeout')),
                'SUMMARY' => $summaryPath,
                'ENCODING' => (string) $this->option('encoding'),
            ])
            ->run(['k6', 'run', '--quiet', '--no-color', base_path(self::SCRIPT)]);

        if ($server instanceof InvokedProcess) {
            usleep(1_500_000);
            $server->signal(SIGTERM);
            $server->wait();
        }

        if (! File::exists($summaryPath)) {
            $this->error('k6 produced no summary. Is k6 installed and Reverb running?');
            $this->line(mb_trim($result->errorOutput()."\n".$result->output()));

            return self::FAILURE;
        }

        /** @var array<string, array{values?: array<string, float|int>}> $metrics */
        $metrics = json_decode((string) File::get($summaryPath), true, flags: JSON_THROW_ON_ERROR);

        $this->table(
            ['Size', 'Acked', 'Lost', 'Round trip med / p95', 'Transit med', 'PHP decode med', 'Peak memory'],
            array_map(fn (int $size): array => $this->row($size, $metrics), $sizes),
        );
        $this->line('Round trip: send to acknowledgement. Transit: send to the listener starting (socket, frame assembly, Reverb\'s own decode and fan-out).');
        $this->line('Summary: '.$summaryPath);

        if ($server instanceof InvokedProcess) {
            $this->printProfile($profilePrefix);
        }

        return self::SUCCESS;
    }

    private function port(): int
    {
        return (int) ($this->option('port') ?: ($this->option('profile') ? self::PROFILE_PORT : config('reverb.servers.reverb.port')));
    }

    /** Starts Reverb with the Excimer prepend file and waits until it accepts connections. */
    private function startProfiledServer(string $profilePrefix): ?InvokedProcess
    {
        $port = $this->port();
        $server = Process::forever()
            ->env(['EXCIMER_OUTPUT' => $profilePrefix, 'XDEBUG_MODE' => 'off'])
            ->start([PHP_BINARY, '-d', 'auto_prepend_file='.base_path(self::PROFILER), base_path('artisan'), 'reverb:start', '--port='.$port]);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @fsockopen((string) $this->option('host'), $port, $errorCode, $errorMessage, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return $server;
            }

            usleep(200_000);
        }

        $server->signal(SIGTERM);
        $this->error("The profiled Reverb did not start on port {$port}.");
        $this->line(mb_trim($server->latestErrorOutput()."\n".$server->latestOutput()));

        return null;
    }

    /** Prints the functions with the most samples (1 sample = 1 ms of wall clock). */
    private function printProfile(string $profilePrefix): void
    {
        if (! File::exists($profilePrefix.'.functions.json')) {
            $this->error('Excimer wrote no profile.');

            return;
        }

        /** @var array<string, array{self: int, inclusive: int}> $functions */
        $functions = json_decode((string) File::get($profilePrefix.'.functions.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach (['self', 'inclusive'] as $kind) {
            uasort($functions, fn (array $a, array $b): int => $b[$kind] <=> $a[$kind]);
            $this->table(
                ['Function', ucfirst($kind).' (ms)'],
                array_map(fn (string $name, array $samples): array => [$name, (string) $samples[$kind]], array_keys(array_slice($functions, 0, self::PROFILE_ROWS, true)), array_slice($functions, 0, self::PROFILE_ROWS, true)),
            );
        }

        $this->line('Flame graph: '.$profilePrefix.'.speedscope.json (open in https://www.speedscope.app)');
    }

    private function url(string $key): string
    {
        $scheme = config('reverb.apps.apps.0.options.scheme') === 'https' ? 'wss' : 'ws';

        return sprintf('%s://%s:%d/app/%s?protocol=7&client=uplink-bench', $scheme, (string) $this->option('host'), $this->port(), $key);
    }

    /**
     * @param  array<string, array{values?: array<string, float|int>}>  $metrics
     * @return list<string>
     */
    private function row(int $size, array $metrics): array
    {
        $value = fn (string $metric, string $stat): ?float => isset($metrics["{$metric}_{$size}kb"]['values'][$stat])
            ? (float) $metrics["{$metric}_{$size}kb"]['values'][$stat]
            : null;
        $ms = fn (?float $milliseconds): string => $milliseconds === null ? 'n/a' : number_format($milliseconds, 1).' ms';

        $acked = (int) ($metrics["roundtrip_{$size}kb"]['values']['count'] ?? 0);
        $bytes = $value('bytes', 'med');

        return [
            ($bytes === null ? $size.' KB' : number_format($bytes / 1024).' KB'),
            (string) $acked,
            (string) (int) ($metrics["lost_{$size}kb"]['values']['count'] ?? 0),
            $ms($value('roundtrip', 'med')).' / '.$ms($value('roundtrip', 'p(95)')),
            $ms($value('inbound', 'med')),
            $ms($value('decode', 'med')),
            ($memory = $value('memory', 'max')) === null ? 'n/a' : number_format($memory, 1).' MB',
        ];
    }
}
