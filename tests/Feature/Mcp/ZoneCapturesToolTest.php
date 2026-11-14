<?php

declare(strict_types=1);

use App\Mcp\Servers\OniServer;
use App\Mcp\Tools\ZoneCapturesTool;
use App\Models\Colony;
use App\Models\Zone;
use App\Models\ZoneCapture;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
    $colony = Colony::factory()->create();
    $this->zone = Zone::factory()->sodicaneFarm()->create(['colony_guid' => $colony->guid, 'name' => 'Sodicane']);
    $this->zone->update(['layout' => [...$this->zone->layout, 'signature' => 'current']]);
});

it('lists the captures newest first with their cycle and layout change', function (): void {
    $older = ZoneCapture::factory()->create(['zone_id' => $this->zone->id, 'taken_at' => now()->subHour(), 'game_seconds' => 30_000.0, 'layout_signature' => 'old']);
    $latest = ZoneCapture::factory()->create(['zone_id' => $this->zone->id, 'taken_at' => now(), 'game_seconds' => 35_400.0, 'layout_signature' => 'current']);

    OniServer::tool(ZoneCapturesTool::class, ['zone' => 'Sodicane'])
        ->assertOk()
        ->assertSee('#'.$latest->id.' ')
        ->assertSee('cycle 59')
        ->assertSee('layout changed since: no')
        ->assertSee('#'.$older->id.' ')
        ->assertSee('layout changed since: yes')
        ->assertSee(route('zones.show', $this->zone));
});

it('returns a capture as a WebP image', function (): void {
    $capture = ZoneCapture::factory()->create(['zone_id' => $this->zone->id, 'path' => 'zone-captures/z/1.webp']);
    Storage::disk('public')->put('zone-captures/z/1.webp', 'RIFF....WEBPVP8 capture');

    OniServer::tool(ZoneCapturesTool::class, ['zone' => 'Sodicane', 'capture' => 'latest'])
        ->assertOk()
        ->assertSee('#'.$capture->id)
        ->assertSee(base64_encode('RIFF....WEBPVP8 capture'));
});

it('picks the previous capture', function (): void {
    $previous = ZoneCapture::factory()->create(['zone_id' => $this->zone->id, 'taken_at' => now()->subHour(), 'path' => 'zone-captures/z/0.webp']);
    ZoneCapture::factory()->create(['zone_id' => $this->zone->id, 'taken_at' => now(), 'path' => 'zone-captures/z/1.webp']);
    Storage::disk('public')->put('zone-captures/z/0.webp', 'previous');

    OniServer::tool(ZoneCapturesTool::class, ['zone' => 'Sodicane', 'capture' => 'previous'])
        ->assertOk()
        ->assertSee('#'.$previous->id)
        ->assertSee(base64_encode('previous'));
});

it('names a capture whose file is missing', function (): void {
    $capture = ZoneCapture::factory()->create(['zone_id' => $this->zone->id, 'path' => 'zone-captures/z/gone.webp']);

    OniServer::tool(ZoneCapturesTool::class, ['zone' => 'Sodicane', 'capture' => (string) $capture->id])
        ->assertHasErrors(['#'.$capture->id, 'file missing']);
});
