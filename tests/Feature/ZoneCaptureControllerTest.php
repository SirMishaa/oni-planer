<?php

declare(strict_types=1);

use App\Models\UplinkCommand;
use App\Models\Zone;

it('asks the mod for a capture of the zone', function (): void {
    $zone = Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a']);

    $this->from(route('zones.show', $zone))
        ->post(route('zones.captures.store', $zone))
        ->assertRedirect(route('zones.show', $zone));

    $command = UplinkCommand::query()->sole();
    expect($command->type)->toBe('zone.capture')
        ->and($command->colony_guid)->toBe('colony-1')
        ->and($command->payload)->toBe(['zoneId' => 'zone-a']);
});

it('does not capture an archived zone', function (): void {
    $zone = Zone::factory()->create(['archived_at' => now()]);

    $this->post(route('zones.captures.store', $zone))->assertNotFound();

    expect(UplinkCommand::query()->count())->toBe(0);
});
