<?php

declare(strict_types=1);

use App\Actions\Uplink\StoreZoneState;
use App\Events\Uplink\ZoneStateStored;
use App\Models\Colony;
use App\Models\UplinkSession;
use App\Models\Zone;
use App\Services\Uplink\ColonyIngestor;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a']);
});

function startSession(string $sessionId, int $minutesAgo = 0, string $colonyGuid = 'colony-1'): void
{
    UplinkSession::factory()->create(['session_id' => $sessionId, 'colony_guid' => $colonyGuid, 'started_at' => now()->subMinutes($minutesAgo)]);
}

/** @return array<string, mixed> */
function zoneState(float $gameSeconds, ?string $sessionId, array $extra = []): array
{
    return ['schema' => 1, 'colonyId' => 'colony-1', 'zoneId' => 'zone-a', 'gameSeconds' => $gameSeconds, ...($sessionId === null ? [] : ['sessionId' => $sessionId]), ...$extra];
}

function storedZone(): Zone
{
    return Zone::query()->sole();
}

it('drops an older state of the same session', function (): void {
    startSession('A');
    Event::fake([ZoneStateStored::class]);

    expect(app(StoreZoneState::class)->handle('fast', zoneState(200, 'A')))->toBeTrue()
        ->and(app(StoreZoneState::class)->handle('fast', zoneState(150, 'A')))->toBeFalse()
        ->and(storedZone()->fast_state['gameSeconds'])->toEqual(200);
    Event::assertDispatchedTimes(ZoneStateStored::class, 1);
});

it('drops a state from a previous session even when its gameSeconds is higher', function (): void {
    startSession('A', 10);
    startSession('B');

    app(StoreZoneState::class)->handle('fast', zoneState(100, 'B'));
    $stored = app(StoreZoneState::class)->handle('fast', zoneState(9000, 'A'));

    expect($stored)->toBeFalse()
        ->and(storedZone()->fast_state['sessionId'])->toBe('B')
        ->and(storedZone()->fast_game_seconds)->toEqual(100);
});

it('replaces a state of another session with the current session state', function (): void {
    startSession('A', 10);
    app(StoreZoneState::class)->handle('fast', zoneState(9000, 'A'));
    startSession('B');

    expect(app(StoreZoneState::class)->handle('fast', zoneState(100, 'B')))->toBeTrue()
        ->and(storedZone()->fast_session_id)->toBe('B')
        ->and(storedZone()->fast_game_seconds)->toEqual(100);
});

it('drops a new-session state processed before its session start', function (): void {
    startSession('A');

    expect(app(StoreZoneState::class)->handle('slow', zoneState(10, 'B')))->toBeFalse()
        ->and(storedZone()->slow_state)->toBeNull();
});

it('treats a state without sessionId as current', function (): void {
    startSession('A');
    app(StoreZoneState::class)->handle('fast', zoneState(100, 'A'));

    expect(app(StoreZoneState::class)->handle('fast', zoneState(150, null)))->toBeTrue()
        ->and(storedZone()->fast_session_id)->toBe('A')
        ->and(storedZone()->fast_game_seconds)->toEqual(150);
});

it('stores a state without sessionId when no session is known', function (): void {
    expect(app(StoreZoneState::class)->handle('slow', zoneState(5, null)))->toBeTrue()
        ->and(storedZone()->slow_game_seconds)->toEqual(5);
});

it('ignores a state for an unknown zone', function (): void {
    expect(app(StoreZoneState::class)->handle('slow', [...zoneState(5, null), 'zoneId' => 'unknown']))->toBeFalse();
});

it('keeps previous_slow_temperature within one session', function (): void {
    startSession('A', 10);
    app(StoreZoneState::class)->handle('slow', zoneState(100, 'A', ['box' => ['x' => 1], 'temperature' => [300.0]]));
    app(StoreZoneState::class)->handle('slow', zoneState(130, 'A', ['box' => ['x' => 1], 'temperature' => [301.0]]));

    expect(storedZone()->previous_slow_temperature)->toMatchArray(['box' => ['x' => 1], 'temperature' => [300.0]])
        ->and(storedZone()->previous_slow_temperature['at'])->toBeString();

    startSession('B');
    app(StoreZoneState::class)->handle('slow', zoneState(50, 'B', ['box' => ['x' => 1], 'temperature' => [280.0]]));

    expect(storedZone()->previous_slow_temperature)->toBeNull()
        ->and(storedZone()->slow_state['temperature'])->toBe([280.0]);
});

it('orders colony states within the session', function (): void {
    startSession('A', colonyGuid: 'guid-deepwater');
    $ingestor = app(ColonyIngestor::class);

    $ingestor->ingest(uplinkFixture(['sessionId' => 'A', 'gameSeconds' => 500.0, 'colony' => ['cycle' => 60]]));
    $late = $ingestor->ingest(uplinkFixture(['sessionId' => 'A', 'gameSeconds' => 400.0, 'colony' => ['cycle' => 59]]));
    $old = $ingestor->ingest(uplinkFixture(['sessionId' => 'OLD', 'gameSeconds' => 9000.0, 'colony' => ['cycle' => 90]]));

    expect($late->outcome)->toBe('refused')->and($late->reason)->toBe('stale')
        ->and($old->outcome)->toBe('refused')->and($old->reason)->toBe('stale')
        ->and(Colony::query()->sole()->cycle)->toBe(60);
});

it('stores a colony state without session fields', function (): void {
    startSession('A', colonyGuid: 'guid-deepwater');
    $ingestor = app(ColonyIngestor::class);

    $ingestor->ingest(uplinkFixture(['sessionId' => 'A', 'gameSeconds' => 500.0, 'colony' => ['cycle' => 60]]));
    $message = $ingestor->ingest(uplinkFixture(['colony' => ['cycle' => 58]]));

    expect($message->outcome)->toBe('stored')
        ->and(Colony::query()->sole()->cycle)->toBe(58);
});

it('keeps a paused colony live: the same state sent again is stored', function (): void {
    startSession('A', colonyGuid: 'guid-deepwater');
    $ingestor = app(ColonyIngestor::class);
    $state = uplinkFixture(['sessionId' => 'A', 'gameSeconds' => 500.0]);

    $ingestor->ingest($state);
    $this->travel(15)->seconds();
    $again = $ingestor->ingest($state);

    expect($again->outcome)->toBe('stored')
        ->and(Colony::query()->sole()->last_seen_at->toDateTimeString())->toBe(now()->toDateTimeString());
});
