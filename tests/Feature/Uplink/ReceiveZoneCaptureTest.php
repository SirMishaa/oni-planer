<?php

declare(strict_types=1);

use App\Events\ZoneUpdated;
use App\Models\UplinkCommand;
use App\Models\Zone;
use App\Models\ZoneCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->modPath = sys_get_temp_dir().'/uplink-mod-'.uniqid();
    config(['game.uplink_mod_path' => $this->modPath]);
    Storage::fake('public');
    Event::fake([ZoneUpdated::class]);
    $this->zone = Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a']);
});

afterEach(function (): void {
    File::deleteDirectory($this->modPath);
});

/** @return array<string, mixed> */
function captureMessage(array $override = []): array
{
    return [
        'schema' => 1, 'id' => 'msg-capture-1', 'colonyId' => 'colony-1', 'sessionId' => 'session-1',
        'zoneId' => 'zone-a', 'gameSeconds' => 247_500.0, 'signature' => 'sig-1', 'trigger' => 'auto',
        'commandId' => null, 'fileName' => '20261114-153000-000.png', 'width' => 704, 'height' => 128,
        ...$override,
    ];
}

function writeModCapture(string $modPath, string $zoneId, string $fileName): void
{
    File::ensureDirectoryExists("{$modPath}/captures/{$zoneId}");
    $image = imagecreatetruecolor(16, 8);
    imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));
    imagepng($image, "{$modPath}/captures/{$zoneId}/{$fileName}");
}

it('converts the capture to WebP, records it and broadcasts it', function (): void {
    writeModCapture($this->modPath, 'zone-a', '20261114-153000-000.png');

    $acks = receiveUplinkData(uplinkData('client-zone.capture', captureMessage()));

    $capture = ZoneCapture::query()->sole();
    expect($acks)->toHaveCount(1)
        ->and($capture->zone_id)->toBe($this->zone->id)
        ->and($capture->path)->toBe('zone-captures/zone-a/20261114-153000-000.webp')
        ->and($capture->trigger)->toBe('auto')
        ->and($capture->layout_signature)->toBe('sig-1')
        ->and([$capture->width, $capture->height])->toBe([704, 128])
        ->and($capture->cycle())->toBe(412)
        ->and(getimagesize(Storage::disk('public')->path($capture->path))['mime'] ?? null)->toBe('image/webp');
    Event::assertDispatched(ZoneUpdated::class, fn (ZoneUpdated $event): bool => $event->kind === 'capture' && $event->zoneGuid === 'zone-a');
});

it('keeps the 15 most recent captures of a zone and deletes the oldest file', function (): void {
    foreach (range(1, 15) as $index) {
        $path = "zone-captures/zone-a/old-{$index}.png";
        Storage::disk('public')->put($path, 'old');
        ZoneCapture::factory()->for($this->zone)->create(['path' => $path, 'taken_at' => now()->subMinutes(100 - $index)]);
    }
    writeModCapture($this->modPath, 'zone-a', '20261114-153000-000.png');

    receiveUplinkData(uplinkData('client-zone.capture', captureMessage()));

    expect(ZoneCapture::query()->count())->toBe(15)
        ->and(Storage::disk('public')->exists('zone-captures/zone-a/old-1.png'))->toBeFalse()
        ->and(Storage::disk('public')->exists('zone-captures/zone-a/old-2.png'))->toBeTrue()
        ->and(ZoneCapture::query()->where('path', 'zone-captures/zone-a/old-1.png')->exists())->toBeFalse();
});

it('acks and fails the command when the capture file is missing', function (): void {
    $command = UplinkCommand::factory()->create(['colony_guid' => 'colony-1', 'type' => 'zone.capture', 'status' => UplinkCommand::DONE]);

    $acks = receiveUplinkData(uplinkData('client-zone.capture', captureMessage(['trigger' => 'manual', 'commandId' => $command->id])));

    expect($acks)->toHaveCount(1)
        ->and(ZoneCapture::query()->count())->toBe(0)
        ->and($command->fresh()->status)->toBe(UplinkCommand::REFUSED)
        ->and($command->fresh()->reason)->toBe('capture file missing');
});

