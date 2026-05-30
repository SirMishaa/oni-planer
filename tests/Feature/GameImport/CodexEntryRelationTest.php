<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\CodexEntry;

it('links a codex entry to a building both ways', function (): void {
    $building = Building::factory()->create(['building_id' => 'Electrolyzer']);
    $entry = CodexEntry::factory()->create(['codex_id' => 'Electrolyzer', 'category' => 'Buildings']);

    $building->codexEntries()->attach($entry->id);

    expect($building->fresh()->codexEntries)->toHaveCount(1)
        ->and($building->fresh()->codexEntries->first()->codex_id)->toBe('Electrolyzer')
        ->and($entry->fresh()->buildings)->toHaveCount(1)
        ->and($entry->fresh()->buildings->first()->building_id)->toBe('Electrolyzer');
});
