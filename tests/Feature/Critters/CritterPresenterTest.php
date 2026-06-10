<?php

declare(strict_types=1);

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
