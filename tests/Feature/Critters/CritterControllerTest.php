<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    makeHatch();
});

it('renders the critter index with families', function (): void {
    $this->get('/critters')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Critters/Index')
            ->has('families', 1)
            ->where('families.0.slug', 'hatch')
            ->where('families.0.variant_count', 1));
});

it('renders a family page', function (): void {
    $this->get('/critters/hatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Critters/Show')
            ->where('family.slug', 'hatch')
            ->has('family.variants', 1)
            ->where('family.variants.0.adult.diets.0.consumed.name', 'Sand'));
});

it('404s on unknown family', function (): void {
    $this->get('/critters/nope')->assertNotFound();
});
