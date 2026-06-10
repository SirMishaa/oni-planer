<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the ranch planner with a catalog and elements map', function (): void {
    makeHatch();

    $this->get('/ranch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Ranch/Index')
            ->has('catalog.families', 1)
            ->where('catalog.families.0.variants.0.baby', null)
            ->where('catalog.elements.Carbon.name', 'Coal'));
});
