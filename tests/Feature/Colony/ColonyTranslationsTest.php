<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('has the same colony keys in french and english', function (): void {
    $french = Arr::dot(trans('colony', [], 'fr'));
    $english = Arr::dot(trans('colony', [], 'en'));

    expect(array_keys($french))->toEqualCanonicalizing(array_keys($english))
        ->and(array_filter($french, fn (mixed $value): bool => $value === '' || $value === []))->toBeEmpty()
        ->and(array_filter($english, fn (mixed $value): bool => $value === '' || $value === []))->toBeEmpty();
});

it('shares the colony translations and the locale with the pages', function (): void {
    app()->setLocale('fr');

    $this->get('/colony/viability')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('locale', 'fr')
            ->where('translations.colony.nav.viability', trans('colony.nav.viability', [], 'fr')));
});
