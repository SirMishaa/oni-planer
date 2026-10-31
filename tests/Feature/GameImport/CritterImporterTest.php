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
        Element::query()->create([
            'element_id' => $eid, 'state' => 'solid', 'molar_mass' => 1.0,
            'toxicity' => 0, 'material_category' => 'Mineral', 'tags' => [],
            'name' => ['en' => $eid], 'is_disabled' => false,
        ]);
    }

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new CritterImporter($resolver)->import(base_path('tests/Fixtures/GameImport/critters.json'));

    expect(Critter::query()->count())->toBe(1)
        ->and(CritterMorph::query()->count())->toBe(1)
        ->and(CritterMorphDiet::query()->count())->toBe(1);

    $morph = CritterMorph::query()->first();
    expect($morph->morph_id)->toBe('HatchHardSkin')
        ->and($morph->calories_per_cycle)->toBe(700.0)
        ->and($morph->diets->first()->consumed_element_id)->toBe('Sand');
});

it('imports diets, outputs and variant_family', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'crit').'.json';
    file_put_contents($path, json_encode([[
        'critter_id' => 'Hatch', 'is_base' => true,
        'min_temp' => 283.15, 'max_temp' => 313.15,
        'calories_per_cycle' => 700000, 'incubation_time' => 20, 'lifespan' => 100,
        'overcrowding_threshold' => 8, 'name_localization_id' => null,
        'variant_family' => 'Hatch',
        'diets' => [['consumed_element_id' => 'Carbon', 'amount_per_cycle' => 140, 'produced_element_id' => 'Carbon', 'conversion_ratio' => 1.0]],
        'outputs' => [['element_id' => 'Carbon', 'amount_per_cycle' => 0.0166, 'output_type' => 'egg']],
    ]]));

    Element::factory()->create(['element_id' => 'Carbon']);

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new CritterImporter($resolver)->import($path);

    $critter = Critter::query()->where('critter_id', 'Hatch')->firstOrFail();
    expect($critter->variant_family)->toBe('Hatch');
    $morph = $critter->baseMorph;
    expect($morph->diets)->toHaveCount(1);
    expect($morph->outputs->first()->output_type)->toBe('egg');
});

it('imports dlc_id', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'crit').'.json';
    file_put_contents($path, json_encode([[
        'critter_id' => 'AlgaeStego', 'is_base' => true,
        'min_temp' => 283.15, 'max_temp' => 313.15,
        'calories_per_cycle' => 700000, 'incubation_time' => 20, 'lifespan' => 100,
        'overcrowding_threshold' => 8, 'name_localization_id' => null,
        'variant_family' => 'AlgaeStego', 'dlc_id' => 'DLC5',
        'diets' => [], 'outputs' => [],
    ]]));

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new CritterImporter($resolver)->import($path);

    $critter = Critter::query()->where('critter_id', 'AlgaeStego')->firstOrFail();
    expect($critter->dlc_id)->toBe('DLC5');
});

it('backfills dlc_id when a later entry for the same parent supplies one', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'crit').'.json';
    file_put_contents($path, json_encode([
        [
            'critter_id' => 'Hatch', 'is_base' => true,
            'min_temp' => 283.15, 'max_temp' => 313.15,
            'calories_per_cycle' => 700000, 'incubation_time' => 20, 'lifespan' => 100,
            'overcrowding_threshold' => 8, 'name_localization_id' => null,
            'variant_family' => 'Hatch', 'dlc_id' => null,
            'diets' => [], 'outputs' => [],
        ],
        [
            'critter_id' => 'HatchBaby', 'parent_critter_id' => 'Hatch', 'is_base' => false,
            'min_temp' => 283.15, 'max_temp' => 313.15,
            'calories_per_cycle' => 0, 'incubation_time' => 0, 'lifespan' => 0,
            'overcrowding_threshold' => 0, 'name_localization_id' => null,
            'variant_family' => 'Hatch', 'dlc_id' => 'DLC5',
            'diets' => [], 'outputs' => [],
        ],
    ]));

    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));
    new CritterImporter($resolver)->import($path);

    $critter = Critter::query()->where('critter_id', 'Hatch')->firstOrFail();
    expect($critter->dlc_id)->toBe('DLC5');
});

it('stores the species tag on the critter', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'crit').'.json';
    file_put_contents($path, json_encode([[
        'critter_id' => 'Hatch', 'is_base' => true, 'min_temp' => 283.15, 'max_temp' => 313.15,
        'calories_per_cycle' => 700000, 'incubation_time' => 20, 'lifespan' => 100,
        'overcrowding_threshold' => 8, 'name_localization_id' => null, 'variant_family' => 'Hatch',
        'species_tag' => 'HatchSpecies', 'diets' => [], 'outputs' => [],
    ]]));
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));

    new CritterImporter($resolver)->import($path);

    expect(Critter::query()->where('critter_id', 'Hatch')->value('species_tag'))->toBe('HatchSpecies');
});
