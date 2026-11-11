<?php

declare(strict_types=1);

use App\Models\Colony;
use App\Models\UplinkMessage;
use App\Services\GameImport\GameDataTables;
use App\Services\Uplink\ColonyIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores the state and journals the message', function (): void {
    $message = app(ColonyIngestor::class)->ingest(uplinkFixture());

    $colony = Colony::query()->sole();
    expect($message->outcome)->toBe('stored')
        ->and($message->size)->toBe(mb_strlen(uplinkFixture(), '8bit'))
        ->and($colony->guid)->toBe('guid-deepwater')
        ->and($colony->cycle)->toBe(59)
        ->and($colony->state['duplicants'])->toHaveCount(2)
        ->and($colony->last_seen_at)->not->toBeNull();
});

it('measures the message size in bytes', function (): void {
    $raw = uplinkFixture(['colony' => ['name' => 'Écluse Profonde']]);

    expect(app(ColonyIngestor::class)->ingest($raw)->size)->toBe(mb_strlen($raw, '8bit'))
        ->and(mb_strlen($raw, '8bit'))->toBeGreaterThan(mb_strlen($raw));
});

it('keeps one row per colony', function (): void {
    app(ColonyIngestor::class)->ingest(uplinkFixture());
    app(ColonyIngestor::class)->ingest(uplinkFixture(['colony' => ['cycle' => 60]]));
    expect(Colony::query()->count())->toBe(1)->and(Colony::query()->value('cycle'))->toBe(60);
});

it('ignores frames that are not colony states', function (): void {
    expect(app(ColonyIngestor::class)->ingest('{"event":"pusher:ping","data":{}}'))->toBeNull()
        ->and(UplinkMessage::query()->count())->toBe(0);
});

it('refuses a newer schema without touching the stored state', function (): void {
    app(ColonyIngestor::class)->ingest(uplinkFixture());
    $message = app(ColonyIngestor::class)->ingest(uplinkFixture(['schema' => 2, 'colony' => ['cycle' => 99]]));
    expect($message->outcome)->toBe('refused')->and($message->reason)->toBe('schema_newer')
        ->and(Colony::query()->value('cycle'))->toBe(59);
});

it('refuses unreadable messages with a reason', function (string $raw, string $reason): void {
    expect(app(ColonyIngestor::class)->ingest($raw)->reason)->toBe($reason);
})->with([
    'broken json' => ['{"event":"client-colony.state","channel":"uplink","data":{', 'invalid_json'],
    'no schema' => ['{"event":"client-colony.state","channel":"uplink","data":{"colony":{"id":"x"}}}', 'schema_missing'],
    'no colony id' => ['{"event":"client-colony.state","channel":"uplink","data":{"schema":1,"colony":{}}}', 'colony_missing'],
]);

it('accepts data sent as a json string', function (): void {
    $envelope = json_decode(uplinkFixture(), true);
    $envelope['data'] = json_encode($envelope['data']);
    expect(app(ColonyIngestor::class)->ingest(json_encode($envelope))->outcome)->toBe('stored');
});

it('records section errors', function (): void {
    $message = app(ColonyIngestor::class)->ingest(uplinkFixture(['plants' => ['error' => 'boom']]));
    expect($message->outcome)->toBe('stored')->and($message->section_errors)->toBe(['plants']);
});

it('keeps only the last 50 messages', function (): void {
    foreach (range(1, 53) as $i) {
        app(ColonyIngestor::class)->ingest(uplinkFixture(['colony' => ['cycle' => $i]]));
    }
    expect(UplinkMessage::query()->count())->toBe(50)->and(UplinkMessage::query()->min('cycle'))->toBe(4);
});

it('is not truncated by the game import', function (): void {
    expect(GameDataTables::all())->not->toContain('colonies')->not->toContain('uplink_messages');
});

it('refuses a colony state sent on another channel', function (): void {
    $envelope = json_decode(uplinkFixture(), true);
    $envelope['channel'] = 'elsewhere';

    $message = app(ColonyIngestor::class)->ingest(json_encode($envelope));

    expect($message->outcome)->toBe('refused')
        ->and($message->reason)->toBe('wrong_channel')
        ->and(Colony::query()->count())->toBe(0);
});

it('keeps only the 10 most recently seen colonies', function (): void {
    foreach (range(1, 12) as $i) {
        $this->travel(1)->minutes();
        app(ColonyIngestor::class)->ingest(uplinkFixture(['colony' => ['id' => "guid-{$i}"]]));
    }

    expect(Colony::query()->count())->toBe(10)
        ->and(Colony::query()->pluck('guid')->all())->not->toContain('guid-1')->not->toContain('guid-2')->toContain('guid-12');
});
