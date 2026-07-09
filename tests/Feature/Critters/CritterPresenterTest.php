<?php

declare(strict_types=1);

use App\Models\Critter;
use App\Models\CritterMorph;
use App\Services\Critters\CritterPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Use an empty public path so asset file checks return null (no webp files in test env).
beforeEach(function (): void {
    app()->usePublicPath(sys_get_temp_dir().'/ony-planner-test-public');
});

it('presents a family with resolved diet element refs and egg outputs', function (): void {
    makeHatch();

    $family = app(CritterPresenter::class)->family('hatch');

    expect($family)->not->toBeNull()
        ->and($family->slug)->toBe('hatch')
        ->and($family->variants)->toHaveCount(1);

    $adult = $family->variants[0]->adult;
    expect($adult->diets)->toHaveCount(1)
        ->and($adult->diets[0]->consumed->name)->toBe('Sand')
        ->and($adult->diets[0]->produced->name)->toBe('Coal')
        ->and($adult->diets[0]->consumed->icon)->toBeNull() // no webp file in test env
        ->and($adult->outputs[0]->kind)->toBe('egg')
        ->and($adult->outputs[0]->url)->toBe('/critters/hatch');

    expect($family->variants[0]->baby)->not->toBeNull();
});

it('returns null for an unknown family slug', function (): void {
    expect(app(CritterPresenter::class)->family('nope'))->toBeNull();
});

it('builds a ranch catalog with adult-only variants and an elements map', function (): void {
    makeHatch();

    $catalog = app(CritterPresenter::class)->catalog();

    expect($catalog->families)->toHaveCount(1)
        ->and($catalog->families[0]->variants[0]->baby)->toBeNull()
        ->and($catalog->elements)->toHaveKey('Carbon')
        ->and($catalog->elements['Carbon']->name)->toBe('Coal');
});

it('lists families with variant counts', function (): void {
    makeHatch();

    $list = app(CritterPresenter::class)->familyList();

    expect($list)->toHaveCount(1)
        ->and($list[0]->slug)->toBe('hatch')
        ->and($list[0]->variant_count)->toBe(1);
});

it('uses the resolved base-morph name for the family, not the raw variant_family', function (): void {
    $critter = Critter::factory()->create(['critter_id' => 'OilFloater', 'variant_family' => 'OilFloater']);
    CritterMorph::factory()->create([
        'critter_id' => $critter->id,
        'morph_id' => 'OilFloater',
        'is_base' => true,
        'name' => ['en' => 'Oil Floater'],
    ]);

    $list = app(CritterPresenter::class)->familyList();

    expect($list)->toHaveCount(1)
        ->and($list[0]->name)->toBe('Oil Floater');
});

it('uses the base variant name even when it does not sort first alphabetically', function (): void {
    $variant = Critter::factory()->create(['critter_id' => 'AaaBammoth', 'variant_family' => 'Bammoth']);
    CritterMorph::factory()->create([
        'critter_id' => $variant->id,
        'morph_id' => 'AaaBammoth',
        'is_base' => true,
        'name' => ['en' => 'Regal Bammoth'],
    ]);

    $base = Critter::factory()->create(['critter_id' => 'Bammoth', 'variant_family' => 'Bammoth']);
    CritterMorph::factory()->create([
        'critter_id' => $base->id,
        'morph_id' => 'Bammoth',
        'is_base' => true,
        'name' => ['en' => 'Bammoth'],
    ]);

    $list = app(CritterPresenter::class)->familyList();

    expect($list)->toHaveCount(1)
        ->and($list[0]->name)->toBe('Bammoth');
});

it('falls back to variant_family when no morph is present', function (): void {
    Critter::factory()->create(['critter_id' => 'Ghost', 'variant_family' => 'Ghost']);

    $list = app(CritterPresenter::class)->familyList();

    expect($list)->toHaveCount(1)
        ->and($list[0]->name)->toBe('Ghost');
});

