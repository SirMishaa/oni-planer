<?php

declare(strict_types=1);

use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\Element;
use App\Services\GameImport\CritterImporter;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports a critter morph with diets and outputs', function (): void {
    foreach (['Sand', 'Carbon', 'EggShell'] as $eid) {
        Element::create([
            'element_id' => $eid, 'state' => 'solid', 'molar_mass' => 1.0,
            'toxicity' => 0, 'material_category' => 'Mineral', 'tags' => [],
            'name' => ['en' => $eid], 'is_disabled' => false,
        ]);
    }

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    (new CritterImporter($resolver))->import(base_path('tests/Fixtures/GameImport/critters.json'));

    expect(Critter::count())->toBe(1)
        ->and(CritterMorph::count())->toBe(1)
        ->and(CritterMorphDiet::count())->toBe(1);

    $morph = CritterMorph::first();
    expect($morph->morph_id)->toBe('HatchHardSkin')
        ->and($morph->calories_per_cycle)->toBe(700.0)
        ->and($morph->diets->first()->consumed_element_id)->toBe('Sand');
});
