<?php

declare(strict_types=1);

use App\Enums\UplinkMessageType;
use App\Models\UplinkCommand;
use App\Models\UplinkSession;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;

/*
 * Processing baseline of each message type, in the normal suite: a large-colony
 * message (tests/Fixtures/Uplink/perf, real format) goes through its job RUNS
 * times; the median must stay under the type's budget and one run must make
 * exactly the expected number of queries. Budgets are about 3x the median
 * measured on the dev machine (5 ms floor), so a slower or busy machine (the game running)
 * does not fail without a regression; an over-budget median is measured once
 * more before failing. A new message type fails here until it has a fixture,
 * a budget and a query count.
 */

const BASELINE_RUNS = 15;

/** Median budget per message type, in milliseconds. */
const BASELINE_BUDGETS = [
    'client-session.start' => 5.0,  // measured median 1.3 ms on 2026-11-13 (floor 5 ms)
    'client-zone.list' => 82.0,     // measured median 27.0 ms on 2026-11-13 (30 zones)
    'client-zone.layout' => 9.0,    // measured median 2.8 ms on 2026-11-13 (1 260 cells)
    'client-zone.slow' => 10.0,     // measured median 3.3 ms on 2026-11-13 (73 KB state)
    'client-zone.fast' => 5.0,      // measured median 1.2 ms on 2026-11-13 (floor 5 ms)
    'client-colony.state' => 19.0,  // measured median 6.1 ms on 2026-11-13 (real 74 KB state)
    'client-command.ack' => 5.0,    // measured median 1.1 ms on 2026-11-13 (floor 5 ms)
    'client-zone.capture' => 22.0,  // measured median 7.3 ms on 2026-11-14 (converts a 64x64 PNG to WebP; a real capture takes ~85 ms)
];

/** Queries of one run of each message type (a change means the handler's work changed). */
const BASELINE_QUERIES = [
    'client-session.start' => 2,
    'client-zone.list' => 61,
    'client-zone.layout' => 5,
    'client-zone.slow' => 2,
    'client-zone.fast' => 2,
    'client-colony.state' => 7,
    'client-command.ack' => 2,
    'client-zone.capture' => 4,
];

/**
 * The raw frame of run $run: the fixture message, made unique per run where the
 * handler would otherwise skip the work (newer game time, new session, a
 * pending command to answer).
 */
function baselineFrame(UplinkMessageType $type, int $run): string
{
    $name = str_replace(['client-', '.'], ['', '-'], $type->value);
    $path = base_path("tests/Fixtures/Uplink/perf/{$name}.json");
    expect(is_file($path))->toBeTrue("Message type {$type->value} has no baseline fixture at {$path}");

    /** @var array<string, mixed> $message */
    $message = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    $message = match ($type) {
        UplinkMessageType::SessionStart => [...$message, 'sessionId' => "perf-session-{$run}"],
        UplinkMessageType::ZoneSlowState, UplinkMessageType::ZoneFastState => [...$message, 'gameSeconds' => 40_000.0 + $run],
        UplinkMessageType::CommandAck => [...$message, 'commandId' => UplinkCommand::factory()->create(['colony_guid' => 'perf-colony'])->id],
        UplinkMessageType::ZoneCapture => baselineCaptureFile([...$message, 'fileName' => "perf-{$run}.png"]),
        default => $message,
    };

    if ($type === UplinkMessageType::ColonyState) {
        return (string) json_encode(['event' => $type->value, 'channel' => $type->channel(), 'data' => json_encode($message, JSON_PRESERVE_ZERO_FRACTION)]);
    }

    return uplinkData($type->value, $message, $type->channel());
}

/**
 * Puts the capture's PNG in a temp mod folder and fakes the public disk, so the job copies a real file.
 *
 * @param  array<string, mixed>  $message
 * @return array<string, mixed>
 */
function baselineCaptureFile(array $message): array
{
    $modPath = sys_get_temp_dir().'/uplink-perf-mod';
    Illuminate\Support\Facades\File::ensureDirectoryExists("{$modPath}/captures/{$message['zoneId']}");
    $image = imagecreatetruecolor(64, 64);
    imagepng($image, "{$modPath}/captures/{$message['zoneId']}/{$message['fileName']}");
    config(['game.uplink_mod_path' => $modPath]);
    Illuminate\Support\Facades\Storage::fake('public');

    return $message;
}

/** Median duration of $runs runs in milliseconds, first run discarded as warm-up. */
function baselineMedian(UplinkMessageType $type, int $runs): float
{
    $durations = [];

    foreach (range(0, $runs) as $run) {
        $job = $type->job();
        $job = new $job(baselineFrame($type, $run));
        $started = hrtime(true);
        app()->call([$job, 'handle']);
        $durations[] = (hrtime(true) - $started) / 1e6;
    }

    array_shift($durations);
    sort($durations);

    return $durations[intdiv(count($durations), 2)];
}

it('processes each message type within its baseline', function (UplinkMessageType $type): void {
    expect(BASELINE_BUDGETS)->toHaveKey($type->value)
        ->and(BASELINE_QUERIES)->toHaveKey($type->value);

    UplinkSession::factory()->create(['colony_guid' => 'perf-colony', 'session_id' => 'perf-session', 'started_at' => now()->subMinute()]);
    foreach (range(0, 29) as $index) {
        Zone::factory()->create(['colony_guid' => 'perf-colony', 'zone_guid' => "perf-zone-{$index}", 'layout' => ['cells' => [1]]]);
    }

    $frame = baselineFrame($type, 1_000);
    $job = $type->job();
    DB::flushQueryLog();
    DB::enableQueryLog();
    app()->call([new $job($frame), 'handle']);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $median = baselineMedian($type, BASELINE_RUNS);
    if ($median >= BASELINE_BUDGETS[$type->value]) {
        $median = baselineMedian($type, BASELINE_RUNS);
    }

    expect($median)->toBeLessThan(BASELINE_BUDGETS[$type->value])
        ->and($queries)->toBe(BASELINE_QUERIES[$type->value]);
})->with(UplinkMessageType::cases());