it('falls back to critter_id when there is no variant_family and no morph', function (): void {
    Critter::factory()->create(['critter_id' => 'Solo', 'variant_family' => null]);

    $list = app(CritterPresenter::class)->familyList();

    expect($list)->toHaveCount(1)
        ->and($list[0]->name)->toBe('Solo');
});

it('resolves the variant tab name from the base morph, not the raw critter name', function (): void {
    $critter = Critter::factory()->create(['critter_id' => 'HatchHard', 'variant_family' => 'Hatch', 'name' => ['en' => 'HatchHard']]);
    CritterMorph::factory()->create([
        'critter_id' => $critter->id,
        'morph_id' => 'HatchHard',
        'is_base' => true,
        'name' => ['en' => 'Hatch Hard'],
    ]);

    $family = app(CritterPresenter::class)->family('hatch');

    expect($family)->not->toBeNull()
        ->and($family->variants[0]->name)->toBe(['en' => 'Hatch Hard']);
});

it('falls back to critter_id for the variant name when the base morph name is unresolved', function (): void {
    $critter = Critter::factory()->create(['critter_id' => 'Mystery', 'variant_family' => 'Mystery']);
    CritterMorph::factory()->create([
        'critter_id' => $critter->id,
        'morph_id' => 'Mystery',
        'is_base' => true,
        'name' => [],
    ]);

    $family = app(CritterPresenter::class)->family('mystery');

    expect($family)->not->toBeNull()
        ->and($family->variants[0]->name)->toBe(['en' => 'Mystery']);
});

it('includes dlc_id, temperature range, and other-variant image previews in the family list', function (): void {
    $tempPublic = sys_get_temp_dir().'/ony-planner-test-public';
    @mkdir($tempPublic.'/assets/game/critters', 0777, true);
    file_put_contents($tempPublic.'/assets/game/critters/PufferFish.webp', 'webp');

    $base = Critter::factory()->create(['critter_id' => 'PacuTropical', 'variant_family' => 'Pacu', 'dlc_id' => 'DLC5']);
    CritterMorph::factory()->create(['critter_id' => $base->id, 'morph_id' => 'PacuTropical', 'is_base' => true, 'min_temp' => 290.0, 'max_temp' => 305.0]);

    $other = Critter::factory()->create(['critter_id' => 'PufferFish', 'variant_family' => 'Pacu', 'dlc_id' => 'DLC5']);
    CritterMorph::factory()->create(['critter_id' => $other->id, 'morph_id' => 'PufferFish', 'is_base' => true, 'min_temp' => 275.0, 'max_temp' => 300.0]);

    $list = app(CritterPresenter::class)->familyList();

    expect($list)->toHaveCount(1);
    $family = $list[0];
    expect($family->dlc_id)->toBe('DLC5')
        ->and($family->min_temp)->toBe(275.0)
        ->and($family->max_temp)->toBe(305.0)
        ->and($family->variant_images)->toBe(['/assets/game/critters/PufferFish.webp']);

    @unlink($tempPublic.'/assets/game/critters/PufferFish.webp');
});

it('excludes the primary variant image and caps the preview stack at 2', function (): void {
    $tempPublic = sys_get_temp_dir().'/ony-planner-test-public';
    @mkdir($tempPublic.'/assets/game/critters', 0777, true);
    foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $id) {
        file_put_contents($tempPublic.'/assets/game/critters/'.$id.'.webp', 'webp');
    }

    foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $id) {
        $critter = Critter::factory()->create(['critter_id' => $id, 'variant_family' => 'MultiVariant']);
        CritterMorph::factory()->create(['critter_id' => $critter->id, 'morph_id' => $id, 'is_base' => true]);
    }

    $list = app(CritterPresenter::class)->familyList();

    expect($list)->toHaveCount(1);
    $family = $list[0];
    expect($family->variant_images)->toHaveCount(2)
        ->and($family->variant_images)->not->toContain('/assets/game/critters/Alpha.webp')
        ->and($family->variant_images)->toBe(['/assets/game/critters/Beta.webp', '/assets/game/critters/Delta.webp']);

    foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $id) {
        @unlink($tempPublic.'/assets/game/critters/'.$id.'.webp');
    }
});