it('records the failure of a manual capture on its command', function (): void {
    $command = UplinkCommand::factory()->create(['colony_guid' => 'colony-1', 'type' => 'zone.capture', 'status' => UplinkCommand::DONE]);
    $failed = captureMessage(['trigger' => 'manual', 'commandId' => $command->id, 'failure' => 'NullReferenceException while rendering the capture']);
    unset($failed['fileName'], $failed['width'], $failed['height']);

    $acks = receiveUplinkData(uplinkData('client-zone.capture', $failed));

    expect($acks)->toHaveCount(1)
        ->and(ZoneCapture::query()->count())->toBe(0)
        ->and($command->fresh()->status)->toBe(UplinkCommand::REFUSED)
        ->and($command->fresh()->reason)->toBe('NullReferenceException while rendering the capture');
    Event::assertDispatched(ZoneUpdated::class, fn (ZoneUpdated $event): bool => $event->kind === 'capture');
});

it('drops a capture of an unknown zone', function (): void {
    $acks = receiveUplinkData(uplinkData('client-zone.capture', captureMessage(['zoneId' => 'zone-gone'])));

    expect($acks)->toHaveCount(1)
        ->and(ZoneCapture::query()->count())->toBe(0);
});

it('refuses a file name that leaves the captures folder', function (): void {
    writeModCapture($this->modPath, 'zone-a', '20261114-153000-000.png');

    $acks = receiveUplinkData(uplinkData('client-zone.capture', captureMessage(['fileName' => '../../uplink.json'])));

    expect($acks)->toHaveCount(1)
        ->and(ZoneCapture::query()->count())->toBe(0);
});

it('stores a capture delivered twice only once', function (): void {
    writeModCapture($this->modPath, 'zone-a', '20261114-153000-000.png');
    $frame = uplinkData('client-zone.capture', captureMessage());

    receiveUplinkData($frame);
    writeModCapture($this->modPath, 'zone-a', '20261114-153000-000.png');
    $acks = receiveUplinkData($frame);

    expect($acks)->toHaveCount(1)
        ->and(ZoneCapture::query()->count())->toBe(1);
});

it('keeps a file another capture row still uses when pruning', function (): void {
    Storage::disk('public')->put('zone-captures/zone-a/shared.png', 'shared');
    ZoneCapture::factory()->for($this->zone)->create(['path' => 'zone-captures/zone-a/shared.png', 'taken_at' => now()->subDays(2)]);
    ZoneCapture::factory()->for($this->zone)->create(['path' => 'zone-captures/zone-a/shared.png', 'taken_at' => now()->subDay()]);
    foreach (range(1, 13) as $index) {
        ZoneCapture::factory()->for($this->zone)->create(['taken_at' => now()->subMinutes(100 - $index)]);
    }
    writeModCapture($this->modPath, 'zone-a', '20261114-153000-000.png');

    receiveUplinkData(uplinkData('client-zone.capture', captureMessage()));

    expect(ZoneCapture::query()->count())->toBe(15)
        ->and(Storage::disk('public')->exists('zone-captures/zone-a/shared.png'))->toBeTrue();
});

it('removes the copy in the mod folder once stored', function (): void {
    writeModCapture($this->modPath, 'zone-a', '20261114-153000-000.png');

    receiveUplinkData(uplinkData('client-zone.capture', captureMessage()));

    expect("{$this->modPath}/captures/zone-a/20261114-153000-000.png")->not->toBeFile();
});

it('does not fail a manual capture delivered again after its file was moved', function (): void {
    $command = UplinkCommand::factory()->create(['colony_guid' => 'colony-1', 'type' => 'zone.capture', 'status' => UplinkCommand::DONE]);
    writeModCapture($this->modPath, 'zone-a', '20261114-153000-000.png');
    $frame = uplinkData('client-zone.capture', captureMessage(['trigger' => 'manual', 'commandId' => $command->id]));

    receiveUplinkData($frame);
    receiveUplinkData($frame);

    expect(ZoneCapture::query()->count())->toBe(1)
        ->and($command->fresh()->status)->toBe(UplinkCommand::DONE);
});

it('fails the command when the capture file is not a readable PNG', function (): void {
    $command = UplinkCommand::factory()->create(['colony_guid' => 'colony-1', 'type' => 'zone.capture', 'status' => UplinkCommand::DONE]);
    File::ensureDirectoryExists("{$this->modPath}/captures/zone-a");
    File::put("{$this->modPath}/captures/zone-a/20261114-153000-000.png", 'not a png');

    $acks = receiveUplinkData(uplinkData('client-zone.capture', captureMessage(['trigger' => 'manual', 'commandId' => $command->id])));

    expect($acks)->toHaveCount(1)
        ->and(ZoneCapture::query()->count())->toBe(0)
        ->and($command->fresh()->reason)->toBe('capture file unreadable');
});
