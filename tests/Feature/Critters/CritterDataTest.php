<?php

declare(strict_types=1);

use App\Data\CritterFamilyData;
use App\Data\ElementRefData;

it('builds element ref and family data from arrays', function (): void {
    $ref = ElementRefData::from([
        'element_id' => 'Carbon', 'name' => 'Coal', 'icon' => '/assets/game/elements/Carbon.webp', 'url' => '/codex/CARBON',
    ]);
    expect($ref->name)->toBe('Coal');

    $family = CritterFamilyData::from([
        'slug' => 'hatch', 'name' => 'Hatch', 'description' => null,
        'image' => '/assets/game/critters/Hatch.webp', 'variants' => [],
    ]);
    expect($family->slug)->toBe('hatch')->and($family->variants)->toBe([]);
});
