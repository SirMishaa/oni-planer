<?php

declare(strict_types=1);

use App\Models\Zone;
use App\Models\ZoneCapture;

it('belongs to its zone, newest first, and gives the game cycle', function (): void {
    $zone = Zone::factory()->create();
    ZoneCapture::factory()->for($zone)->create(['taken_at' => now()->subHour(), 'game_seconds' => 1_000.0]);
    $latest = ZoneCapture::factory()->for($zone)->create(['taken_at' => now(), 'game_seconds' => 247_500.0]);

    expect($zone->captures()->first()?->is($latest))->toBeTrue()
        ->and($latest->cycle())->toBe(412)
        ->and($latest->zone->is($zone))->toBeTrue()
        ->and(ZoneCapture::KEEP)->toBe(15);
});
